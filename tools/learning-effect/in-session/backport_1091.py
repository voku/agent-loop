#!/usr/bin/env python3
"""Backport agent-loop#1091 (precedent visibility) into a baseline-85c53c3 workspace.

usage: backport_1091.py <repo>
Copies WorkflowLearningPrecedentProjector from the #1091 branch and wires it into the
baseline's enter (JSON + text) and workflow plan (text) at the equivalent points.
"""
import sys
from pathlib import Path

repo = Path(sys.argv[1])
src = Path("/home/user/agent-loop/src/Workflow/WorkflowLearningPrecedentProjector.php").read_text()
src = src.replace(" * In the learning-effect experiment (docs/dogfood/2026-11-19-learning-effect-round2.md)\n", " * In a learning-effect experiment\n")
(repo / "src/Workflow/WorkflowLearningPrecedentProjector.php").write_text(src)

def patch(path, old, new):
    p = repo / path
    t = p.read_text()
    assert t.count(old) == 1, (path, old[:60])
    p.write_text(t.replace(old, new))

patch("src/Workflow/HostFrontDoorCommand.php",
"""        if ($preparationFailure !== null) {
            $payload['blockers'] = [[
                'code' => 'enter.preparation_failed',""",
"""        $learningPrecedents = (new WorkflowLearningPrecedentProjector($this->rootPath))->project($taskId->value);
        if ($learningPrecedents !== null) {
            $payload['learning_precedents'] = $learningPrecedents;
        }
        if ($preparationFailure !== null) {
            $payload['blockers'] = [[
                'code' => 'enter.preparation_failed',""")
patch("src/Workflow/HostFrontDoorCommand.php",
"""            $this->printCandidateApprovalSubject($taskId->value, $policy->nextActionKind);
            echo ($policy->nextActionKind === RunPolicyEvaluation::KIND_HOST_WORK ? 'Next (your change): ' : 'Next: ')""",
"""            $this->printCandidateApprovalSubject($taskId->value, $policy->nextActionKind);
            if ($learningPrecedents !== null) {
                echo (new WorkflowLearningPrecedentProjector($this->rootPath))->renderText($learningPrecedents);
            }
            echo ($policy->nextActionKind === RunPolicyEvaluation::KIND_HOST_WORK ? 'Next (your change): ' : 'Next: ')""")
patch("src/Workflow/WorkflowPlanCommand.php",
"""        if ($options['format'] === 'json') {
            echo json_encode([
                'schema_version' => '1.0',
                'command' => 'workflow plan',""",
"""        $learningPrecedentProjector = new WorkflowLearningPrecedentProjector($this->rootPath);
        $learningPrecedents = $learningPrecedentProjector->project($taskId->value);
        if ($options['format'] === 'json') {
            echo json_encode([
                'schema_version' => '1.0',
                'command' => 'workflow plan',""")
patch("src/Workflow/WorkflowPlanCommand.php",
"""                'next_action_kind' => 'decision_required',
            ], JSON_PRETTY_PRINT""",
"""                'next_action_kind' => 'decision_required',
            ] + ($learningPrecedents !== null ? ['learning_precedents' => $learningPrecedents] : []), JSON_PRETTY_PRINT""")
patch("src/Workflow/WorkflowPlanCommand.php",
"""        echo "Scope:\\n  " . implode("\\n  ", $contract->scope) . "\\n";
        echo "Next:\\n";""",
"""        echo "Scope:\\n  " . implode("\\n  ", $contract->scope) . "\\n";
        if ($learningPrecedents !== null) {
            echo $learningPrecedentProjector->renderText($learningPrecedents);
        }
        echo "Next:\\n";""")
print("backported")
