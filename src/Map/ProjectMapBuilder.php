<?php

declare(strict_types=1);

namespace voku\AgentLoop\Map;

use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\Build\PhpStanSemanticAnalyzer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\IO\SourceLanguage;
use voku\AgentMap\MapArtifactPaths;

/**
 * Loop's map preparation for a project that declared more than PHP in `map.languages`.
 *
 * agent-map owns what a language is, which files belong to it, how they are parsed and when a
 * map is stale. Its incremental `prepare`/`refresh` cover PHP-only maps and refuse every other
 * one, so wherever Loop would refresh such a map it asks the owner for a full build instead,
 * through `AgentMapBuilder::forLanguages()`. Nothing here parses source or decides currentness.
 */
final readonly class ProjectMapBuilder
{
    /** @param list<string> $languages agent-map `SourceLanguage` names */
    public function __construct(private string $rootPath, private array $languages)
    {
    }

    public static function forProject(string $rootPath): self
    {
        return new self($rootPath, (new ProjectLayout($rootPath))->mapLanguages());
    }

    /** @return list<string> */
    public function languages(): array
    {
        return $this->languages;
    }

    /** Whether the declared languages need the owner's full build instead of its PHP-only refresh. */
    public function indexesBeyondPhp(): bool
    {
        return $this->languages !== [SourceLanguage::PHP];
    }

    /** The `--languages` value that reproduces the declaration on the owner's command line. */
    public function languagesOption(): string
    {
        return implode(',', $this->languages);
    }

    /**
     * Whether an existing Contract scope file needs a current map before governed preparation.
     *
     * PHP keeps Loop's long-standing rule (`.php`, case-insensitively), so a PHP-only project is
     * judged exactly as before. Every other declared language is judged by the owner's own
     * file-to-language mapping, which also keeps TypeScript declaration files out.
     */
    public function requiresMap(string $relativePath): bool
    {
        if (in_array(SourceLanguage::PHP, $this->languages, true) && str_ends_with(strtolower($relativePath), '.php')) {
            return true;
        }

        $language = SourceLanguage::ofPath($relativePath);

        return $language !== null
            && $language !== SourceLanguage::PHP
            && in_array($language, $this->languages, true);
    }

    /**
     * Fully rebuild the map so that it covers `$scope` in the declared languages.
     *
     * A missing map is built from the scope alone, structurally - the same bound the PHP path
     * uses. An existing map is rebuilt from the scope it recorded plus `$scope`, with the
     * semantic backend it already carries, because a full build replaces the artifact and must
     * not shrink what the project built on purpose. The index is written only after the build
     * succeeded, so a failure leaves the existing artifact untouched.
     *
     * @param list<string> $scope project-relative paths that must be indexed
     */
    public function rebuild(MapArtifactPaths $artifacts, array $scope): AgentMapIndex
    {
        $indexPath = $artifacts->indexJson();
        try {
            $existing = $this->existingMap($indexPath);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Cannot prepare ' . $indexPath . ': the existing map is unreadable. ' . $exception->getMessage()
                . ' Existing map was left untouched. Recovery: ' . $this->buildCommand($scope),
                0,
                $exception,
            );
        }

        $paths = $this->rebuildPaths($existing, $scope);
        $recorded = $existing?->fingerprint?->semanticScope;
        // PHPStan is the semantic backend of PHP files only (the owner's own CLI rule); a missing
        // map is built structurally, like the PHP path does.
        $structural = $existing === null
            || !str_ends_with($existing->backend, '+phpstan')
            || !in_array(SourceLanguage::PHP, $this->languages, true);
        if (!$structural && !PhpStanSemanticAnalyzer::isAvailable()) {
            throw new RuntimeException(
                'Cannot prepare ' . $indexPath . ': the recorded PHPStan backend is unavailable.'
                . ' Existing map was left untouched. Recovery: ' . $this->buildCommand($paths),
            );
        }

        try {
            $index = AgentMapBuilder::forLanguages(
                $this->languages,
                $structural ? new StructuralOnlySemanticAnalyzer() : new PhpStanSemanticAnalyzer($artifacts),
                $artifacts,
            )->build(
                $this->rootPath,
                $paths,
                $recorded !== null ? $recorded->excludes : [],
                null,
                null,
                null,
                $structural || $recorded === null ? [] : $recorded->scanDirectories,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException($this->buildFailure($exception->getMessage(), $paths), 0, $exception);
        }

        (new IndexWriter())->write($index, $indexPath, 'json');

        return $index;
    }

    /**
     * The `agent-loop map build` command that covers `$scope` without shrinking an existing map:
     * the same paths `rebuild()` uses. An unreadable map contributes nothing.
     *
     * @param list<string> $scope
     */
    public function rebuildCommand(MapArtifactPaths $artifacts, array $scope): string
    {
        try {
            $existing = $this->existingMap($artifacts->indexJson());
        } catch (Throwable) {
            $existing = null;
        }

        return $this->buildCommand($this->rebuildPaths($existing, $scope));
    }

    private function existingMap(string $indexPath): ?AgentMapIndex
    {
        return is_file($indexPath)
            ? (new IndexReader())->readForRoot($indexPath, $this->rootPath, sections: ['files'])
            : null;
    }

    /**
     * The scope an existing map recorded (or, for a map without one, the files it indexes), plus
     * the paths that must be covered now.
     *
     * @param list<string> $scope
     *
     * @return list<string>
     */
    private function rebuildPaths(?AgentMapIndex $existing, array $scope): array
    {
        if ($existing === null) {
            return $scope;
        }

        $recorded = $existing->fingerprint?->semanticScope;
        $recordedPaths = $recorded !== null
            ? $recorded->paths
            : array_map(static fn ($file): string => $file->path, $existing->files);

        $uncovered = array_filter($scope, static function (string $path) use ($recordedPaths): bool {
            foreach ($recordedPaths as $recordedPath) {
                $directory = rtrim($recordedPath, '/');
                if ($directory === '.' || $path === $directory || str_starts_with($path, $directory . '/')) {
                    return false;
                }
            }

            return true;
        });

        return array_values(array_unique([...$recordedPaths, ...$uncovered]));
    }

    /**
     * agent-map's reason, unchanged, with what Loop knows on top: which declaration asked for
     * the build and how to retry it.
     *
     * @param list<string> $paths
     */
    private function buildFailure(string $reason, array $paths): string
    {
        $message = 'agent-map could not build the map for the declared languages (' . $this->languagesOption()
            . ', from map.languages in .agent-loop/init.json): ' . $reason;
        if (in_array(SourceLanguage::TYPESCRIPT, $this->languages, true)) {
            $message .= ' The `typescript` map language needs Node.js on PATH and the `typescript` npm package'
                . ' (the project\'s node_modules after `npm ci`, or AGENT_MAP_TYPESCRIPT_PATH);'
                . ' install them, or remove `typescript` from map.languages to work without TypeScript map evidence.';
        }

        return $message . ' Existing map was left untouched. Retry: ' . $this->buildCommand($paths);
    }

    /** @param list<string> $paths */
    private function buildCommand(array $paths): string
    {
        return 'agent-loop map build --languages=' . $this->languagesOption()
            . ($paths === [] ? '' : ' --paths=' . implode(',', $paths));
    }
}
