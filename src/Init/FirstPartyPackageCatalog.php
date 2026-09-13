<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use ReflectionClass;
use RuntimeException;
use voku\AgentKanban\Cli\CliApplication as KanbanCli;
use voku\AgentLearning\Cli as LearningCli;
use voku\AgentLearning\PackageResources as LearningResources;
use voku\AgentLoop\PackageResources as LoopResources;
use voku\AgentMap\PackageResources as MapResources;
use voku\AgentRecallCompiler\Cli as RecallCli;
use voku\AgentRecallCompiler\PackageResources as RecallResources;
use voku\AgentSession\PackageResources as SessionResources;

/**
 * Single source of truth for first-party workflow packages, root paths,
 * portable provenance, and scoped asset exports (consumer vs owner_repository).
 */
final readonly class FirstPartyPackageCatalog
{
    /** @var list<string> */
    public const array FIRST_PARTY_OWNERS = [
        'voku/agent-loop',
        'voku/agent-learning',
        'voku/agent-recall-compiler',
        'voku/agent-session',
        'voku/agent-map',
        'voku/agent-kanban',
    ];

    /** @var list<string> */
    private const array MAINTAINER_ONLY_SKILLS = [
        'agent-learning-maintainer',
        'agent-recall-compiler-maintainer',
        'agent-session-maintainer',
    ];

    /** @return list<string> */
    public static function firstPartyOwners(): array
    {
        return self::FIRST_PARTY_OWNERS;
    }

    public static function isFirstPartyOwner(string $owner): bool
    {
        return in_array($owner, self::FIRST_PARTY_OWNERS, true);
    }

    public static function packageRoot(string $packageName): ?string
    {
        return match ($packageName) {
            'voku/agent-loop' => self::normalize(dirname(__DIR__, 2)),
            'voku/agent-learning' => self::resolveClassRoot(LearningResources::class) ?? self::resolveClassRoot(LearningCli::class),
            'voku/agent-recall-compiler' => self::resolveClassRoot(RecallResources::class) ?? self::resolveClassRoot(RecallCli::class),
            'voku/agent-session' => self::resolveClassRoot(SessionResources::class),
            'voku/agent-map' => self::resolveClassRoot(MapResources::class),
            'voku/agent-kanban' => self::resolveClassRoot(KanbanCli::class),
            default => null,
        };
    }

    public static function packageRootForOwner(string $owner): ?string
    {
        return self::packageRoot($owner);
    }

    public static function ownerForPath(string $sourcePath, string $projectRoot): string
    {
        $sourcePath = self::normalize($sourcePath);
        $projectRoot = self::normalize($projectRoot);

        $roots = [];
        foreach (self::FIRST_PARTY_OWNERS as $packageName) {
            $root = self::packageRoot($packageName);
            if ($root !== null) {
                $roots[$packageName] = $root;
            }
        }
        uasort(
            $roots,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        foreach ($roots as $packageName => $root) {
            if (self::inside($sourcePath, $root)) {
                return $packageName;
            }
        }

        if (self::inside($sourcePath, $projectRoot)) {
            return 'project';
        }

        return 'local';
    }

    public static function resolvePersistedPath(string $owner, ?string $reference, ?string $sourcePath): ?string
    {
        if ($reference === null) {
            return $sourcePath === null ? null : self::normalize($sourcePath);
        }
        if (!self::validReference($reference)) {
            return null;
        }

        $root = self::packageRootForOwner($owner);
        if ($root === null) {
            return null;
        }

        $candidate = self::normalize($root . '/' . $reference);

        return self::inside($candidate, $root) ? $candidate : null;
    }

    public static function projectPackageName(string $projectRoot): ?string
    {
        $composerFile = rtrim($projectRoot, '/') . '/composer.json';
        if (!is_file($composerFile)) {
            return null;
        }

        $content = file_get_contents($composerFile);
        if (!is_string($content)) {
            return null;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : null;
    }

    public static function isOwnerRepository(string $projectRoot, string $packageName): bool
    {
        return self::projectPackageName($projectRoot) === $packageName;
    }

    public static function isSkillAllowedForProject(string $skillId, string $skillPath, string $projectRoot): bool
    {
        $owner = self::ownerForPath($skillPath, $projectRoot);
        if (self::isOwnerRepository($projectRoot, $owner)) {
            return true;
        }

        if (in_array($skillId, self::MAINTAINER_ONLY_SKILLS, true)) {
            return false;
        }

        return !self::isMaintainerSkillOfOwner($skillId, $owner);
    }

    private static function isMaintainerSkillOfOwner(string $skillId, string $owner): bool
    {
        return match ($owner) {
            'voku/agent-learning' => array_key_exists($skillId, LearningResources::maintainerSkills()),
            'voku/agent-recall-compiler' => array_key_exists($skillId, RecallResources::maintainerSkills()),
            'voku/agent-session' => array_key_exists($skillId, SessionResources::maintainerSkills()),
            default => false,
        };
    }

    /**
     * @return array<string, array{id: string, path: string, owner: string}> skill-id => metadata
     */
    public static function exportableSkills(string $projectRoot): array
    {
        $skills = [];

        // 1. voku/agent-loop
        $loopRoot = self::packageRoot('voku/agent-loop');
        if ($loopRoot !== null) {
            $loopSkillsDir = $loopRoot . '/' . LoopResources::SKILLS;
            if (is_dir($loopSkillsDir)) {
                foreach ((array) scandir($loopSkillsDir) as $entry) {
                    if (!is_string($entry) || str_starts_with($entry, '.')) {
                        continue;
                    }
                    $skillPath = $loopSkillsDir . '/' . $entry;
                    if (is_file($skillPath . '/SKILL.md')) {
                        self::registerExportableSkill($skills, $entry, $skillPath, 'voku/agent-loop', $projectRoot);
                    }
                }
            }
        }

        // 2. voku/agent-learning
        $isLearningOwner = self::isOwnerRepository($projectRoot, 'voku/agent-learning');
        foreach (LearningResources::consumerSkills() as $id => $path) {
            if (is_file($path . '/SKILL.md')) {
                self::registerExportableSkill($skills, $id, $path, 'voku/agent-learning', $projectRoot);
            }
        }
        if ($isLearningOwner) {
            foreach (LearningResources::maintainerSkills() as $id => $path) {
                if (is_file($path . '/SKILL.md')) {
                    self::registerExportableSkill($skills, $id, $path, 'voku/agent-learning', $projectRoot);
                }
            }
        }

        // 3. voku/agent-recall-compiler
        $isRecallOwner = self::isOwnerRepository($projectRoot, 'voku/agent-recall-compiler');
        foreach (RecallResources::consumerSkills() as $id => $path) {
            if (is_file($path . '/SKILL.md')) {
                self::registerExportableSkill($skills, $id, $path, 'voku/agent-recall-compiler', $projectRoot);
            }
        }
        if ($isRecallOwner) {
            foreach (RecallResources::maintainerSkills() as $id => $path) {
                if (is_file($path . '/SKILL.md')) {
                    self::registerExportableSkill($skills, $id, $path, 'voku/agent-recall-compiler', $projectRoot);
                }
            }
        }

        // 4. voku/agent-session
        $isSessionOwner = self::isOwnerRepository($projectRoot, 'voku/agent-session');
        foreach (SessionResources::consumerSkills() as $id => $path) {
            if (is_file($path . '/SKILL.md')) {
                self::registerExportableSkill($skills, $id, $path, 'voku/agent-session', $projectRoot);
            }
        }
        if ($isSessionOwner) {
            foreach (SessionResources::maintainerSkills() as $id => $path) {
                if (is_file($path . '/SKILL.md')) {
                    self::registerExportableSkill($skills, $id, $path, 'voku/agent-session', $projectRoot);
                }
            }
        }

        ksort($skills, SORT_STRING);

        return $skills;
    }

    /**
     * @param array<string, array{id: string, path: string, owner: string}> $skills
     */
    private static function registerExportableSkill(array &$skills, string $id, string $path, string $owner, string $projectRoot): void
    {
        $path = self::exportPathForProject($owner, $path, $projectRoot);
        if ($path === null) {
            return;
        }
        $existing = $skills[$id] ?? null;
        if (is_array($existing) && ($existing['path'] !== $path || $existing['owner'] !== $owner)) {
            throw new RuntimeException('Multiple first-party packages export the same skill id: ' . $id);
        }

        $skills[$id] = [
            'id' => $id,
            'path' => $path,
            'owner' => $owner,
        ];
    }

    /**
     * An owner repository's checked-out resource is the current source of truth;
     * an installed copy is only the fallback used by ordinary consumers.
     */
    private static function exportPathForProject(string $owner, string $path, string $projectRoot): ?string
    {
        $path = self::normalize($path);
        if (!self::isOwnerRepository($projectRoot, $owner)) {
            return $path;
        }

        $packageRoot = self::packageRoot($owner);
        if ($packageRoot === null || !self::inside($path, $packageRoot)) {
            return $path;
        }

        $candidate = self::normalize($projectRoot) . '/' . ltrim(substr($path, strlen(rtrim($packageRoot, '/'))), '/');

        return is_file($candidate . '/SKILL.md') ? self::normalize($candidate) : null;
    }

    public static function sourceRootForProject(string $owner, string $projectRoot): ?string
    {
        if (self::isOwnerRepository($projectRoot, $owner)) {
            return self::normalize($projectRoot);
        }

        return self::packageRoot($owner);
    }

    /**
     * @return array<string, string> owner => fragment file path
     */
    public static function instructionFragments(string $projectRoot): array
    {
        $fragments = [];

        // voku/agent-learning
        if (!self::isOwnerRepository($projectRoot, 'voku/agent-learning')) {
            $fragment = LearningResources::consumerInstructionFragment();
            if ($fragment !== null && is_file($fragment)) {
                $fragments['voku/agent-learning'] = $fragment;
            }
        }

        // voku/agent-recall-compiler
        if (!self::isOwnerRepository($projectRoot, 'voku/agent-recall-compiler')) {
            $fragment = RecallResources::consumerInstructionFragment();
            if ($fragment !== null && is_file($fragment)) {
                $fragments['voku/agent-recall-compiler'] = $fragment;
            }
        }

        // voku/agent-session
        if (!self::isOwnerRepository($projectRoot, 'voku/agent-session')) {
            $fragment = SessionResources::consumerInstructionFragment();
            if ($fragment !== null && is_file($fragment)) {
                $fragments['voku/agent-session'] = $fragment;
            }
        }

        ksort($fragments, SORT_STRING);

        return $fragments;
    }

    public static function composedProjectInstructions(string $projectRoot): string
    {
        $path = LoopResources::projectInstructions();
        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Package project instruction source is missing or empty: ' . $path);
        }

        $composed = trim($content);
        foreach (self::instructionFragments($projectRoot) as $fragmentPath) {
            $fragment = file_get_contents($fragmentPath);
            if (is_string($fragment) && trim($fragment) !== '') {
                $composed .= "\n\n" . trim($fragment);
            }
        }

        return str_replace(
            '{{agent_loop_cli}}',
            (new RepositoryActivation($projectRoot))->cliPath(),
            $composed,
        );
    }

    /** @param class-string $className */
    private static function resolveClassRoot(string $className): ?string
    {
        if (!class_exists($className)) {
            return null;
        }

        $file = (new ReflectionClass($className))->getFileName();

        return is_string($file) ? self::normalize(dirname($file, 2)) : null;
    }

    private static function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private static function normalize(string $path): string
    {
        $real = realpath($path);
        $path = $real === false ? $path : $real;

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function validReference(string $reference): bool
    {
        if ($reference === '.') {
            return true;
        }
        if ($reference === '' || str_starts_with($reference, '/') || str_contains($reference, '\\')) {
            return false;
        }

        foreach (explode('/', $reference) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
