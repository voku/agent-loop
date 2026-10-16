# Git hooks: where the commit rules live

The Git hooks in a repository that uses `voku/agent-loop` are thin wrappers. The
logic is in the package and the rules are data in one project file. Knowing that
saves a long search when a commit is rejected or a rule has to change.

```text
git commit
  -> core.hooksPath/commit-msg          generated wrapper, do not edit
     -> bin|vendor/bin/agent-loop githooks commit-msg <message-file>
        -> .agent-loop/githooks.json    the rules: edit this
```

| I want to ... | Change ... |
| --- | --- |
| Change the header format, forbid or require text in a message | `commit_msg` in `.agent-loop/githooks.json` |
| Change which checks run before a commit | `pre_commit.checks` in `.agent-loop/githooks.json` |
| Change how the hooks run (container, paths) | `.agent-loop/init.json`, then `init sync-githooks` |
| Fix a wrapper script in the hooks directory | Nothing by hand: `init sync-githooks` regenerates it |
| Change the logic that applies the rules | The package (`src/GitHooks/`), with a test |

A project skill or instruction file that is installed into an agent directory
(for example under a dot-directory that is git-ignored) is a generated copy.
Edit the tracked source and re-run the matching `init sync-*` command; see
[agent-assets.md](agent-assets.md).

Without `.agent-loop/githooks.json` both hooks do nothing.

## `commit_msg` keys

| Key | Type | Meaning |
| --- | --- | --- |
| `header_pattern` | PCRE string | The first line must match. Empty means no header rule. |
| `header_hint` | string | Shown after a header violation, for example `type(scope): subject`. |
| `trivial_header_pattern` | PCRE string | Headers that match are exempt from the required section. Forbidden patterns still apply. |
| `forbidden_patterns` | list of `{pattern, message}` | Every pattern is matched against the whole message Git will store. A match blocks the commit and prints `message`. |
| `required_section` | string | Name of a section (`WHY` matches `WHY:` at line start) that non-trivial commits must contain with real content. |
| `vague_phrases` | list of strings | Phrases that make a short section count as vague. |
| `vague_word_threshold` | int | A section with this many words or more is never called vague (default 8). |

Evaluation order: header, then forbidden patterns, then required section, then
vagueness. The first group that fails is reported on its own, so fixing one
problem can reveal the next.

What is matched is the message **after** Git's cleanup: commentary lines that Git
removes under `commit.cleanup=strip` are not seen, lines Git keeps are. See
`GitCommitCleanup` for the exact rules, including `core.commentChar`.

## Examples

All patterns are JSON strings, so every backslash is doubled in the file. The
examples below show the decoded pattern; the file form is in the last block.

### A conventional-commit header with a required reason

```json
{
  "commit_msg": {
    "header_pattern": "/^(feat|fix|docs|refactor|test|chore)(\\([a-z0-9-]+\\))?!?: .+$/",
    "header_hint": "type(scope): subject",
    "trivial_header_pattern": "/^(docs|chore)/",
    "required_section": "WHY",
    "vague_phrases": ["cleanup", "minor fixes"]
  }
}
```

### Reject attribution trailers that AI tools add

Agent clients often append a co-author trailer, a session link or a "generated
with" line. A repository that does not want them says so once:

```json
{
  "commit_msg": {
    "forbidden_patterns": [
      {
        "pattern": "/^\\s*Co-Authored-By:.*(claude|copilot|gemini|codex|openai|anthropic)/im",
        "message": "Remove the AI co-author trailer; this repository records the reason in a WHY section instead."
      },
      {
        "pattern": "/^\\s*[A-Za-z-]*Session:\\s*https?:\\/\\//im",
        "message": "Remove the session link."
      },
      {
        "pattern": "/Generated with .{0,30}(Claude Code|Copilot|Codex)/i",
        "message": "Remove the generated-with line."
      }
    ]
  }
}
```

The first pattern is limited to tool names on purpose: a trailer naming a human
co-author still passes. Put the repository's reason in `message`, because the
agent reads it right when the commit is refused.

### Require a footer with a forbidden pattern

`forbidden_patterns` can only reject, but a negative lookahead turns "must
contain" into "must not lack":

```json
{
  "pattern": "/\\A(?!.*^Refs: [A-Z]+-\\d+)/ms",
  "message": "Add a footer line such as `Refs: PROJ-123`."
}
```

## How the examples were checked

The cases below were run through `CommitMessageValidator` built from exactly
these settings (conventional header, `WHY` section, the three trailer patterns
and the footer pattern):

| Message | Result |
| --- | --- |
| valid header, `WHY` bullet, `Refs: PROJ-12` | passes |
| same without the `Refs:` footer | blocked by the footer pattern |
| same plus an AI co-author trailer | blocked |
| same plus a `Session:` link | blocked |
| same plus a "Generated with ... Claude Code" line | blocked |
| same plus a human `Co-Authored-By:` | passes |
| header `Fixed things` | blocked by `header_pattern` |
| `docs(readme): fix typo` without `WHY`, with `Refs:` | passes (trivial header) |
| `WHY:` with the single word `cleanup` | blocked as vague |

To test a rule before committing, write a message to a file and call the hook
directly; the exit code is 0 for a pass and non-zero for a block:

```bash
vendor/bin/agent-loop githooks commit-msg /path/to/message.txt
```

Put the file in a directory you own (for example one from `mktemp -d`); a fixed
name in a shared `/tmp` can belong to another user or session.

## Limits

- The hook runs only where `core.hooksPath` points at the generated hooks
  directory. `git commit --no-verify` and plumbing commands skip it, so a rule
  that must hold needs the same check in CI.
- It validates commits, not merge request or pull request text. Descriptions that
  GitLab or GitHub generate from the commit messages inherit what the commits
  contain.
- Patterns are PCRE and run on the cleaned message; test any new one against a
  passing and a failing message before relying on it.
