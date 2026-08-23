<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use Throwable;
use voku\AgentLoop\Edit\Refactor\RefactorEditCommand;
use voku\AgentLoop\Edit\Refactor\RefactorVerifyDispatchCommand;
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
        // Refactor plans have their own non-method target contract and must be routed before the
        // ordinary edit request parser enforces CLASS::METHOD. Their deterministic post-apply
        // verifier is routed here too, so it never inherits method-target parsing by accident.
        if ($tokens[0] === 'refactor') {
            if (($tokens[1] ?? null) === 'verify') {
                return (new RefactorVerifyDispatchCommand($this->projectRoot))->run(array_slice($tokens, 2));
            }

            return (new RefactorEditCommand($this->projectRoot))->run(array_slice($tokens, 1));
        }
        // `verify` grades a bundle this command produced earlier; it takes no target and shares no
        // options with an edit run, so it is routed before the request parser sees the tokens.
        if ($tokens[0] === 'verify') {
            return (new EditVerifyCommand($this->projectRoot))->run(array_slice($tokens, 1));
        }

        try {
            $request = $this->parser->parse($this->projectRoot, $tokens);
            if (!$request->dryRun && in_array($request->runner, ['command', 'mechanical', 'method-rename', 'auto'], true)) {
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
          agent-loop edit refactor PLAN [options]
          agent-loop edit refactor verify --bundle=.agent-loop/edit/TASK [options]

        Deterministically refreshes the repository map when necessary, compiles
        target-aware recall, writes one execution bundle, and optionally hands
        the compiled prompt to a generic command runner.

        `edit refactor` is the separate governed boundary for consuming an already-produced
        agent-map rename or method-removal plan. It does not reinterpret those targets as methods
        and does not accept arbitrary edit plans. Method-removal keeps a separate fail-closed
        decoder and verifier while sharing the project mutation lock and transactional publication
        boundary. After mutation and Map refresh, `edit refactor verify` dispatches from persisted
        runner evidence and writes the verification-result.json required by governed closeout.

        Governed mutation gate:
          If --task identifies an active governed workflow, command/mechanical/method-rename/auto
          mutation requires the current L2 execution contract to be ready. `edit refactor` applies
          the same execution-contract gate independently. Dry-run, refactor verification and stdout
          prompt preparation remain read-only and may run without source mutation.

        Options:
          --task ID                 Stable task ID. Generated from target and instruction by default.
          --recall-root PATH        Learning/recall root. Auto-discovered below the project root.
          --map-index PATH          JSON or TOON map path. Default: .agent-loop/map/php-symbols.json
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
          --runner NAME             stdout (default), auto, command, mechanical, or method-rename.
          --runner-command PATH     Executable for --runner=command. No shell is used.
          --runner-arg VALUE        Command argument. Repeatable; use --runner-arg=--flag for flags.
          --replace-old TEXT        Exact target-method literal for --runner=mechanical.
          --replace-new TEXT        Replacement literal for --runner=mechanical.
          --rename-method NAME      New method name for --runner=method-rename.
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

          agent-loop edit refactor build/property-rename-plan.json --task=REFACTOR-42 --dry-run
          agent-loop edit refactor build/method-removal-plan.json --task=REFACTOR-43 --dry-run
          agent-loop edit refactor verify --bundle=.agent-loop/edit/REFACTOR-43

        TXT;
        echo $usage;

        return 0;
    }
}
