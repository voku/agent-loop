# Pre-1.0 compatibility policy

Status: accepted project-wide policy for the current `agent-loop` release line.

`agent-loop` and its first-party `agent-*` packages are still before 1.0. Backward
compatibility is therefore not the default goal when it would preserve duplicate
ownership, a false-green path, or a legacy layer that exists only for another
first-party package in the same coordinated release set.

For an affected first-party contract:

1. identify the owning package and every in-scope first-party consumer;
2. prefer changing the owner and consumers together, updating dependency
   constraints, and deleting the obsolete path;
3. prove the coordinated migration through package tests and the installed
   release-set gate;
4. accept an alias, adapter, fallback, dual-read, or legacy layer only when a
   verified external consumer or an explicit compatibility promise requires it.

Breaking change is not permission for speculative redesign. The change must
remove a demonstrated contradiction, defect, duplicate rule, or wrong ownership
boundary. Historical evidence remains readable, and unrelated public contracts
remain out of scope.

If a narrower directory or module policy conflicts with this project-wide
default, stop and surface the conflict instead of guessing which contract wins.
