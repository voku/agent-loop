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

# Run PHPStan static analysis
composer phpstan
# or: vendor/bin/phpstan analyse -c phpstan.neon.dist

# Run all CI checks
composer ci
```

## Pull Requests

- Keep pull requests focused on a single concern.
- Ensure all CI checks (`composer ci`) pass.
- Include unit tests for any new features or bug fixes.
- Follow the pull request template provided.

## Code of Conduct

Please note that this project is released with a [Contributor Code of Conduct](CODE_OF_CONDUCT.md). By participating in this project you agree to abide by its terms.
