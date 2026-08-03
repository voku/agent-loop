<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use Throwable;

final readonly class EditCommand
{
    public function __construct(
        private string $projectRoot,
        private EditRequestParser $parser = new EditRequestParser(),
        private EditOrchestrator $orchestrator = new EditOrchestrator(),
    ) {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens === [] || in_array($tokens[0] ?? '', ['help', '--help', '-h'], true)) {
            return $this->help();
        }

        try {
            $request = $this->parser->parse($this->projectRoot, $tokens);
            $outcome = $this->orchestrator->execute($request);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }

        fwrite(STDOUT, "Edit execution bundle prepared: {$outcome->outputDirectory}\n");
        fwrite(STDOUT, "- target: {$request->target}\n");
        fwrite(STDOUT, "- status: {$outcome->runResult->status}\n");
        fwrite(STDOUT, "- map digest: {$outcome->mapDigest}\n");
        fwrite(STDOUT, "- recall bundle: {$outcome->recallBundleDigest}\n");
        fwrite(STDOUT, "- prompt: {$outcome->promptPath}\n");
        fwrite(STDOUT, "- execution: {$outcome->executionPath}\n");

        return $outcome->succeeded() ? 0 : 1;
    }

    private function help(): int
    {
        $usage = <<<'TXT'
        Usage:
          agent-loop edit CLASS::METHOD [options] -- INSTRUCTION

        Deterministically refreshes the repository map when necessary, compiles
        target-aware recall, writes one execution bundle, and optionally hands
        the compiled prompt to a generic command runner.

        Options:
          --task ID                 Stable task ID. Generated from target and instruction by default.
          --recall-root PATH        Learning/recall root. Auto-discovered below the project root.
          --map-index PATH          JSON or TOON map path. Default: .agent-map/php-symbols.json
          --map-root PATH           Runtime source root for map freshness checks. Default: project root.
          --map-paths PATHS         Comma-separated map build paths. Default: .
          --map-exclude REGEX       Additional agent-map exclude regex. Repeatable.
          --phpstan-config PATH     Explicit PHPStan configuration used while rebuilding the map.
          --output-dir PATH         Execution bundle directory. Default: .agent-loop/edit/<task-id>
          --rebuild-map             Rebuild even when the current map is fresh.
          --no-rebuild-map          Fail instead of building a missing or stale map.
          --dry-run                 Prepare the bundle without invoking the selected runner.
          --runner NAME             stdout (default) or command.
          --runner-command PATH     Executable for --runner=command. No shell is used.
          --runner-arg VALUE        Command argument. Repeatable; use --runner-arg=--flag for flags.
          --runner-timeout SECONDS  Command timeout. Default: 900.
          --print-prompt            Print prompt.md when using the stdout runner.

        Examples:
          agent-loop edit 'App\Service\UserService::save' -- \
            'Reject inactive users before persistence and adapt affected callers.'

          agent-loop edit 'App\Service\UserService::save' \
            --runner=command --runner-command=codex \
            --runner-arg=exec --runner-arg=- \
            -- 'Reject inactive users before persistence.'

        TXT;
        fwrite(STDOUT, $usage);

        return 0;
    }
}
