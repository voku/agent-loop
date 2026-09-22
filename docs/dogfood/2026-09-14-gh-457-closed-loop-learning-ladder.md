# Closed-Loop Learning Ladder: End-to-End Lineage from Real Work to Deterministic Enforcement

## Context and Purpose

This receipt documents the canonical closed-loop learning architecture across `voku/agent-loop` and `voku/agent-learning`, resolving the architectural boundary discussed in `voku/agent-loop#457`, `voku/agent-learning#107`, and `voku/agent-learning#73`.

### The Anti-Pattern vs. The Canonical Ladder

An early misconception of the learning workflow conflated precedent capture with policy creation:

```text
Finding -> Proposal -> LearningNote   (INCORRECT ANTI-PATTERN)
```

This anti-pattern is wrong on two counts:
1. It creates bureaucratic overhead for capturing single-session lessons, requiring proposals and approvals merely to store what happened.
2. It prematurely elevates raw, unrepeated observations into the proposal review pipeline before any repeated pattern or causal utility has been proven.

The canonical owner flow cleanly decouples the **Evidence Loop** from the **Promotion Loop**:

```text
======================= EVIDENCE LOOP =======================
                      real work
                          ↓
                       Finding
                          ↓
                     LearningNote
                          ↓
                        Recall
                          ↓
               L2 -> concrete L1 -> coding agent
                          ↓
                 observable decision
                          ↓
                     new Finding
====================== PROMOTION LOOP =======================
                  repeated pattern
                          ↓
                        Dream
                          ↓
                       Proposal
                          ↓
                    Human approval
                          ↓
              Memory / Skill / Constraint
                          ↓
               deterministic enforcement
                          ↓
                 guidance retirement
```

---

## The 10 Milestones of the Canonical Ladder

| Milestone | Artifact / Stage | Governing Owner | Semantic Meaning |
|---|---|---|---|
| **1. Observation** | `Finding` | `agent-learning` | Structured record of what happened in real work (task, diff, error, validation case). Not a policy. |
| **2. Precedent** | `LearningNote` | `agent-learning` | Bounded solved-case knowledge published directly from a Finding. Precedent only; not active guidance. |
| **3. Rehydration** | `Recall (L2/L1)` | `agent-recall-compiler` | Selected into subsequent task context by pattern/tag match. Binds L1 execution contract. |
| **4. Attribution** | `Observed Decision` | Host / `agent-loop` | Did the agent change its behavior? Classified honestly (`DEMONSTRATED_VALUE` vs `WITHHELD`). |
| **5. Recurrence** | `New Finding` | `agent-learning` | Second observation validates recurrence and isolates edge cases or tooling interactions. |
| **6. Synthesis** | `Dream / Evaluation` | `agent-learning` | Offline analysis groups repeated findings, checks outcome histories, drafts candidate proposals. |
| **7. Governance** | `Human Approval` | Human Authority | Strict gate. Human decides whether to accept, adjust, or reject the candidate. Never automated. |
| **8. Codification** | `Constraint / Skill` | `agent-learning` | Active owner rule. When target is a constraint, defines engine, rule id, and validation command. |
| **9. Mechanical Gate** | `Deterministic Rule` | Consuming Repository | Target project implements machine-enforced rule (PHPStan, PHPCS, ArchTest, CI). |
| **10. Retirement** | `Guidance Obsoletion` | `agent-learning` / UI | Soft prompt guidance is retired. Zero token overhead; CI deterministically enforces invariant. |

---

## Real Production Proof: A Downstream Consumer Trace

To prove this ladder is not theoretical prose, we trace the full lineage of an actual production pattern from a private downstream consumer's learning records:

### 1. Real Work & Initial Finding (`finding.2026-07-06.002`)
- **Task**: `TODO@agent-learning/phpstan-bump-cleanup` in `lib/framework/system/helper/TypeHelper.php`.
- **Observation**: After bumping PHPStan, `TypeHelper::toNumericIdentifierOrNull()` failed static analysis despite having an inline comment `/* @var numeric-string $value */`.
- **Evidence**: Single-star block comments (`/* ... */`) are plain comments to PHP-Parser. PHPStan's inline type narrowing requires PHPDoc double-star delimiters (`/** ... */`). The inline cast had been inert and silently ignored since authoring.
- **Classification**: `ADD_LEARNING_NOTE`
- **Pattern Key**: `phpstan.inline_var_cast_requires_phpdoc_delimiter`
- **Validation Case**:
  - *Given*: an inline `@var` type-narrowing comment appears immediately above a statement.
  - *When*: an agent writes or reviews that comment.
  - *Then*: use `/** @var Type $var */`, never `/* @var Type $var */`.

### 2. LearningNote Capture (Direct Precedent)
- Published directly as a solved-case precedent:
  - Documented context, symptoms, root cause, and verification command.
  - **Not** an active constraint. It did not alter prompt budgets or global policies across unrelated tasks.

### 3. Recall & Subsequent Task Injection
- When subsequent tasks touched PHP files in `lib/` or `modules/`, Recall matched the pattern key and scope tags, projecting the precedent into the task's `facts.json` and L2 prompt envelope.
- As demonstrated in `GH-457-Task-B` (`compilation.GH-457-Task-B.2026-09-13-084517.7b131090`), the precedent is rendered into `system.md` alongside the execution dispatch recipe, and the host binds a concrete L1 contract before editing.

### 4. Observable Decision & Second Finding (`finding.2026-07-06.006`)
- In subsequent cleanup work, the agent attempted to fix 9 occurrences discovered across the codebase (e.g. `JiraConnector.php`, `RemoApiGateway.php`).
- **Surprise Root Cause Discovered**: Running `make apply-php-cs-fixer` revealed that the repository's own `phpdoc_to_comment` rule in `phpcs.php_cs` was silently demoting `/** @var */` back into `/* @var */`!
- The second finding captured this critical interaction:
  `tooling.autofixer_reverts_or_corrupts_correct_code`.
- Fixing `phpcs.php_cs` to add `ignored_tags => ['var', 'phpstan-var']` solved the systemic issue at the tool configuration level.

### 5. Repeated Pattern & Dream Synthesis
- Multiple distinct findings across distinct files confirmed that human/agent authoring alone could not reliably prevent this subtle syntax demotion across a 500k-line legacy codebase.
- Offline analysis grouped these findings and prepared a candidate constraint proposal.

### 6. Human Approval Gate
- Proposal `finding.2026-07-06.002` was reviewed and approved by human maintainers.
- Scope: `lib/`, `modules/`, `infra/`, `scripts/`.
- Engine: `phpstan`.
- Rule identifier: `itportal.inlineVarWrongDelimiter`.

### 7. Active Constraint Activation
- Saved in `constraints/active/constraint.itportal.inlineVarWrongDelimiter.json`:
```json
{
    "schema_version": "1.0",
    "id": "constraint.itportal.inlineVarWrongDelimiter",
    "engine": "phpstan",
    "rule_identifier": "itportal.inlineVarWrongDelimiter",
    "scope": [
        "lib/",
        "modules/",
        "infra/",
        "scripts/"
    ],
    "validation_commands": [
        "make phpstan STATIC_ANALYSE_FILES=\"lib/framework/system/helper/TypeHelper.php\""
    ],
    "source_proposal": "finding.2026-07-06.002",
    "status": "active"
}
```

### 8. Deterministic Mechanical Gate (`ItPortalInlineVarWrongDelimiterRule.php`)
- Rather than leaving this rule as an endless prompt instruction for LLMs to heed or hallucinate, the team implemented a custom PHPStan rule in `infra/githooks/StandardITPortal/PHPStan/ItPortalInlineVarWrongDelimiterRule.php`:
```php
final class ItPortalInlineVarWrongDelimiterRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Stmt::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Comment\Doc) {
                continue;
            }
            $text = $comment->getText();
            if (preg_match('/@(?:phpstan-)?var\b.*\x24\w+/s', $text) !== 1) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(
                'Inline @var/@phpstan-var cast must use a PHPDoc comment (/** @var Type $var */), not a plain block comment (/* @var Type $var */) -- PHPStan silently ignores the latter.'
            )
                ->identifier('itportal.inlineVarWrongDelimiter')
                ->tip('Change the leading "/*" to "/**" so PHPStan actually recognizes the cast.')
                ->line($comment->getStartLine())
                ->build();
        }
        return $errors;
    }
}
```
- Registered in `infra/githooks/phpstan.neon`.
- Wired into `composer ci` and pre-commit hooks.

### 9. Guidance Retirement & Outcome Verification
- Outcome telemetry in `history/outcomes.jsonl` logged **101 evaluations**:
  - 13 helpful
  - 36 not_used
  - 48 irrelevant
  - 0 harmful
- With the deterministic PHPStan rule active, soft prompt text was no longer required in operating prompts:
  - Any regression immediately halts CI with an actionable compiler error.
  - Zero context tokens wasted on reminding agents to write `/**` instead of `/*`.
  - The constraint file remains in `constraints/active/` as the immutable lineage reference connecting the PHPStan rule back to the historical incident.

---

## Architectural Principles Enforced

1. **No Premature Bureaucracy**: A single problem produces a Finding and optionally a LearningNote. It never pollutes the global proposal queue.
2. **Precedent Is Not Policy**: LearningNotes are read-only precedents. They provide context when recalled, but are not active enforcement rules.
3. **Causal Attribution Discipline**: When evaluating an agent's run under a precedent, if the task prompt already required the behavior, attribute `WITHHELD`, not `DEMONSTRATED_VALUE`.
4. **Mechanical Supremacy**: If a constraint is checkable by AST, compiler, linter, or test, implement it as code. LLMs must not be treated as fragile linters when compilers exist.
5. **Traceable Lineage**: Every deterministic gate links back to an approved constraint, which links back to a proposal, which links back to the originating findings and git commits.
