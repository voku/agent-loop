<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use voku\AgentEdit\Cli\VerifyCommand;
use voku\AgentLoop\ProjectLayout;

/**
 * Governance facade for `agent-loop edit refactor verify`: supplies Loop's map-index default and delegates the
 * receipt dispatch, verification and `verification-result.json` to `voku/agent-edit`.
 */
final readonly class RefactorVerifyDispatchCommand
{
    public function __construct(private string $projectRoot)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if (in_array($tokens[0] ?? '', ['help', '--help', '-h'], true)) {
            echo $this->help();

            return 0;
        }

        $hasMapIndex = false;
        foreach ($tokens as $token) {
            $hasMapIndex = $hasMapIndex || $token === '--map-index' || str_starts_with($token, '--map-index=');
        }
        if (!$hasMapIndex) {
            $tokens[] = '--map-index=' . (new ProjectLayout($this->projectRoot))->mapIndex();
        }

        return (new VerifyCommand($this->projectRoot))->run($tokens);
    }

    private function help(): string
    {
        return <<<'TXT'
Usage:
  agent-loop edit refactor verify --bundle=.agent-loop/edit/TASK [--map-index PATH] [--map-root PATH] [--accept-residue=REASON]

Read-only verification of one applied refactor bundle, executed by voku/agent-edit from the persisted
receipt (`execution.json`). It requires independently observed changed-file evidence, binds the plan
and the refreshed Map, and writes verification-result.json into the bundle.

For rename and method-removal plans it also re-scans Markdown and Twig/Smarty/Blade files for the old symbol. Remaining
non-historical mentions make the result `incomplete` (and block close) until they are fixed or accepted with
--accept-residue=REASON, which records the reason in the result.

TXT;
    }
}
