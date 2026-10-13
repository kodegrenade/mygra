# Changelog

All notable changes to Mygra are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/). While the version is 0.x, minor releases may change the configuration format; such changes are listed under **Changed**.

## [0.1.0] - 2026-10-13

First public release.

### Added
- Migrate data between MySQL/MariaDB, PostgreSQL, SQLite and SQL Server in any direction, driven by a YAML, JSON or PHP config.
- Column mapping, joins, `where` filters, transforms and validations per column.
- Configuration is validated when it loads: unknown keys, options, transformers and validators are reported together, with "did you mean" suggestions.
- Reading in primary-key order, page by page; target tables created on demand (`--create`).
- Transaction modes `per_batch` (resumable with `--resume`) and `single`; `--upsert` for a final sync before cutover.
- Parents migrate before children (foreign keys, `depends_on`, `references`); children of a failed parent are skipped.
- ID mapping: `remap_ids: true` (offset after the target's existing rows) or `remap_ids: uuid` (UUIDv5), with references, self-references and IDs inside text (`pattern`) following the new IDs.
- `verify` (row counts and checksums, read-only) and `finish` (verify, then remove Mygra's bookkeeping).
- `--format=json` for `migrate`, `verify`, `finish` and `validate`; non-zero exit codes whenever rows were skipped or data does not match.

[0.1.0]: https://github.com/kodegrenade/mygra/releases/tag/v0.1.0
