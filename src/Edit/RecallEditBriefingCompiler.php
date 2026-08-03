<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use JsonException;
use RuntimeException;
use voku\AgentRecallCompiler\Command\CompileCommand;

final readonly class RecallEditBriefingCompiler implements EditBriefingCompiler
{
    public function __construct(private CompileCommand $command = new CompileCommand())
    {
    }

    public function compile(EditRequest $request): EditBriefing
    {
        $outputDirectory = $request->outputDirectory . '/recall';
        $exit = $this->command->run([
            '--root',
            $request->recallRoot,
            '--task',
            $request->taskId,
            '--description',
            $request->instruction,
            '--target',
            $request->target,
            '--map-index',
            $request->mapIndex,
            '--map-root',
            $request->mapRoot,
            '--output-dir',
            $outputDirectory,
            '--compilation-id',
            'edit.' . $request->taskId,
        ]);
        if ($exit !== 0) {
            throw new RuntimeException('Recall compilation failed with exit code ' . $exit . '.');
        }

        $systemMarkdown = $this->read($outputDirectory . '/system.md');
        $validationMarkdown = $this->read($outputDirectory . '/validation-plan.md');
        $meta = $this->readJson($outputDirectory . '/meta.json');
        $this->verifyOutputHash($meta, 'system.md', $systemMarkdown);
        $this->verifyOutputHash($meta, 'validation-plan.md', $validationMarkdown);
        $this->verifyOutputHash($meta, 'recall.bundle.json', $this->read($outputDirectory . '/recall.bundle.json'));

        $bundleDigest = $meta['bundle_sha256'] ?? null;
        if (!is_string($bundleDigest) || trim($bundleDigest) === '') {
            throw new RuntimeException('Recall metadata does not contain bundle_sha256.');
        }

        return new EditBriefing(
            outputDirectory: $outputDirectory,
            systemMarkdown: $systemMarkdown,
            validationMarkdown: $validationMarkdown,
            bundleDigest: $bundleDigest,
            meta: $meta,
        );
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read recall artifact: ' . $path);
        }

        return $content;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        try {
            $decoded = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid recall metadata: ' . $path . '. ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Recall metadata must be a JSON object: ' . $path);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $meta */
    private function verifyOutputHash(array $meta, string $name, string $content): void
    {
        $hashes = $meta['output_hashes'] ?? null;
        $expected = is_array($hashes) ? ($hashes[$name] ?? null) : null;
        if (!is_string($expected) || !hash_equals($expected, hash('sha256', $content))) {
            throw new RuntimeException('Recall artifact hash mismatch: ' . $name);
        }
    }
}
