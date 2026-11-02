#!/usr/bin/env python3
"""Unit regressions for the Codex JSONL command accounting boundary."""

import importlib.util
from pathlib import Path
import unittest


SOURCE = Path(__file__).with_name("codex-local-model-behavior.py")
SPEC = importlib.util.spec_from_file_location("local_model_behavior", SOURCE)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Cannot load the local-model harness")
HARNESS = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(HARNESS)


def command_event(phase, identifier, command, *, status=None, exit_code=None):
    return {
        "type": phase,
        "item": {
            "id": identifier,
            "type": "command_execution",
            "command": command,
            "status": status,
            "exit_code": exit_code,
        },
    }


class CommandAccountingTest(unittest.TestCase):
    def test_started_and_completed_for_one_id_count_once(self):
        events = [
            command_event("item.started", "item_1", "pwd", status="in_progress"),
            command_event("item.completed", "item_1", "pwd", status="completed", exit_code=0),
        ]
        commands = HARNESS.summarize_command_executions(events)
        self.assertEqual(1, len(commands))
        self.assertTrue(commands[0]["succeeded"])

    def test_failed_command_is_observed_but_not_a_successful_canary(self):
        commands = HARNESS.summarize_command_executions([
            command_event("item.started", "item_2", "pwd", status="in_progress"),
            command_event("item.completed", "item_2", "pwd", status="failed", exit_code=1),
        ])
        self.assertEqual(1, len(commands))
        self.assertFalse(commands[0]["succeeded"])

    def test_separate_ids_keep_distinct_sanitized_attempts(self):
        commands = HARNESS.summarize_command_executions([
            command_event("item.started", "item_1", "agent-loop enter task"),
            command_event("item.started", "item_2", "php test.php"),
            command_event("item.completed", "item_1", "agent-loop enter task", status="completed", exit_code=0),
            command_event("item.completed", "item_2", "php test.php", status="completed", exit_code=0),
        ])
        self.assertEqual(2, len(commands))
        self.assertEqual(1, sum(int(command["enter"]) for command in commands))
        self.assertEqual(1, sum(int(command["php_test"]) for command in commands))

    def test_malformed_ids_and_irrelevant_events_are_not_commands(self):
        events = [
            command_event("item.updated", "item_1", "pwd"),
            command_event("item.completed", "", "pwd", status="completed", exit_code=0),
            {"type": "item.completed", "item": {"type": "agent_message", "text": "pwd"}},
        ]
        self.assertEqual([], HARNESS.summarize_command_executions(events))


if __name__ == "__main__":
    unittest.main()
