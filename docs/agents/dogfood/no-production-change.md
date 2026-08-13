# Evidence-backed no production change

Use this for real upstream/fork adaptation tasks where the requested capability may already exist in the target. It specializes `real-issue-acceptance.md`; it does not add a workflow phase or require a diff.

## Adaptation pre-screen

Freeze the external delta and target revision first. Record:

```text
REAL_REQUEST
FROZEN_EXTERNAL_DELTA
FROZEN_TARGET
BEHAVIORAL_INTENT_IDENTIFIED
```

For this task type, do not use `NO_EXISTING_IMPLEMENTATION` or `ACTIONABLE_DELTA_REMAINS` as hard gates. Those are useful for defect replay, but they bias an adaptation task toward code before equivalence is checked.

Search the frozen target for the requested capability and supporting evidence. If a behavioral gap is demonstrated, continue through the ordinary implementation path. If the target already provides the behavior, the run may close as `NO_PRODUCTION_CHANGE_REQUIRED` after verification.

A similarly named method is not enough. A no-change result needs:

- the frozen external behavior and its intent;
- the existing target behavior that satisfies that intent;
- deterministic tests or equivalent evidence;
- relevant project validation;
- an explanation of why copying the external implementation shape adds no required behavior;
- an explicit no-production-change decision.

Compare behavior and constraints, not file shape or method count. Documentation is useful for public API evidence, but it does not replace executable proof.

## Frozen benchmark: Simple-PHP-Code-Parser #105

`tests/fixtures/real-issue-no-change/simple-php-code-parser-105.json` is derived from `voku/Simple-PHP-Code-Parser#105`, Actions run `31659131414`.

The frozen upstream change added mutable `ParserContainer::setAst()/getAst()` state. The target already exposed resolved AST through `PhpCodeParser::getAstFromString()` / `getAstFromFile()` with regression coverage, so the correct result was `NO_PRODUCTION_CHANGE_REQUIRED`.

The fixture keeps only decisive provenance and structural evidence, not the complete historical map. Its expected decision is benchmark data, not a new runtime enum or lifecycle status.

Do not invent a wider outcome taxonomy until several real adaptation runs demonstrate that it is useful.
