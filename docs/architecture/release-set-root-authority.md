# Consumer release-set root authority

`voku/agent-loop` owns the compatible runtime set of `voku/agent-*` packages that it requires.

A consumer that installs `agent-loop` as repository-local tooling should therefore constrain `voku/agent-loop` directly and let Composer resolve these owned siblings through that package:

- `voku/agent-kanban`
- `voku/agent-learning`
- `voku/agent-map`
- `voku/agent-recall-compiler`
- `voku/agent-session`

Do not repeat those sibling constraints in the consumer root merely to record which versions a replay used. That creates a second release-set authority and can make a historical or candidate `agent-loop` impossible to install when the consumer's copied pins move ahead independently.

The resolved sibling versions belong in evidence: retain `composer.lock`, the resolved package list, or explicit expected resolved versions. Constraints and evidence answer different questions.

This does not forbid a project from directly requiring a package for its own independent product/tooling reason. In that case the project is intentionally adding another compatibility constraint and must treat it as such, rather than describing it as passive release provenance.

The installed release-set dogfood keeps `voku/agent-loop` as the only direct `agent-*` requirement in its clean consumer. `ReleaseSetDogfoodFixtureTest` guards that shape so future fixture changes cannot silently turn the consumer into another release-set owner.
