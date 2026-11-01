# Prompting presentation preferences

Projects can set response language and blind-spot review presentation in `.agent-loop/init.json`:

```json
{
  "prompting": {
    "language": "de",
    "blindspots": {
      "tone": "direct"
    }
  }
}
```

The same file can choose the default human decision view:

```json
{
  "interaction": {
    "decision_presentation": "cli"
  }
}
```

`interaction.decision_presentation` accepts `cli` (default) or `html`. For a
Contract approval, `html` prefers the existing agent-ui view when the local
control plane is enabled and reachable. The current HTML decision labels are
English, so another configured language keeps the full overview in the CLI. If
the control plane is unavailable, agent-loop also falls back to the full CLI
decision overview. This preference changes Contract approval presentation.
The CLI decision labels currently support English and German;
unknown configured languages use English labels while preserving exact
Contract text, commands, paths, and owner evidence. The host agent should still
follow `prompting.language` for its own explanation and recommendation.

`language` accepts a BCP 47 language tag such as `en`, `de`, or `de-DE`. It defaults to `en`. The setting governs explanatory prose in the workflow prompt envelope and `workflow context` projection, the CLI decision labels when English or German is configured, and the explanatory prose an agent writes when presenting a workflow decision or execution-pipeline Attention to a developer. For a governed task, use the normalized `prompting` projection from `workflow context <task-id> --format=json`. Source text, technical names, paths, commands, citations, machine-readable tokens, and owner-provided evidence stay exact. The setting does not translate persisted Contract, audit, or Recall artifacts.

`blindspots.tone` is `measured` (default), `direct`, or `unflinching`. Direct asks reviewers to state evidenced flaws and consequences plainly. Unflinching asks for forceful, uncomfortable candor about verified failures. Neither mode permits invented evidence, attributed motives, or attacks on people. Add an optional one-line focus per review with `agent-loop review blindspots TASK-1 --focus 'rollback after timeout'`.

The `workflow context <task-id> --format=json` projection includes the normalized `prompting` settings for governed tasks. Generated workflow and review prompts carry the language preference. The installed `agent-loop-blindspot-review` skill and reviewer use the settings for semantic review. An untracked review may read the same settings directly from `.agent-loop/init.json`. Invalid values produce a warning and fall back to the defaults.

These preferences never change deterministic audit status, evidence requirements, lifecycle decisions, or human authority. A project-wide config should not store private fears or sensitive review details; supply a task focus in the request or governed task artifacts instead.
