---
name: php-best-practices
description: Modern PHP engineering discipline: strict types, native types first, immutable value objects, explicit error handling, boundary security, and blank-page prevention.
---

# PHP Best Practices

Design for explicitness, analyzability, type safety, and minimal safe changes.

## Type Safety & Modern Design

- Always require `declare(strict_types=1);` in all PHP files.
- Prefer native PHP types for parameters, returns, and properties. Use PHPDoc as a precision layer (`list<T>`, `array<string, int>`, `class-string<T>`, `non-empty-string`), not as decoration.
- Mark classes `final` by default unless explicitly designed for extension.
- Prefer immutable value objects for domain primitives where validation belongs with the data.
- Avoid `mixed`. If input is mixed at a system boundary, validate and narrow it immediately into a typed value.
- Avoid untyped arrays passed across layers without a documented shape. Use explicit DTOs or typed collections where appropriate.
- Avoid magic methods (`__get`, `__set`, `__call`) in domain logic.
- Avoid introducing file-local global functions; use private methods, small dedicated classes, or local closures.

## Error Handling & Observability

- Failures must be explicit, typed, and observable without leaking secrets.
- Throw typed exceptions with context instead of returning `false` or `null` on failure.
- Catch specific exceptions at the recovery, translation, or logging boundary.
- Avoid blanket `catch (\Throwable)` or `catch (\Exception)` unless rethrowing after essential cleanup or boundary translation.
- Use `finally` only for guaranteed cleanup (closing handles, unlocking mutexes).
- Log actionable context with PSR-3 loggers; never log passwords, tokens, API keys, or sensitive personal data.
- Do not instantiate heavy logging handles or expensive resources inside loops; instantiate once and reuse.

## UI & View Flow Discipline

- In web/view execution paths, every reachable branch (including early returns, validation failures, and permission checks) must render a template or return a response. A silent early exit or unhandled condition leaves a blank or partial response ("white page") for users.
- For batch operations, never emit an aggregate success message after a loop unless an accumulator confirms every individual item succeeded. If partial failures occurred, report a mixed or failure outcome.

## Security Boundaries

- Input is untrusted: validate all external inputs against strict schemas or validation rules at the boundary.
- SQL queries: always use prepared statements with parameter binding. Never concatenate variables into SQL strings.
- Output escaping: treat all dynamic output as untrusted and encode appropriately for its output context (HTML, JS, URL, attribute).
- CSRF protection: ensure all state-mutating requests (POST, PUT, DELETE) validate CSRF tokens.

## Testing Discipline

- Unit tests for domain logic, value objects, and utility functions.
- Integration tests for database queries, external API gateways, and boundary adapters.
- Bug fixes must include a reproduction test that fails before the fix and passes after.
- Prefer test doubles / fake implementations via interfaces or anonymous classes over complex mocking libraries.
- Never assert a pass without observing the exit code and output of the test runner.
