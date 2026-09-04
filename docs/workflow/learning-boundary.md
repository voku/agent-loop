# Learning boundary

`agent-loop` keeps the review/workflow safety spine separate from durable
memory. The learning pipeline may collect findings, build candidates, and help
humans evaluate patterns, but those artifacts are not durable memory by
appearance alone.

## Boundary rule

- Findings are not durable memory.
- Learning candidates are not durable memory.
- Only reviewed decisions become durable guidance.

This means a workflow can compile recall context, run blind-spot review, close a
session, and record learning evidence without automatically promoting anything
into `MEMORY.md` or active guidance. Durable guidance remains a human-reviewed
choice.

## From findings to executable constraints

Durable guidance does not have to remain Markdown. When coding-session evidence
shows a recurring project-specific problem and the requirement is mechanically
checkable, the stronger outcome is often a repository-owned executable rule:

```text
coding task
  -> observed Finding
  -> repeated / corroborated Learning evidence
  -> human-reviewed durable decision
  -> executable project rule
  -> CI enforces the rule on later changes
```

For PHP repositories, that can mean:

- a custom PHPStan rule for semantic, architectural, or type-aware constraints;
- a custom php-cs-fixer fixer for deterministic code-shape or formatting rules;
- a focused test for a behavioral invariant;
- another deterministic linter or repository command when neither PHPStan nor a
  fixer is the right enforcement layer.

The point is not to translate every Finding into code. Some knowledge is
contextual and belongs in reviewed guidance. But once a stable rule can be
checked by a machine, leaving it only in `AGENTS.md`, `MEMORY.md`, a prompt, or a
LearningNote makes every future agent spend context on a constraint CI could
prove directly.

This promotion is deliberately not automatic. Learning owns the Findings,
evidence, proposals, LearningNotes, and decision history. A human decides
whether the pattern is sufficiently stable and general to enforce. The host
repository owns the PHPStan rule, fixer, test, linter, and CI configuration that
implements that decision.

Once the executable rule exists, its result is the authoritative evidence for
that invariant. Recall or guidance may still explain *why* the rule exists and
when it matters, but agents should not be asked to remember a prose-only version
of a constraint the repository can enforce deterministically. That reduces
repeated review work and avoids spending prompt tokens restating machine-checkable
policy.

`agent-loop` itself dogfoods this separation: project-specific PHPStan fixture
checks and architecture-rule validation are part of `composer ci`, while the
Learning artifacts preserve the evidence and reasoning that justified durable
constraints.

## Human MEMORY.md promotion review

Use the memory review command when a repository has a `MEMORY.md` promotion
queue:

```bash
agent-loop memory review --file MEMORY.md
```

`MemoryPromotionAnalyzer` is the human review boundary for `MEMORY.md`
promotion state. It reports entries that still need promotion review; it does
not approve, rewrite, or auto-promote durable memory.

## Non-goals

- No automatic durable-memory promotion.
- No automatic generation or approval of project CI rules.
- No changes to `voku/agent-learning` package behavior.
- No LLM calls from runtime code.
