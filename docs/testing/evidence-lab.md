# Evidence Lab

Use this opt-in convention for executable checks that help answer implementation questions. It reuses PHPUnit and the existing task evidence; it adds no package, CI stage, suite, or evidence schema.

## Keep the artifacts distinct

| Artifact | Purpose | Lifetime | Where it belongs |
| --- | --- | --- | --- |
| Implementation probe | Answer a question while changing code | Temporary; may fail | `build/evidence/<task-id>/probes/` |
| Regression test | Protect approved behavior from future changes | Maintained | `tests/` |
| Execution evidence | Record what actually ran and what it showed | Retain selectively | Existing Run/Session evidence |

The tool does not determine the artifact's purpose. A PHPUnit file can be a temporary probe during implementation; it becomes a regression test only when its expected behavior is grounded in an approved requirement, contract, or established invariant and its ongoing value justifies the maintenance cost.

## Run a probe explicitly

The repository already ignores `/build/`, and `phpunit.xml` points the normal suite at `tests/`. Put a task's temporary checks under its own directory:

```sh
mkdir -p build/evidence/GH-123/probes
```

For example, an evidence file can use PHPUnit without a `Test.php` filename:

```php
<?php

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\CandidateWorkspaceIdentity;

final class QuestionEvidence extends TestCase
{
    #[Test]
    public function missingBaseIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_CANDIDATE: observing a candidate requires an exact governed base commit.');
        (new CandidateWorkspaceIdentity())->observe(getcwd(), null);
    }
}
```

Save it as `QuestionEvidence.php` and run it explicitly without loading the default PHPUnit configuration:

```sh
vendor/bin/phpunit \
    --no-configuration \
    --bootstrap vendor/autoload.php \
    build/evidence/GH-123/probes/QuestionEvidence.php
```

PHPUnit reports the executed method as a test; the file's purpose and disposable location make it an implementation probe, not a maintained regression test. Match the class name to the filename when invoking a file directly.

This command does not add the probe to `composer test`, `composer test:fast`, or `composer ci`. Do not put disposable checks in the `slow` group: the full `composer ci` command still includes that group. Keep production edits within the approved task scope; the ignored probe directory does not grant extra source-edit authority.

When task and repository instructions permit this scratch location, keep a disposable probe outside the Contract's **implementation** scope; scope the implementation files the task actually changes. Host and sandbox permissions still apply. Explicitly including the probe directory in implementation scope binds its contents to the implementation snapshot: an empty scoped directory is unavailable, and changing or removing its contents can make validation evidence stale. Do not omit a required implementation or deliverable from scope to avoid that binding.

Ignored probes are not automatically delivered to a reviewer. Preserve and explicitly provide a selected probe with its observation and candidate identity when reproduction matters; check that the reviewer can access it. A Session note describing a probe is not the probe itself.

## Pilot and promotion

The GH-859 pilot used a temporary PHPUnit check with a synthetic Git repository to ask whether `CandidateWorkspaceIdentity::exportCandidate()` preserves tracked symlinks and executable modes. After observing that it did, the check was promoted into `CandidateScopeExportTest::testExportCandidatePreservesTrackedSymlinksAndExecutableModes()` because those metadata are part of the approved candidate-tree behavior. The maintained test is the executable example of a temporary export check.

That test calls the export helper directly. It does not establish that the full gateway selects the right export path, that submodules are materialized, or that interrupted exports are cleaned up. Treat those as separate questions when a real task needs them.

## Retain only consequential observations

If a probe changes the implementation decision, disproves an important assumption, or exposes an unresolved risk, record a short factual note through the current Run or Session mechanism. Include the question, exact source candidate, probe identity, command and inputs, observed exit/result, and important cases left untested. Use existing evidence records rather than inventing a new JSON receipt. Do not retain every passing experiment.

Promote a check into `tests/` only when its expected behavior is independently grounded and the test will protect a meaningful contract over time. Otherwise remove the file from `build/evidence/<task-id>/probes/` when the investigation is complete. A green probe records one observed outcome; it is not proof of correctness.
