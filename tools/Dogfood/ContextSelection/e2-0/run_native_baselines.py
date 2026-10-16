#!/usr/bin/env python3
"""E2.0 single-case native baseline runner (experiment only).

Runs predeclared native commands (cat, tail, rg) over the preserved original CI log, then scores
the outputs against an evaluator-only rubric. The commands never see the rubric. Nothing is
truncated after the fact: an output larger than its budget is reported OVER_BUDGET.
"""
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

base = Path(sys.argv[1])
out = Path(sys.argv[2])
(out / "outputs").mkdir(parents=True, exist_ok=True)

case = json.loads((base / "e2-0-case.json").read_text())
src_meta = case["source"]
log = base / src_meta["path"]
raw = log.read_bytes()
src_lines = raw.splitlines(keepends=True)


def sha(b):
    return "sha256:" + hashlib.sha256(b).hexdigest()


invariants = {
    "source_sha256_matches": sha(raw) == "sha256:" + src_meta["sha256"],
    "source_bytes_match": len(raw) == src_meta["bytes"],
    "source_lines_match": len(src_lines) == src_meta["lines"],
}
(out / "orig.log").write_bytes(raw)


def run(args):
    r = subprocess.run(args, capture_output=True)
    return r.returncode, r.stdout, r.stderr


# ---- build the predeclared command matrix (no rubric access here) ----
runs = []
runs.append({"id": "full", "kind": "full", "budget": None, "args": ["cat", str(log)]})
for budget in case["budgets_bytes"]:
    runs.append({"id": f"tail_c@{budget}", "kind": "tail", "budget": budget,
                 "args": ["tail", "-c", str(budget), str(log)]})
for name, spec in case["strategies"].items():
    if spec["kind"] != "rg":
        continue
    for n in spec["context_lines"]:
        args = ["rg", "--no-config", "--no-filename", "--no-heading", "--color", "never", "-n"]
        if spec.get("ignore_case"):
            args.append("-i")
        args += ["-C", str(n), "-e", spec["pattern"], str(log)]
        runs.append({"id": f"{name}_C{n}", "kind": "rg", "budget": None, "args": args})

determinism = {}
for r in runs:
    rc1, o1, e1 = run(r["args"])
    rc2, o2, _ = run(r["args"])
    r.update(exit_code=rc1, stdout=o1, stderr=e1)
    determinism[r["id"]] = (rc1 == rc2 and o1 == o2)
    (out / "outputs" / (r["id"].replace("@", "_at_") + ".txt")).write_bytes(o1)
invariants["all_commands_deterministic"] = all(determinism.values())
invariants["tail_rg_exit_ok"] = all(r["exit_code"] == 0 for r in runs)

# ---- evaluator-only rubric is loaded only now ----
ev = json.loads((base / "e2-0-evaluator-only.json").read_text())
items = ev["items"]
all_rubric_lines = {n for it in items.values() for alt in it["alternatives"] for n in alt}


def retained_lines(kind, data):
    """Return (set of source line numbers, partial_first_line, provenance_mismatches)."""
    total = len(src_lines)
    if kind == "full":
        return set(range(1, total + 1)), False, 0
    if kind == "tail":
        k = 0
        for cand in range(total + 1):
            if data.endswith(b"".join(src_lines[total - cand:])):
                k = cand
            else:
                break
        joined = len(b"".join(src_lines[total - k:]))
        return set(range(total - k + 1, total + 1)), len(data) > joined, 0
    got, bad = set(), 0
    for line in data.split(b"\n"):
        if not line or line == b"--":
            continue
        m = re.match(rb"^(\d+)([:-])(.*)$", line, re.S)
        if not m:
            bad += 1
            continue
        n = int(m.group(1))
        if 1 <= n <= total and m.group(3) == src_lines[n - 1].rstrip(b"\n"):
            got.add(n)
        else:
            bad += 1
    return got, False, bad


def ranges(s):
    out_r, start, prev = [], None, None
    for n in sorted(s):
        if start is None:
            start = prev = n
        elif n == prev + 1:
            prev = n
        else:
            out_r.append([start, prev]); start = prev = n
    if start is not None:
        out_r.append([start, prev])
    return out_r


def item_ok(it, got):
    return any(all(n in got for n in alt) for alt in it["alternatives"])


records = []
for r in runs:
    data = r["stdout"]
    got, partial, bad = retained_lines(r["kind"], data)
    per_item = {k: item_ok(v, got) for k, v in items.items()}
    required = [k for k, v in items.items() if v["required"]]
    missing = [k for k in required if not per_item[k]]
    rc = all(per_item[k] for k in ev["root_cause_identifiable_requires"])
    cd = all(per_item[k] for k in ev["complete_diagnosis_requires"])
    budgets = [r["budget"]] if r["budget"] else case["budgets_bytes"]
    for b in budgets:
        within = len(data) <= b
        if r["kind"] == "full":
            status = "REFERENCE_OVER_BUDGET" if not within else "OK"
        else:
            status = "OK" if within else "OVER_BUDGET"
        out_lines = data.count(b"\n")
        rubric_hits = len(got & all_rubric_lines)
        records.append({
            "strategy": r["id"],
            "budget_bytes": b,
            "command": [("<LOG>" if a == str(log) else a) for a in r["args"]],
            "exit_code": r["exit_code"],
            "stderr_bytes": len(r["stderr"]),
            "bytes": len(data),
            "lines": out_lines,
            "sha256": sha(data),
            "reduction_ratio_vs_original": round(len(data) / len(raw), 4),
            "budget_status": status,
            "partial_first_line": partial,
            "source_line_provenance_mismatches": bad,
            "retained_source_line_ranges": ranges(got),
            "items": per_item,
            "missing_required_items": missing,
            "root_cause_identifiable": rc,
            "complete_diagnosis": cd,
            "complete_diagnosis_within_budget": bool(cd and status == "OK"),
            "retained_lines_outside_rubric": len(got - all_rubric_lines),
            "retained_lines_total": len(got),
        })

invariants["no_provenance_mismatch"] = all(x["source_line_provenance_mismatches"] == 0 for x in records)
invariants_ok = all(invariants.values())

passing = {}
for b in case["budgets_bytes"]:
    passing[str(b)] = [x["strategy"] for x in records
                       if x["budget_bytes"] == b and x["complete_diagnosis_within_budget"] and x["strategy"] != "full"]
if not invariants_ok:
    verdict = "INCONCLUSIVE"
elif passing[str(max(case["budgets_bytes"]))]:
    verdict = "NATIVE_FILTER_SUFFICIENT_FOR_THIS_CASE"
else:
    verdict = "NATIVE_FILTER_INSUFFICIENT"
smallest = next((b for b in sorted(case["budgets_bytes"]) if passing[str(b)]), None)

report = {
    "schema_version": "1.0",
    "stage": "E2.0",
    "source": {**src_meta, "observed_sha256": sha(raw), "observed_bytes": len(raw), "observed_lines": len(src_lines)},
    "invariants": invariants,
    "invariants_ok": invariants_ok,
    "determinism_per_command": determinism,
    "records": records,
    "passing_strategies_by_budget": passing,
    "smallest_passing_budget_bytes": smallest,
    "preliminary_disposition_by_predeclared_rule": verdict,
    "existing_formatter_assessment": case["existing_formatter_assessment"],
    "limitations": case["limitations"],
    "model_invoked": False,
    "token_or_task_success_claim": False,
}
(out / "e2-0-native-baselines.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")

print(f"{'strategy':28} {'budget':>6} {'bytes':>6} {'lines':>5} {'status':>22} partial cause complete missing")
for x in records:
    print(f"{x['strategy']:28} {x['budget_bytes']:>6} {x['bytes']:>6} {x['lines']:>5} {x['budget_status']:>22} "
          f"{str(x['partial_first_line']):>7} {str(x['root_cause_identifiable']):>5} {str(x['complete_diagnosis']):>8} {x['missing_required_items']}")
print("invariants:", invariants)
print("passing:", passing)
print("verdict:", verdict)
