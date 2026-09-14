---
name: agent-learning
description: Capture reusable lessons about agent-loop workflow, validation, migration, evidence integrity, discipline dogfood, and package ownership before promoting durable guidance.
---

# Agent Learning

**Trigger Anchor:** Reusable lesson or recurring defect discovered -> check existing guidance first, sweep entire backlog, promote to the lowest viable mechanism in the value ladder.

## Fast Path

1. **Check Existing First:** Inspect README, changelog, `docs/`, and `resources/skills/` before inventing new guidance. Refine the existing home when possible.
2. **Sweep Full Backlog:** Process all validated, unconsolidated backlog items. Handling only recent findings introduces recency bias.
3. **Cluster by Owner:** Group findings by owning package (`agent-loop`, `agent-map`, `agent-learning`, etc.) or workflow boundary.
4. **Promote Down Ladder:** Choose the lowest mechanism that solves the problem (runtime check > static rule > dogfood test > documentation).
5. **Validate & Record:** Verify exit status of tests/dogfood; record explicit reasons for any deferred items.

## Promotion Value Ladder

```text
Raw Finding (observed, evidence-backed defect/discovery)
  -> Reviewed Guidance / Memory (documented convention or skill rule)
  -> Typed Runtime / Dogfood Case (automated behavioral enforcement)
  -> Static Constraint (PHPStan / CI rule for statically verifiable invariants)
```

| Mechanism | Target Location | When to Use |
|---|---|---|
| Static Rule | PHPStan custom rules / coding standards | Statically verifiable property; avoids noisy style preferences |
| Typed Runtime | `src/AgentGuidance/`, `src/Init/` | Behavior must execute deterministically during operation |
| Dogfood Gate | `tools/agent-discipline-dogfood.php`, `docs/dogfood/` | Skill/hook/prompt regression prevention |
| Targeted Skill | `resources/skills/<skill-name>/SKILL.md` | Domain heuristic or workflow decision boundaries (e.g. `agent-loop-discipline` for adaptive PHP navigation) |
| Durable Memory | `.agent-loop/learning/` or `MEMORY.md` | General reviewable precedent needing human validation |

### Bad vs Good Promotion

### Bad
Promoting a vague memory rule for a statically checkable bug:
```text
# Memory note: Remember to check if method arguments match in all calls.
```

### Good
Promoting to executable PHPStan rule or typed runtime check:
```php
// Custom PHPStan rule: enforce exact parameter count or fail at static analysis
if (count($methodCall->getArgs()) < $requiredCount) {
    return [RuleErrorBuilder::message('Parameter count mismatch')->build()];
}
```

## Historical Context (`ctx`)

```bash
ctx search "<task / migration / failure / command>"
ctx show event <ctx-event-id> --window 5
```
History explains provenance; it does NOT prove current behavior. Persist only bounded event IDs and verified summaries. Never copy raw transcripts or secrets into findings.

## Validation Commands

```bash
vendor/bin/agent-loop init doctor
vendor/bin/agent-loop init validate --kind=all
vendor/bin/agent-loop init install-assets --agent=codex --dry-run
composer dogfood:discipline
vendor/bin/phpunit --filter 'AgentDisciplineHook|InitInstallAssets|Init|DispatcherTest'
vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=512M
composer ci
```
