from pathlib import Path

path = Path('tests/WorkflowPlanCommandTest.php')
text = path.read_text()
old = "self::assertSame('.agent-loop/todo/cards/ABC-123.md', $kanbanContext['source']['path'] ?? null);"
new = "self::assertSame('todo/cards/ABC-123.md', $kanbanContext['source']['path'] ?? null);"
if text.count(old) != 1:
    raise SystemExit(f'expected one source-path assertion, found {text.count(old)}')
path.write_text(text.replace(old, new, 1))
