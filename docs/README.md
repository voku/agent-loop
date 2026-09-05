# Documentation

`README.md` is the product and installation front door. `AGENTS.md` contains repository-specific instructions for agents contributing to `voku/agent-loop`. This index routes readers into the narrower documentation under `docs/`.

## Start here

- **Using `agent-loop` as a developer:** read [Your first governed task](quick-start.md), then [Human authority](workflow/humans.md) when you need to know what requires your decision.
- **Using `agent-loop` as a coding agent:** read the [Coding-agent field guide](workflow/ai.md), then follow the current lifecycle result rather than remembered prose.
- **Looking for a particular journey or recovery path:** use the [Workflow index](workflow/README.md).
- **Contributing to this repository:** read [`AGENTS.md`](../AGENTS.md) for package ownership and implementation rules, then the focused architecture/contributor documents relevant to the change.

## Choose the smallest host front door

The canonical durable lifecycle remains `enter -> work -> finish`. The newer host commands are convenience surfaces around that authority, not alternate lifecycle specifications:

- `quick` starts a bounded surgical micro-task when the work genuinely fits the fast-path limits; it still produces governed task state and must stay inside its declared scope.
- `repair` consumes an already-recorded validation failure and projects a bounded repair instruction. Its default repair budget is two attempts before human escalation.
- `pipeline` drives an existing governed execution profile (`surgical`, `standard`, or `hardened`) through its current stage, handoff, verification, and attention state; completion returns to `finish`.

Use the [Workflow index](workflow/README.md) to choose between those surfaces without memorizing their internal stage ordering.

## Ordinary durable task

The host-facing path is deliberately small:

```text
enter <task-id>
  -> obey the current lifecycle result
  -> perform host-native work when authorized
  -> finish <task-id>
  -> obey the current lifecycle result until complete
```

The executable lifecycle kernel owns ordering, mutation readiness, and the canonical next action. Documentation explains how to consume that result; it does not define another gate sequence.

For the concrete command walkthrough, use [quick-start.md](quick-start.md). For lifecycle ownership and the structured result contract, use [workflow/lifecycle.md](workflow/lifecycle.md).

## Documentation boundaries

- `docs/` explains the product, workflows, architecture, policies, contributor concerns, and dogfood evidence.
- `resources/` contains package-shipped skills, subagents, prompts, hooks, and other static product assets.
- `README.md` stays the public product/install overview instead of becoming an operations manual.
- `AGENTS.md` stays repository-specific instead of becoming generic consumer documentation.

See [Repository layout](contributing/repository-layout.md) for the full path convention.
