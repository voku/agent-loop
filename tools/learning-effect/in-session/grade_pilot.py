#!/usr/bin/env python3
"""Grade H2b pilot runs: hidden oracle (5 expected locations), LevelsTestCase false-positive probe, LOC, skill read."""
import json, subprocess, sys, re
from pathlib import Path
H2 = Path(__file__).resolve().parent; T = H2.parent.parent / "tasks"
sys.path.insert(0, str(H2)); from size import numstat_ws, summ
EXPECTED = {("in-process-phpstan-rule-test-case-alias.php", 14), ("in-process-phpstan-rule-test-case.php", 13),
            ("in-process-phpstan-type-inference-test-case.php", 13), ("in-process-phpstan-type-inference-test-case.php", 21),
            ("in-process-phpstan-type-inference-test-case.php", 25)}
rows = []
for line in (H2 / "runmap.tsv").read_text().split("\n"):
    if not line.strip(): continue
    name, agent = line.split("\t"); repo = H2 / "runs" / name / "repo"
    o = subprocess.run([sys.executable, str(H2 / "oracle_h2b.py"), str(repo), str(repo / "vendor")], capture_output=True, text=True).stdout.split("\n")
    hits = {(l.split()[0], int(l.split()[1])) for l in o[1:] if l.strip()}
    fp = subprocess.run([sys.executable, str(H2 / "fp_h2b.py"), str(repo), str(repo / "vendor")], capture_output=True, text=True).stdout.strip()
    s = summ(numstat_ws(str(repo)))
    transcript = T / f"{agent}.output"
    tr = transcript.read_text(errors="ignore") if transcript.exists() else None  # None = not inspected
    rows.append(dict(run=name, arm=name[2], oracle_pass=EXPECTED <= hits, expected_found=len(EXPECTED & hits),
                     levels_false_positive=("analysis_failed" if "ANALYSIS_FAILED" in fp else (not fp.endswith("no"))), src_add=s["src_add"], src_del=s["src_del"], test_add=s["test_add"],
                     skill_read=("transcript_unavailable" if tr is None else bool(re.search(r'coding-simplicity/SKILL\.md', tr)))))
for r in rows: print(json.dumps(r))
