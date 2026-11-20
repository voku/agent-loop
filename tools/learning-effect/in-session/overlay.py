#!/usr/bin/env python3
"""Overlay voku packages into a vendor dir from git refs (git archive honours export-ignore like dist)."""
import json, shutil, subprocess, sys, io, tarfile
from pathlib import Path
vendor = Path(sys.argv[1])
specs = json.loads(sys.argv[2])  # {name: [repo_path, ref, version]}
installed_path = vendor / "composer/installed.json"
installed = json.loads(installed_path.read_text())
for name, (repo, ref, version) in specs.items():
    target = vendor / name
    shutil.rmtree(target, ignore_errors=True)
    target.mkdir(parents=True)
    data = subprocess.run(["git", "-C", repo, "archive", "--format=tar", ref], capture_output=True, check=True).stdout
    with tarfile.open(fileobj=io.BytesIO(data)) as tar:
        tar.extractall(target, filter="data")
    manifest = json.loads((target / "composer.json").read_text())
    for pkg in installed["packages"]:
        if pkg["name"] == name:
            pkg["version"] = version
            pkg["version_normalized"] = version + ".0" if version.count(".") == 2 else version
            for key in ("autoload", "require", "bin"):
                if key in manifest:
                    pkg[key] = manifest[key]
            pkg.pop("source", None); pkg.pop("dist", None)
    print(name, version)
installed_path.write_text(json.dumps(installed, indent=4))
