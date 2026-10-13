# Contributing

## Submitting a Pull Request

1. Fork the repository.
2. Create a feature branch.
3. Make your changes.
4. Commit and push your changes.
5. Open a pull request.

## Code Style

- Use 2 spaces for indentation.
- Follow PSR-2 coding standards.
- Write clear and concise commit messages.

## Testing

- Write tests for new features.
- Run tests before submitting a pull request.

```bash
composer test               # unit tests (SQLite only)
composer test:integration   # round trip against real databases
```

Integration tests run against each database whose `MYGRA_TEST_*` env var is set to its connection config as JSON; the rest are skipped. CI sets all three (see `.github/workflows/php.yml`). Example:

```bash
MYGRA_TEST_MYSQL='{"driver":"mysql","host":"127.0.0.1","database":"mygra_it","user":"root"}' composer test:integration
```

Others: `MYGRA_TEST_PGSQL`, `MYGRA_TEST_SQLSRV`.

PHPUnit fails on any warning, notice or deprecation (`phpunit.xml.dist`).

## Code style and static analysis

CI rejects a pull request that fails either check:

```bash
composer cs        # PER Coding Style with 2-space indentation; `composer cs:fix` applies it
composer analyse   # PHPStan, level 6
```

`composer.lock` is committed and resolved for PHP 8.1 (`config.platform.php`), so every CI job installs the same versions. After changing dependencies, run `composer update` and commit the lock file.

## Releasing

1. Move the changes under a new `## [x.y.z] - YYYY-MM-DD` heading in `CHANGELOG.md` (and add its link at the bottom). Merge to `main` with CI green.
2. Tag and push: `git tag -a vx.y.z -m "Mygra x.y.z" && git push origin vx.y.z`.

Packagist publishes the tag on its own, and the Release workflow creates the GitHub release with that version's CHANGELOG section as its notes. A tag with a suffix (`v1.0.0-rc.1`) becomes a pre-release.

## License

By contributing to this project, you agree to license your contribution under the MIT license.