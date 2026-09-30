# Contributing to agent-loop

Thank you for your interest in contributing to `voku/agent-loop`! We welcome bug reports, feature suggestions, and pull requests.

## Development Workflow

1. Fork the repository and clone your fork.
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Install dependencies: `composer install`
4. Make your changes adhering to existing code conventions.

## Running Tests & Quality Checks

Run the test suite and static analysis before submitting a pull request:

```bash
# Validate composer configuration
composer validate --strict

# Run PHPUnit tests
composer test
# or: vendor/bin/phpunit

# Quick feedback while developing: skips the tests tagged #[Group('slow')]
composer test:fast

# The full suite on several processes (paratest, one test per process slot)
composer test:parallel

# Run PHPStan static analysis
composer phpstan
# or: vendor/bin/phpstan analyse -c phpstan.neon.dist

# Run all CI checks
composer ci
```

### Why some tests are slow

Every map build starts a PHPStan process (about 1.5-2 s even for a two-file fixture). Tests that build the
same fixture in a fresh temporary root should use `voku\AgentLoop\Tests\Support\CachedAgentMapBuilder::build()`
instead of `new AgentMapBuilder()`: it caches the built map by the fixture's content and the installed
agent-map/PHPStan, and re-roots it on a hit. Set `AGENT_LOOP_TEST_MAP_CACHE=0` to force real builds.
Tests whose map build happens inside production code (the CLI flows) are tagged `#[Group('slow')]`.

## Pull Requests

- Keep pull requests focused on a single concern.
- Ensure all CI checks (`composer ci`) pass.
- Include unit tests for any new features or bug fixes.
- Follow the pull request template provided.

## Code of Conduct

Please note that this project is released with a [Contributor Code of Conduct](CODE_OF_CONDUCT.md). By participating in this project you agree to abide by its terms.
