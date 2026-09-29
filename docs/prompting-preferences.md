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

`language` accepts a BCP 47 language tag such as `en`, `de`, or `de-DE`. It defaults to `en`. The setting governs explanatory prose in the workflow prompt envelope and `workflow context` projection. Source text, technical names, paths, commands, citations, and machine-readable tokens stay exact. It does not translate persisted Contract, audit, or Recall artifacts.

`blindspots.tone` is `measured` (default), `direct`, or `unflinching`. Direct asks reviewers to state evidenced flaws and consequences plainly. Unflinching asks for forceful, uncomfortable candor about verified failures. Neither mode permits invented evidence, attributed motives, or attacks on people. Add an optional one-line focus per review with `agent-loop review blindspots TASK-1 --focus 'rollback after timeout'`.

The `workflow context <task-id> --format=json` projection includes the normalized `prompting` settings for governed tasks. Generated workflow and review prompts carry the language preference. The installed `agent-loop-blindspot-review` skill and reviewer use the settings for semantic review. An untracked review may read the same settings directly from `.agent-loop/init.json`. Invalid values produce a warning and fall back to the defaults.

These preferences never change deterministic audit status, evidence requirements, lifecycle decisions, or human authority. A project-wide config should not store private fears or sensitive review details; supply a task focus in the request or governed task artifacts instead.
