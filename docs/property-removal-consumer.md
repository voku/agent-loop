# Property removal consumer

`agent-loop edit refactor` accepts `property_removal_plan@1.0` as a fixed governed mutation contract.

The consumer remains deliberately narrow:

- only `status=safe` plans are accepted;
- stale evidence, blockers, blind spots, moves, unsupported roles, non-empty replacements, non-PHPStan resolution, invalid hashes/ranges and mismatched target identities fail closed;
- provenance must match the current PHPStan-backed Map exactly;
- source hashes and expected bytes are revalidated immediately before publication;
- rewritten PHP is staged and syntax-checked before any source file is replaced;
- publication is transactional and restores every source file on failure;
- verification requires the persisted plan binding, exact independently observed changed-file scope, a current PHPStan-backed Map, rewritten source hashes, and absence of the removed property declaration relation.

This does not introduce a generic edit-plan or Rector execution boundary. Property removal keeps its own decoder, applier and verifier alongside the existing method-removal consumer.
