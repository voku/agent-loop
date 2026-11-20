#!/usr/bin/env python3
"""Replay the H2 historical regression oracles on pinned source commits.

This is a reproducible *oracle calibration*, not a coding-agent run or a
demonstration that Learning improved an engineering outcome. A candidate test
is copied from the repair commit into the vulnerable baseline, so the same
oracle is executed against both implementations in disposable worktrees.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from typing import Any, Final

H2A_BASE: Final = "fd2989588b0d5c3bd60215c8e19856a278675b2d"
H2A_FIX: Final = "2daacc888743694399ebe007a0ff7037bcde9256"
H2B_BASE: Final = "5f54b84ed46b7f33be8bdc4b33bb3f1e00fae55c"
H2B_FIX: Final = "0866edafef95ef2409998e9960c6135b4c0d0334"

H2A_METHODS: Final = (
    "testContractRevisionDisagreementBlocksFalseGreenProjection",
    "testRunNewerThanTheContractStillRoutesToRepairNotApproval",
)
DIAGNOSTIC: Final = "Project PHPStan rule fixtures must run in an isolated PHPStan process"
H2A_FIXTURE: Final = "tests/RunManifestProjectorTest.php"
H2B_FIXTURE: Final = "tests/PHPStan/data/in-process-phpstan-type-inference-test-case.php"


def run(
    command: list[str],
    cwd: Path,
    log: Path,
    *,
    expected: tuple[int, ...] = (0,),
    timeout: int = 900,
) -> subprocess.CompletedProcess[str]:
    result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, check=False, timeout=timeout)
    log.write_text(
        "exit=" + str(result.returncode) + "\n" + result.stdout + "\n" + result.stderr,
        encoding="utf-8",
    )
    if result.returncode not in expected:
        raise RuntimeError(
            f"Unexpected exit {result.returncode} ({' '.join(command)}); see {log}"
        )
    return result


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def verify_revision(repo: Path, wanted: str, out: Path) -> None:
    got = run(["git", "rev-parse", "HEAD"], repo, out).stdout.strip()
    if got != wanted:
        raise RuntimeError(f"Historical worktree revision mismatch: {got} != {wanted}")


def replay_h2a(base: Path, fixed: Path, evidence: Path) -> dict[str, Any]:
    outcomes: dict[str, Any] = {}
    for name, tree in (("baseline", base), ("repair", fixed)):
        junit = evidence / f"{name}.xml"
        run_result = run(
            [
                str(tree / "vendor/bin/phpunit"),
                "--no-progress",
                "--filter",
                "|".join(H2A_METHODS),
                "--log-junit",
                str(junit),
                H2A_FIXTURE,
            ],
            tree,
            evidence / f"{name}-phpunit.log",
            expected=(0, 1),
        )
        root = ET.parse(junit).getroot()
        tests: dict[str, bool] = {}
        for case in root.iter("testcase"):
            method = case.attrib.get("name", "")
            if method in H2A_METHODS:
                if method in tests:
                    raise RuntimeError(f"Duplicate PHPUnit result: {method}")
                tests[method] = not any(case.find(tag) is not None for tag in ("failure", "error", "skipped"))
        if set(tests) != set(H2A_METHODS):
            raise RuntimeError(f"Missing oracle tests in {name}: {tests}")
        outcomes[name] = {"exit_code": run_result.returncode, "tests": tests}

    first, guard = H2A_METHODS
    expected = (
        outcomes["baseline"]["exit_code"] == 1
        and outcomes["baseline"]["tests"] == {first: False, guard: True}
        and outcomes["repair"]["exit_code"] == 0
        and outcomes["repair"]["tests"] == {first: True, guard: True}
    )
    return {"passed": expected, "outcomes": outcomes}


def replay_h2b(base: Path, fixed: Path, evidence: Path) -> dict[str, Any]:
    def analyse(name: str, tree: Path) -> dict[str, Any]:
        result = run(
            [
                str(tree / "vendor/bin/phpstan"),
                "analyse",
                "--configuration=phpstan/project-rule-test.neon",
                "--no-progress",
                "--error-format=raw",
            ],
            tree,
            evidence / f"{name}-phpstan.log",
            expected=(1,),
        )
        lines = (result.stdout + "\n" + result.stderr).splitlines()
        diagnostic_lines = [line for line in lines if DIAGNOSTIC in line]
        return {
            "exit_code": result.returncode,
            "in_process_diagnostic_count": len(diagnostic_lines),
            "matched_paths": sorted(set(line.split(":", 1)[0] for line in diagnostic_lines)),
        }

    baseline = analyse("baseline", base)
    repair = analyse("repair", fixed)

    # Negative control: the tempting "add TypeInferenceTestCase to the
    # direct-parent deny-list" fix detects the direct and abstract cases but
    # still misses the indirect child. Verify that the oracle rejects it.
    rule_path = base / "phpstan/Rules/NoInProcessPhpstanRuleTestCaseRule.php"
    original = rule_path.read_text(encoding="utf-8")
    needle = "$scope->resolveName($node->extends) !== self::RULE_TEST_CASE"
    replacement = (
        "(" + needle
        + r" && $scope->resolveName($node->extends) !== 'PHPStan\\Testing\\TypeInferenceTestCase')"
    )
    if original.count(needle) != 1:
        raise RuntimeError("Historical negative-control mutation target not unique")
    rule_path.write_text(original.replace(needle, replacement), encoding="utf-8")
    wrong_fix = analyse("direct-parent-denylist-negative-control", base)

    outcomes = {
        "baseline": baseline,
        "repair": repair,
        "plausible_wrong_fix": wrong_fix,
    }
    return {
        "passed": baseline["in_process_diagnostic_count"] == 2
        and repair["in_process_diagnostic_count"] == 5
        and wrong_fix["in_process_diagnostic_count"] == 4
        and any(H2B_FIXTURE in path for path in repair["matched_paths"])
        and not any(H2B_FIXTURE in path for path in baseline["matched_paths"]),
        "outcomes": outcomes,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--case", choices=["H2a", "H2b"], required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    source = Path.cwd().resolve()
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    config = {
        "H2a": (H2A_BASE, H2A_FIX, H2A_FIXTURE),
        "H2b": (H2B_BASE, H2B_FIX, H2B_FIXTURE),
    }
    baseline_sha, fix_sha, fixture = config[args.case]
    report: dict[str, Any] = {
        "schema_version": "1.0",
        "evidence_class": "HISTORICAL_ORACLE_CALIBRATION_NOT_AGENT_OUTCOME",
        "case": args.case,
        "baseline_sha": baseline_sha,
        "repair_sha": fix_sha,
        "checkout_sha": os.environ.get("EXPECTED_HEAD", "unknown"),
        "status": "ERROR",
    }
    try:
        expected_head = os.environ.get("EXPECTED_HEAD")
        if expected_head:
            actual = run(
                ["git", "rev-parse", "HEAD"], source, output / "checkout.log"
            ).stdout.strip()
            if actual != expected_head:
                raise RuntimeError(f"Not exact PR head: {actual} != {expected_head}")

        with tempfile.TemporaryDirectory(prefix="h2-oracle-", dir=os.environ.get("RUNNER_TEMP")) as tmp:
            work = Path(tmp)
            base = work / "baseline"
            fixed = work / "repair"
            for name, sha, path in (("baseline", baseline_sha, base), ("repair", fix_sha, fixed)):
                run(
                    ["git", "worktree", "add", "--detach", str(path), sha],
                    source,
                    output / f"{name}-worktree.log",
                )
                verify_revision(path, sha, output / f"{name}-revision.log")

            # Hidden oracle is from the historical repair; do not copy the production fix.
            shutil.copyfile(fixed / fixture, base / fixture)
            original_hash = digest(fixed / fixture)
            if digest(base / fixture) != original_hash:
                raise RuntimeError("Oracle fixture differs between historical worktrees")
            report["oracle_sha256"] = original_hash

            for name, path in (("baseline", base), ("repair", fixed)):
                run(
                    ["composer", "install", "--no-interaction", "--prefer-dist", "--no-progress"],
                    path,
                    output / f"{name}-composer.log",
                )
                if not (path / "vendor/bin/phpunit").exists():
                    raise RuntimeError(f"Missing PHPUnit after Composer install: {name}")

            observation = (
                replay_h2a(base, fixed, output)
                if args.case == "H2a"
                else replay_h2b(base, fixed, output)
            )
            report.update(observation)
            report["status"] = "PASS" if observation["passed"] else "REGRESSION_OR_MISMATCH"
    except (OSError, RuntimeError, subprocess.TimeoutExpired, ET.ParseError) as error:
        report["error"] = str(error)

    (output / "report.json").write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0 if report["status"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())
