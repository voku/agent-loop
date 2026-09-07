<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\InlineCompileTask;
use voku\AgentRecallCompiler\RecallCompiler;

final readonly class RecallEditBriefingCompiler implements EditBriefingCompiler
{
    public function __construct(private RecallCompiler $compiler = new RecallCompiler())
    {
    }

    public function compile(EditRequest $request): EditBriefing
    {
        $outputDirectory = $request->outputDirectory . '/recall';
        $result = $this->compiler->compile(new CompileRequest(
            learningRoot: $request->recallRoot,
            taskBrief: null,
            outputDirectory: $outputDirectory,
            mapIndex: $request->mapIndex,
            mapRoot: $request->mapRoot,
            editFocus: $request->focusTerms,
            compilationId: 'edit.' . $request->taskId,
            inlineTask: new InlineCompileTask(
                taskId: $request->taskId,
                description: $request->instruction,
                targets: [$request->target],
            ),
        ));

        return new EditBriefing(
            outputDirectory: $result->outputDirectory,
            systemMarkdown: $this->read($result->systemPath()),
            validationMarkdown: $this->read($result->validationPlanPath()),
            bundleDigest: $this->normalizeSha256Digest($result->bundleSha256, 'bundle_sha256'),
            meta: [
                'compilation_id' => $result->compilationId,
                'bundle_sha256' => $result->bundleSha256,
            ],
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

    private function normalizeSha256Digest(string $digest, string $name): string
    {
        $value = str_starts_with($digest, 'sha256:') ? substr($digest, 7) : $digest;
        if (preg_match('/\A[a-f0-9]{64}\z/i', $value) !== 1) {
            throw new RuntimeException('Recall compilation receipt contains an invalid ' . $name . '.');
        }

        return 'sha256:' . strtolower($value);
    }
}
