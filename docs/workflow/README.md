# Workflow documentation

These documents explain the current human/AI workflow. They do not implement lifecycle ordering or mutation authority; the canonical lifecycle result remains authoritative.

Current workflow documents:

- [`learning-boundary.md`](learning-boundary.md): Learning ownership and the governed return loop.
- [`handoff.md`](handoff.md): bounded handoff from current-session context into a durable task owner.

Add workflow documents here when they explain an end-to-end user/agent journey, recovery path, or authority boundary. Put package assets such as skills, prompt manifests, and host hooks under `resources/` instead, even when the asset itself is Markdown.
