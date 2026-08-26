from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    file = Path(path)
    text = file.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"expected exactly one match in {path}, found {count}")
    file.write_text(text.replace(old, new, 1))


replace_once(
    "src/Workflow/WorkflowRunPreparer.php",
    """        $kanbanContext = (new WorkflowKanbanContextWriter($this->rootPath))->write($contract->taskId, $session);\n        if ($kanbanContext !== null) {\n            $recallArgs[] = '--kanban-context';\n            $recallArgs[] = $kanbanContext;\n        }\n""",
    """        $kanbanContext = (new WorkflowKanbanContextProjector($this->rootPath))->project($contract->taskId);\n        if ($kanbanContext !== null) {\n            $recallArgs[] = '--kanban-context';\n            $recallArgs[] = json_encode(\n                $kanbanContext->toArray(),\n                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,\n            );\n        }\n""",
)

replace_once(
    "src/Workflow/WorkflowHandoffCommand.php",
    """            $kanbanContext = (new WorkflowKanbanContextWriter($this->rootPath))->write($taskId->value, $session);\n            if ($kanbanContext !== null) {\n                $recallArgs[] = '--kanban-context';\n                $recallArgs[] = $kanbanContext;\n            }\n""",
    """            $kanbanContext = (new WorkflowKanbanContextProjector($this->rootPath))->project($taskId->value);\n            if ($kanbanContext !== null) {\n                $recallArgs[] = '--kanban-context';\n                $recallArgs[] = json_encode(\n                    $kanbanContext->toArray(),\n                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,\n                );\n            }\n""",
)

replace_once(
    "tests/WorkflowPlanCommandTest.php",
    """            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');\n            self::assertCount(1, $sessions);\n            $contextPath = $sessions[0]->path . '/kanban-context.json';\n            $recallInput = $root . '/.agent-loop/runs/ABC-123/recall-input.json';\n            self::assertFileExists($contextPath);\n            self::assertFileExists($recallInput);\n            self::assertSame([[\n                'compile', '--root', $learningRoot,\n                '--task', 'ABC-123', '--task-brief', $recallInput,\n                '--document-manifest', $root . '/docs/agents/recall-documents.json',\n                '--document-manifest', $learningRoot . '/recall-documents.json',\n                '--kanban-context', $contextPath,\n                '--map-index', $root . '/.agent-loop/map/php-symbols.json', '--map-root', $root,\n                '--map-search-index', $root . '/.agent-loop/map/search.sqlite',\n            ]], $recallCalls);\n""",
    """            $sessions = (new SessionStore())->all($root . '/.agent-loop/sessions');\n            self::assertCount(1, $sessions);\n            self::assertFileDoesNotExist($sessions[0]->path . '/kanban-context.json');\n            $recallInput = $root . '/.agent-loop/runs/ABC-123/recall-input.json';\n            self::assertFileExists($recallInput);\n            self::assertCount(1, $recallCalls);\n            $kanbanContextIndex = array_search('--kanban-context', $recallCalls[0], true);\n            self::assertIsInt($kanbanContextIndex);\n            $kanbanContextJson = $recallCalls[0][$kanbanContextIndex + 1] ?? null;\n            self::assertIsString($kanbanContextJson);\n            $kanbanContext = json_decode($kanbanContextJson, true, 512, JSON_THROW_ON_ERROR);\n            self::assertIsArray($kanbanContext);\n            self::assertSame('ABC-123', $kanbanContext['task_id'] ?? null);\n            self::assertSame('.agent-loop/todo/cards/ABC-123.md', $kanbanContext['source']['path'] ?? null);\n            self::assertSame(\n                ['title', 'lane', 'status', 'priority', 'next_action'],\n                array_keys($kanbanContext['card'] ?? []),\n            );\n            self::assertSame([[\n                'compile', '--root', $learningRoot,\n                '--task', 'ABC-123', '--task-brief', $recallInput,\n                '--document-manifest', $root . '/docs/agents/recall-documents.json',\n                '--document-manifest', $learningRoot . '/recall-documents.json',\n                '--kanban-context', $kanbanContextJson,\n                '--map-index', $root . '/.agent-loop/map/php-symbols.json', '--map-root', $root,\n                '--map-search-index', $root . '/.agent-loop/map/search.sqlite',\n            ]], $recallCalls);\n""",
)
