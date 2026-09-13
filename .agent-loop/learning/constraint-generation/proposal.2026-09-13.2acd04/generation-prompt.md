# Constraint Adoption Prompt

The repository already contains the configured phpstan enforcement for `agent-loop.phpstan.in-process-rule-test-case`.

Existing target path: `phpstan/Rules/NoInProcessPhpstanRuleTestCaseRule.php`

Existing registration files:
- phpstan/project-rule-test.neon
- composer.json

Do not generate a duplicate rule or synthetic PHP fixtures. Validate the existing enforcement against the approved constraint semantics and historical bad/good states using:
- php tools/project-phpstan-rules.php
- composer ci

If that validation passes, use the existing constraint activation path to record the reviewed lineage manifest. Do not activate the constraint without human approval.
