# Security policy

## Reporting a vulnerability

Please open a private security advisory on GitHub or contact the maintainer
directly at lars@moelleken.org rather than filing a public issue if the
report includes vulnerability or exploit details.

## What this package does to stay safe by default

- **Task and Session Isolation**: Session IDs and names are validated to prevent
  path traversal or accidental cross-session overwriting.
- **Volatile Working Memory Boundary**: Working memory stays scoped to active
  tasks and is safely pruneable, preventing ephemeral scratch state from
  leaking into durable system memory.
- **File Integrity and Locking**: Checkpoints and decision records are
  committed with atomicity and error isolation.
- **Contextual Exceptions**: No error suppression (`@`); all failures produce
  actionable, non-leaking exceptions.

## Supported versions

This project is pre-1.0; only the latest commit on the default branch
receives security fixes until a 1.0.0 stability policy is published.
