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

## The canonical learning ladder: from real work to deterministic enforcement

A recurring architectural misconception is the sequence:

```text
Finding -> Proposal -> LearningNote   (INCORRECT)
```

A `LearningNote` is **not** active guidance and does **not** require a `Proposal` to be created. It is precedent knowledge derived directly from a validated `Finding`.

The canonical owner flow is:

```text
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
  ↓
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
```

### 1. Finding: "What actually happened"

A Finding is **not yet a rule**. It records structured, validated evidence from a single task:
- task identity and session
- concrete observation
- reproducible evidence (file diffs, command output, test results)
- validated conclusion
- stable `pattern_key` and classification (e.g. `ADD_LEARNING_NOTE`)
- bounded scope and validation case (`given`, `when`, `then`)

A single incident does not create a project-wide policy or constraint.

### 2. LearningNote: "Documented precedent knowledge"

From a Finding classified `ADD_LEARNING_NOTE`, a `LearningNote` is published **directly**:

```text
Finding -> LearningNote
```

The `LearningNote`:
- is **not** an active rule or constraint;
- is **not** evaluated as active guidance during task execution;
- is structured precedent knowledge indexed by `pattern_key`, tags, and scope;
- states: *"When you encounter a similar situation in the future, review this precedent."*

### 3. Recall & L2/L1: "Precedent informs new work"

When Task B starts:
1. Recall queries relevant precedents matching the task scope/tags (`facts.json`).
2. Recall compiles the L2 system prompt incorporating the precedent.
3. A concrete L1 execution contract is bound for the coding agent.
4. The agent executes the task under that bounded context.

### 4. Observable decision & new Finding: "Testing causal impact"

During Task B, the agent's behavior is observed:
- Did the agent alter its decision or avoid the past failure?
- Was the precedent genuinely causal (`DEMONSTRATED_VALUE`), or did the prompt already mandate the behavior anyway (`WITHHELD`)?
- A **new Finding** is captured for Task B, recording the observed outcome.

### 5. Repeated pattern & Dream: "Systematization requires repeated evidence"

Only when multiple independent Findings show:
- the pattern recurs across distinct sessions/tasks;
- the precedent has measurable positive impact;
- the knowledge is stable enough to formalize into an active policy;

does the synthesis stage begin:

```text
repeated Findings
  ↓
Dream / guidance-evaluate
  ↓
Proposal
```

Dream clusters evidence and drafts candidate Proposals:
- `memory` (soft heuristics or working conventions);
- `skill` (workflow procedures or tool instructions);
- `constraint` (hard semantic invariants).

### 6. Human approval gate

Proposals are **never auto-promoted**. A human authority explicitly reviews the evidence, candidate diff, and validation case:
- Accept, reject, or adjust.
- Only upon explicit human approval does the candidate become active project guidance.

### 7. Active Guidance (Memory, Skill, or Constraint)

Approved proposals transition into active owner guidance:
- A `constraint` records the semantic invariant, target engine, and validation commands.
- Future Recall runs include it as active evaluated guidance with outcome tracking.

### 8. Deterministic enforcement: "Code replaces prose"

For hard invariants, soft prose guidance is only an interim bridge. Once a rule can be mechanically verified, it must be promoted to a repository-owned deterministic gate:

```text
Constraint (semantic invariant)
  ↓
Project-owned deterministic enforcement (PHPStan rule, PHPCS sniff, ArchTest, CI check)
```

Examples in real codebases:
- Semantic / type-narrowing rules: custom PHPStan rule (e.g. `ItPortalInlineVarWrongDelimiterRule`);
- Code style / formatting safety: custom PHP-CS-Fixer rule or PHPCS sniff (e.g. `noRedirectInUnitCest`);
- Architectural boundaries: deptrac or PHPStan architecture rules.

### 9. Guidance retirement: "Zero token overhead for machine-proven rules"

Once deterministic enforcement is wired into CI:
- CI fails mechanically if the rule is violated, providing immediate feedback before merge;
- soft prompt instructions become redundant and can be retired or omitted from default Recall;
- the Constraint remains in historical lineage as the semantic reference and justification;
- agents spend zero prompt tokens remembering what CI proves automatically.

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
