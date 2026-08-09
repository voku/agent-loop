<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use Throwable;
use voku\AgentLoop\Edit\Verify\EditVerifyCommand;
use voku\AgentLoop\Workflow\ExecutionContractStore;

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
        if ($tokens === []) {
            return $this->help();
        }
        if (in_array($tokens[0], ['help', '--help', '-h'], true)) {
            return $this->help();
        }
        // `verify` grades a bundle this command produced earlier; it takes no target and shares no
        // options with an edit run, so it is routed before the request parser sees the tokens.
        if ($tokens[0] === 'verify') {
            return (new EditVerifyCommand($this->projectRoot))->run(array_slice($tokens, 1));
        }

        try {
            $request = $this->parser->parse($this->projectRoot, $tokens);
            if (!$request->dryRun && in_array($request->runner, ['command', 'mechanical', 'auto'], true)) {
                (new ExecutionContractStore($request->projectRoot))->assertReadyForMutation($request->taskId);
            }
            $outcome = $this->orchestrator->execute($request);
        } catch (Throwable $exception) {
            fwrite(STDERR, '[ERROR] ' . $exception->getMessage() . "\n");

            return 1;
        }

        echo "Edit execution bundle prepared: {$outcome->outputDirectory}\n";
        echo "- target: {$request->target}\n";
        echo "- status: {$outcome->runResult->status}\n";
        echo "- map digest: {$outcome->mapDigest}\n";
        echo "- recall bundle: {$outcome->recallBundleDigest}\n";
        echo "- prompt: {$outcome->promptPath}\n";
        echo "- execution: {$outcome->executionPath}\n";

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

        Governed mutation gate:
          If --task identifies an active governed workflow, command/mechanical/auto
          mutation requires the current L2 execution contract to be ready. Dry-run
          and stdout prompt preparation remain read-only and may run before that gate.

        Options:
          --task ID                 Stable task ID. Generated from target and instruction by default.
          --recall-root PATH        Learning/recall root. Auto-discovered below the project root.
          --map-index PATH          JSON or TOON map path. Default: .agent-map/php-symbols.json
          --map-root PATH           Runtime source root for map freshness checks. Default: project root.
          --map-paths PATHS         Comma-separated map build paths. Default: .
          --map-exclude REGEX       Additional agent-map exclude regex. Repeatable.
          --focus TEXT              Narrow target source context around this literal. Repeatable.
          --phpstan-config PATH     Explicit PHPStan configuration used while rebuilding the map.
          --phpstan-memory-limit N  Positive PHPStan memory limit, e.g. 512M or 2G.
          --output-dir PATH         Execution bundle directory. Default: .agent-loop/edit/<task-id>
          --rebuild-map             Rebuild even when the current map is fresh.
          --no-rebuild-map          Fail instead of building a missing or stale map.
          --dry-run                 Prepare the bundle without invoking the selected runner.
          --runner NAME             stdout (default), auto, command, or mechanical.
          --runner-command PATH     Executable for --runner=command. No shell is used.
          --runner-arg VALUE        Command argument. Repeatable; use --runner-arg=--flag for flags.
          --replace-old TEXT        Exact target-method literal for --runner=mechanical.
          --replace-new TEXT        Replacement literal for --runner=mechanical.
          --runner-timeout SECONDS  Command timeout. Default: 900.
          --print-prompt            Print prompt.md when using the stdout runner.

        Audit warning:
          Runner commands and arguments are persisted verbatim in request.json
          and execution.json. Pass secrets through the runner environment, never
          through --runner-command or --runner-arg values.

        Examples:
          agent-loop edit 'App\Service\UserService::save' -- \
            'Reject inactive users before persistence and adapt affected callers.'

          agent-loop edit 'App\Service\UserService::save' \
            --runner=command --runner-command=codex \
            --runner-arg=exec --runner-arg=- \
            -- 'Reject inactive users before persistence.'

          agent-loop edit 'App\Service\UserService::save' --runner=mechanical \
            --replace-old='$legacyUser->regionId' --replace-new='$legacyUser->getCurrentRegionId()' \
            -- 'Replace the deprecated region property.'

          agent-loop edit 'App\Service\UserService::save' --runner=auto \
            --replace-old='$legacyUser->regionId' --replace-new='$legacyUser->getCurrentRegionId()' \
            -- 'Replace the deprecated region property without a model runner.'

        TXT;
        echo $usage;

        return 0;
    }
}
