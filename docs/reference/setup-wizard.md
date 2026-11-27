# Setup wizard (read-only, rerunnable)

`agent-loop setup` is the canonical name of the repository setup commands. `agent-loop init` remains a compatible alias: every subcommand, option and result is identical, so existing scripts keep working (help and usage text name the command you typed). No deprecation is scheduled.

```bash
vendor/bin/agent-loop setup wizard --format=json
vendor/bin/agent-loop setup wizard --answers=setup-answers.json --format=json
```

The wizard turns the existing [project-aware setup evidence](project-aware-init.md) into a versioned question contract. It never writes a file, never runs a project script and never calls a model, so it is safe to run again at any time. The same evidence, answers, project config and host session give byte-identical output.

## Question contract (`agent-loop.setup-wizard`, version 1)

Every run asks the core questions in this order. The two check questions are asked only when their evidence exists; otherwise they appear under `skipped` with a reason and keep their position number.

| Order | ID | Applies to | Kind | Asked when |
| --- | --- | --- | --- | --- |
| 1 | `map.languages` | project | `multi_choice` (`php`, `typescript`) | always |
| 2 | `map.paths` | project | `path_list` | always |
| 3 | `checks.composer_scripts` | project | `multi_choice` | `composer.json` declares validation-like scripts |
| 4 | `checks.package_scripts` | project | `multi_choice` | `package.json` declares validation-like scripts |
| 5 | `prompting.language` | project | `bcp47` | always |
| 6 | `personal.language` | personal | `bcp47` or `inherit` | always |

Each question has `default` and `default_source` (the evidence the default comes from), plus `current` and `current_source` (what is in effect now and where it comes from). Check questions offer only script names that are actually declared, and default to none. A declared script name is not proof that the script is safe to run. `evidence_sha256` is the `init plan` evidence digest.

Adding, removing or reordering a question requires a new schema version. Conditional questions depend only on recorded evidence.

## Answers

```json
{
  "version": 1,
  "answers": {
    "map.languages": ["php", "typescript"],
    "prompting.language": "de",
    "personal.language": "en"
  }
}
```

The wizard validates the answers file. An unknown question ID, an answer to a skipped question, a value outside the offered options, a path that is not an existing project-relative directory, or an invalid language tag fails with `[FAIL] setup wizard: …` and exit code 1. The output then adds:

- `answers`: the normalized answers, in question order (always a JSON object, `{}` when empty);
- `changes`: each persisted value that would differ from its current value, with `before`, `before_source`, `after`, `requires_confirmation: true` and an `apply` route. If every answer matches the current value, `changes` is empty. A route whose command is not yet runnable has `status: prerequisite_required`;
- `validation_candidates`: selected check scripts, always `execution: not_authorized`;
- `next_actions`: for `map.paths`, the shell-quoted `map build` command, which still needs confirmation. It uses the answered `map.languages`, else the configured value, else the proposal, and passes `--languages` whenever that differs from what `map build` would otherwise use (the configured languages, or `php`). Paths must be plain project-relative directories (`[A-Za-z0-9._/-]`) that resolve inside the project.

| Change | Apply route |
| --- | --- |
| `prompting.language` | `supported`: the existing evidence-guarded `setup apply --language=… --evidence-sha256=… --accept=prompting.language` (with a `--dry-run` preview). The digest is bound to the requested language. Without `.agent-loop/`, the route is `prerequisite_required`: run `setup scaffold`, then rerun the wizard, because scaffolding changes the evidence the digest covers. The route is also `prerequisite_required` while `init.json` has validation warnings, which `setup apply` refuses. |
| `map.languages` | `manual_review`: the exact `.agent-loop/init.json` fragment to add. There is deliberately no generic config writer. |
| `personal.language` | `supported`: `workflow interaction <host-session-id> --language=…` in a bound host session, stored in the user's own state directory ([interaction preference](../workflow/interaction-preference.md)). Without a session, the route is `host_session_required`; without a saved session mode, the route is `prerequisite_required` and names the mode command, with the language command under `then`. |

## Project defaults and personal languages

`prompting.language` is the project's shared default. It is tracked in `.agent-loop/init.json` and changed only through `setup apply`. `personal.language` belongs to one developer's host session. It never touches tracked configuration, so two developers can work in German and English on the same repository. For decision cards, a session language takes precedence over the project language (`WorkflowPresentationLanguage`). A personal language is currently **session-local**: a new host session inherits the shared default mode but not the language, so it starts from the project language again. Persistence across sessions, and the interactive one-question-at-a-time flow on top of this contract, are tracked in [#1195](https://github.com/voku/agent-loop/issues/1195). Machine-readable tokens, commands, paths and recorded decisions stay exact in every language.
