# Workflow documentation

These documents explain common human/AI journeys. They do not implement lifecycle ordering, mutation authority, or close readiness; the current lifecycle result remains authoritative.

For an ordinary durable task, keep the host-facing model small:

```text
enter -> obey current result -> work when authorized -> finish -> obey current result -> complete
```

Use [lifecycle.md](lifecycle.md) for lifecycle ownership and structured result semantics, and [../quick-start.md](../quick-start.md) for the concrete command walkthrough.

## Named workflows

This section is a router, not a second workflow specification. Each entry names the user-visible intent and points to the owner or deeper document that defines the details.

1. **Ordinary durable task** — start at `enter`, perform host-native work only when authorized, return through `finish`, and keep obeying the current result. See [quick-start.md](../quick-start.md) and [lifecycle.md](lifecycle.md).
2. **New task / Contract creation and approval** — let `enter` surface the current planning or decision action; present human-owned authority when it is actually required. See [quick-start.md](../quick-start.md), [lifecycle.md](lifecycle.md), and [humans.md](humans.md).
3. **Read-only exploration / ephemeral work** — investigate without inventing durable task authority; return to the durable lifecycle only when durable work is actually needed. See the read-only and diagnostic guidance in [lifecycle.md](lifecycle.md).
4. **Validation failure and implementation repair** — preserve the observed failure, repair the implementation when the lifecycle routes back to host work, then return through `finish`. The kernel decides the current action; prose does not predict the gate order. See [quick-start.md](../quick-start.md) and [lifecycle.md](lifecycle.md).
5. **Same-intent premise challenge / `REPLAN`** — `REPLAN` is agent-owned when the approved Goal, acceptance, scope, and authority stay unchanged; a change to those boundaries requires human authority. The package-owned simplicity/discipline skills define the premise-check heuristic, while the lifecycle owns task authority.
6. **Cross-package semantic-owner change** — change the semantic owner first, prove and release that owner when needed, then consume its public surface from Loop. For this repository's package boundaries, see [`AGENTS.md`](../../AGENTS.md).
7. **Learning return loop and later Recall precedent** — Learning owns durable findings/decisions; Recall consumes only the governed precedent exposed by its owner. See [learning-boundary.md](learning-boundary.md).
8. **Host asset install or repair** — use the setup/host-status surfaces to diagnose package-projected instructions, skills, agents, hooks, or host-policy capability, then return to the ordinary lifecycle. See the installation sections in [`README.md`](../../README.md) and the bootstrap in [quick-start.md](../quick-start.md).
9. **Human task review and evidence presentation** — show the exact review/evidence surface returned by the lifecycle and escalate only genuine authority decisions. See [humans.md](humans.md), [quick-start.md](../quick-start.md), and [lifecycle.md](lifecycle.md).
10. **Marker-driven release work** — release mechanics are repository-specific contributor policy, not generic product lifecycle. In this repository, follow the release rules in [`AGENTS.md`](../../AGENTS.md) and current repository evidence.

## Other workflow documents

- [humans.md](humans.md): human authority and supervision boundary.
- [ai.md](ai.md): compact coding-agent field guide.
- [learning-boundary.md](learning-boundary.md): Learning ownership and the governed return loop.
- [handoff.md](handoff.md): bounded handoff from current-session context into a durable task owner.
- [lifecycle.md](lifecycle.md): cross-package lifecycle ownership and the ordinary host contract.

Add workflow documents here only when they explain an end-to-end user/agent journey, recovery path, or authority boundary. Put package assets such as skills, prompt manifests, and host hooks under `resources/`, even when the asset itself is Markdown.
