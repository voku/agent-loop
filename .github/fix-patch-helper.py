from pathlib import Path

path = Path('.github/apply-self-shape-repeat-fix.py')
lines = path.read_text().splitlines()
index = 61
if index >= len(lines) or 'dropping abandoned governed Session' not in lines[index]:
    raise SystemExit('temporary helper shape changed')
lines[index] = "    \"$loop = static fn (array $arguments): array => $runner->mustRun([PHP_BINARY, 'bin/agent-loop', ...$arguments]);\\n\\n$sessionToDrop = (new SelfShapeRunRecovery())->sessionToDrop(\\n    (new SessionStore())->all((new ProjectLayout($root))->sessionsRoot()),\\n    TASK,\\n    PLANNER,\\n);\\nif ($sessionToDrop !== null) {\\n    echo '[WARN] self-shape: dropping abandoned governed Session ' . $sessionToDrop . PHP_EOL;\\n    $loop(['session', 'close', $sessionToDrop, '--status', 'dropped']);\\n}\\n\\n$base = $git(['merge-base', 'HEAD', 'origin/' . $options['base-ref']]);\\n\","
path.write_text('\n'.join(lines) + '\n')
Path(__file__).unlink()
