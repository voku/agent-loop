# Evidence-backed no production change

Use this for real upstream/fork adaptation tasks where the requested capability may already exist in the target. It specializes `real-issue-acceptance.md`; it does not add a workflow phase or require a diff.

Freeze the external delta and target revision before deciding whether work is missing. For this task type, `NO_EXISTING_IMPLEMENTATION` and `ACTIONABLE_DELTA_REMAINS` are not hard gates before equivalence is checked. Those gates remain useful for defect replay, but applying them here biases the run toward mutation.

A successful `NO_PRODUCTION_CHANGE_REQUIRED` result needs frozen external behavior and intent, an existing target behavior that satisfies that intent, deterministic evidence that exercises it, relevant validation, and an explanation of why copying the external implementation shape adds no required behavior. A similarly named method is not enough.

Compare observable behavior and constraints, not file shape or method count. Documentation can support public API evidence but does not replace executable proof.

## Frozen benchmark: Simple-PHP-Code-Parser #105

`tests/fixtures/real-issue-no-change/simple-php-code-parser-105.json` is derived from `voku/Simple-PHP-Code-Parser#105`, Actions run `31659131414`. The frozen upstream change added mutable `ParserContainer::setAst()/getAst()` state. The target already exposed resolved AST through `PhpCodeParser::getAstFromString()` / `getAstFromFile()` with regression coverage, so the correct result was `NO_PRODUCTION_CHANGE_REQUIRED`.

The fixture keeps decisive provenance and structural evidence, not the complete historical map. Its expected decision is benchmark data, not a new runtime enum or lifecycle status. Do not invent a wider outcome taxonomy until several real adaptation runs demonstrate that it is useful.
