# Setup wizard (rerunnable, deterministic)

`agent-loop setup` is the canonical name of the repository setup commands. `agent-loop init` remains a compatible alias: every subcommand, option and result is identical, so existing scripts keep working (help and usage text name the command you typed). No deprecation is scheduled.

```bash
vendor/bin/agent-loop setup wizard --interactive                                  # a person in a terminal
vendor/bin/agent-loop setup wizard --next --answers=partial.json --format=json   # a coding host, one step at a time
vendor/bin/agent-loop setup wizard --answers=setup-answers.json --format=json    # all answers at once
vendor/bin/agent-loop setup wizard --format=json                                  # just the questions
```

The wizard turns the existing [project-aware setup evidence](project-aware-init.md) into a versioned question contract. All four forms use the same questions, the same validation and the same result. It never writes a file, never runs a project script and never calls a model, so it is safe to run again at any time. The same evidence, answers, project config and user preferences give byte-identical output.

## Question contract (`agent-loop.setup-wizard`, version 2)

Every run asks the core questions in this order. The two check questions are asked only when their evidence exists; otherwise they appear under `skipped` with a reason and keep their position number.

| Order | ID | Applies to | Kind | Asked when |
| --- | --- | --- | --- | --- |
| 1 | `map.languages` | project | `multi_choice` (`php`, `typescript`) | always |
| 2 | `map.paths` | project | `path_list` | always |
| 3 | `checks.composer_scripts` | project | `multi_choice` | `composer.json` declares validation-like scripts |
| 4 | `checks.package_scripts` | project | `multi_choice` | `package.json` declares validation-like scripts |
| 5 | `prompting.language` | project | `bcp47` | always |
| 6 | `personal.language` | personal (your default for every host session) | `bcp47` or `inherit` | always |

Each question has `default` and `default_source` (the evidence the default comes from), plus `current` and `current_source` (what is in effect now and where it comes from). Check questions offer only script names that are actually declared, and default to none. A declared script name is not proof that the script is safe to run. `evidence_sha256` is the `init plan` evidence digest.

Adding, removing or reordering a question, or changing what one means, requires a new schema version. Version 2 changed `personal.language` from the current host session's language to your default language for every session. Conditional questions depend only on recorded evidence.

## One question at a time

`--interactive` asks each question in contract order. It shows the current value and the recommendation, each with its source, the options, and what the answer touches. Then it reads one line:

| Input | Meaning |
| --- | --- |
| Enter | keep the current value, or take the recommendation when there is nothing to keep |
| `k` / `keep` | keep the current value |
| `r` / `recommended` | take the evidence-backed recommendation |
| `s` / `skip` | skip an optional question; nothing is proposed for it |
| anything else | change to this value (comma-separated for lists) |

The words `k`, `keep`, `r`, `recommended`, `s` and `skip` are reserved. To answer `map.paths` with a directory of one of those names, add a trailing slash (`s/`); the slash is dropped from the stored path.

An invalid answer is explained and asked again. Prompts go to standard error. When the last question is answered, standard output gets the `--answers` result for the same answers, plus `progress`, `step: null` and an `answers_document` you can save and pass to `--answers` later. If input ends before the last question, the run exits 1 and changes nothing, so a pipe or CI job never waits.

For a coding host, `--next` is the same flow without a terminal. Pass the answers given so far. The output has `progress` and a `step`: the first question in contract order that has no answer yet, with its `actions` (`keep`, `recommended`, `change` and, for optional questions, `skip`, each with the value it resolves to and its effect) and its `consequence`. Once every asked question has an answer, `step` is `null` and the output is the full `--answers` result plus `progress`. The `recommended` effect is derived from what that answer would actually propose: it says `No change.` when the recommendation proposes no change, command or validation candidate, for example `php` for an unconfigured `map.languages`, which `map build` already indexes. Loop decides the order and the next question; the host relays the question, may explain it, and adds the person's answer. It never picks the next question or answers for the person. The same partial answers always give the same step.

## Answers

```json
{
  "version": 2,
  "answers": {
    "map.languages": ["php", "typescript"],
    "map.paths": {"action": "recommended"},
    "checks.composer_scripts": {"action": "skip"},
    "prompting.language": {"action": "keep"},
    "personal.language": "en"
  }
}
```

An answer is a value (meaning change to it) or an action: `{"action": "keep"}`, `{"action": "recommended"}`, `{"action": "skip"}` or `{"action": "change", "value": …}`. `keep` needs a current value, and `skip` only works on optional questions. Version 1 answers files are still read; their `personal.language` now sets your default language, so a version 1 file that sets it gets a `[WARN] setup wizard:` entry in `warnings`. Write version 2 to confirm the new meaning. Language tags are case-insensitive and stored lower-cased (`de-DE` becomes `de-de`), as `prompting.language` always was.

The wizard validates the answers file. An unknown question ID, an answer to a skipped question, a value outside the offered options, a path that is not an existing project-relative directory, or an invalid language tag fails with `[FAIL] setup wizard: …` and exit code 1. The output then adds:

- `answers`: the normalized answers, in question order (always a JSON object, `{}` when empty). `keep` and `recommended` appear as the value they resolved to; a skipped question appears as `{"action": "skip"}`;
- `changes`: each persisted value that would differ from its current value, with `before`, `before_source`, `after`, `requires_confirmation: true` and an `apply` route. If every answer matches the current value, `changes` is empty. A route whose command is not yet runnable has `status: prerequisite_required`;
- `validation_candidates`: selected check scripts, always `execution: not_authorized`;
- `next_actions`: for `map.paths`, the shell-quoted `map build` command, which still needs confirmation. It uses the explicitly answered `map.languages`, otherwise the effective configured languages (or the existing `php` fallback). The detected recommendation is not applied unless answered. If the declaration is invalid and no language is answered, the action reports `prerequisite_required` without a command. It passes `--languages` when an explicit answer differs from the effective setting or repairs an invalid declaration. Paths must be plain project-relative directories (`[A-Za-z0-9._/-]`) that resolve inside the project.

| Change | Apply route |
| --- | --- |
| `prompting.language` | `supported`: the existing evidence-guarded `setup apply --language=… --evidence-sha256=… --accept=prompting.language` (with a `--dry-run` preview). The digest is bound to the requested language. Without `.agent-loop/`, the route is `prerequisite_required`: run `setup scaffold`, then rerun the wizard, because scaffolding changes the evidence the digest covers. The route is also `prerequisite_required` while `init.json` has validation warnings, which `setup apply` refuses. |
| `map.languages` | `manual_review`: the exact `.agent-loop/init.json` fragment to add. There is deliberately no generic config writer. |
| `personal.language` | `supported`: `workflow interaction --default-language=…`, stored with the shared default in your own user state directory ([interaction preference](../workflow/interaction-preference.md)). Without a shared default mode, the route is `prerequisite_required`: it names the mode command, and the language command is under `then`. Without a known user state directory, the route is also `prerequisite_required`. |

## Project defaults and personal languages

`prompting.language` is the project's shared default. It is tracked in `.agent-loop/init.json` and changed only through `setup apply`. `personal.language` is your own default language. It lives in your user state directory, follows you into every new host session and never touches tracked configuration, so two developers can work in German and English on the same repository. For decision cards the order is: a language set for the current session only (`workflow interaction <id> --language=…`), then your default language, then the project language (`WorkflowPresentationLanguage`). When the current session has its own language, the question shows it as `session_override`. Machine-readable tokens, commands, paths and recorded decisions stay exact in every language. Wizard prompts are in English.
