# Evidence-backed no production change

For real upstream or fork adaptation work, producing no code change can be the correct result when the requested behavior already exists in the target.

Freeze the external delta and target revision before discovery. Compare behavioral intent and constraints, not file shape or method count. If a behavioral gap is demonstrated, continue through the ordinary implementation path. If equivalent target behavior is already proven, the run may conclude `NO_PRODUCTION_CHANGE_REQUIRED`.

A no-change conclusion requires:

- frozen external behavior and intent;
- existing target behavior satisfying that intent;
- deterministic tests or equivalent evidence;
- relevant project validation;
- an explanation of why copying the external implementation adds no required behavior;
- an explicit no-production-change decision.

For adaptation tasks, defect-replay preconditions such as `NO_EXISTING_IMPLEMENTATION` and `ACTIONABLE_DELTA_REMAINS` must not force a code change before equivalence is checked.

## Earned example

`Simple-PHP-Code-Parser#105` exposed this case. The frozen run found existing AST entry points and regression coverage, so the product result was no production change. The same run also exposed a separate governed-context loss: ranked test evidence reached the relevant source, but the decisive existing API did not reach the execution context. That deterministic context defect was fixed in agent-loop #73 and its production-seed-cap boundary is covered by #83.

Those regressions prove the context behavior. This document records the acceptance rule; it does not duplicate the tests or treat a historical outcome label as proof of correctness.

`NO_PRODUCTION_CHANGE_REQUIRED` is an evidence-backed conclusion, not a new lifecycle state. Do not add a broader outcome taxonomy until multiple real tasks demonstrate a need for one.
