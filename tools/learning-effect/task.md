Task id: REL-001

Our marker-driven release of {version} was refused. The `Release tag` workflow (`.github/workflows/release-tag.yml`) stopped with:

    Release target {target} does not contain the {version} changelog section

`CHANGELOG.md` at commit {target} does contain the `{changelog_heading}` section.

Make the workflow tag a valid release, keep refusing a release target whose `CHANGELOG.md` lacks the section, and add an automated regression test to this repository's test suite. Do not create tags, push, or open pull requests. Finish by summarising what you changed and how you verified it.
