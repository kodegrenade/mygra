<div align="center">

```text
  __  __                       
 |  \/  |_   _  __ _ _ __ __ _ 
 | |\/| | | | |/ _` | '__/ _` |
 | |  | | |_| | (_| | | | (_| |
 |_|  |_|\__, |\__, |_|  \__,_|
         |___/ |___/           

  Universal Database Migration Tool
  The modern way to sync schemas and move data.
```

</div>

# Mygra

Mygra moves data from one database to another — MySQL, PostgreSQL, SQLite and SQL Server, in any direction — driven by a configuration file instead of code. You describe which tables go where, how columns map, how values are cleaned and validated, and how rows relate to each other; Mygra moves the data across in batches, keeps relationships intact, and proves the result with `verify`.

It is built for real application migrations: merging into an app that already has data, giving rows new IDs (integers or UUIDs) while every reference follows, resuming after a failure, and doing a final sync just before cutover.

## Contents

- [Features](#features)
- [Requirements and installation](#requirements-and-installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Transforms](#transforms)
- [Validations](#validations)
- [Errors, transactions and resuming](#errors-transactions-and-resuming)
- [Migration order](#migration-order)
- [ID mapping](#id-mapping)
- [Verifying a migration](#verifying-a-migration)
- [Commands](#commands)
- [Limitations](#limitations)
- [Security](#security)

## Features

- **Any direction between four databases**: MySQL/MariaDB, PostgreSQL, SQLite, SQL Server. Tested against real MySQL 8.4, PostgreSQL 17 and SQL Server 2022 in CI.
- **Column mapping, joins and filters**: rename columns, pull columns in from joined tables, filter source rows with a `where` clause.
- **Transforms and validations** per column: dates, booleans, casing, trimming, hashing, JSON and more; rows that fail validation are skipped or stop the run, your choice.
- **Creates target tables** when missing, converting column types between databases.
- **Reads in key order, page by page**, so table size is bounded by time, not memory, nothing stays open on a live source, and rows added or deleted mid-run cannot be skipped. Wide tables are split to stay under each database's parameter limit.
- **Catches config mistakes before anything runs**: every key, option, transformer and validator name is checked when the config loads, with a "did you mean" for typos.
- **Resumable**: each batch commits on its own; `--resume` continues after a failure. Or run a schema as one all-or-nothing transaction.
- **Final sync** with `--upsert`: update rows that changed in the source, add new ones.
- **Parents before children**: schemas are ordered by foreign keys or declared dependencies; if a parent fails, its children are skipped.
- **ID mapping**: give rows new IDs — an offset after the target's existing rows, or deterministic UUIDs — and every reference follows, including IDs inside text such as links.
- **Verify**: compare row counts and a checksum of every mapped column, references included. Non-zero exit on any mismatch.
- **Honest results**: any skipped row makes the run exit non-zero; nothing reports success when data was left behind.

## Requirements and installation

- PHP 8.1 or newer with PDO and the driver extension for each database you use: `pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`, `pdo_sqlsrv` (or `pdo_dblib`).
- Composer.

```bash
git clone https://github.com/kodegrenade/mygra.git
cd mygra
composer install
./mygra list        # or: php mygra list
```

Inside a project: `composer require --dev kodegrenade/mygra`, then run `vendor/bin/mygra`.

## Quick start

```bash
php mygra init                          # create config/mygra.yaml with a wizard
php mygra validate                      # check the config and both connections
php mygra compare --schema=users        # see how source and target tables differ
php mygra migrate --dry-run             # read and transform everything, write nothing
php mygra migrate --create              # migrate, creating missing target tables
php mygra verify                        # prove the target matches the source
php mygra migrate --upsert              # final sync just before cutover
php mygra finish                        # after cutover: verify once more, remove Mygra's bookkeeping
```

## Configuration

The default config file is `config/mygra.yaml`; pass another with `--config`. YAML, JSON (`.json`) and PHP files returning an array (`.php`) are accepted.

```yaml
connections:
  source:
    driver: mysql
    host: 127.0.0.1
    database: old_app
    user: root
    password: ${SOURCE_DB_PASSWORD}
  target:
    driver: pgsql
    host: 127.0.0.1
    database: new_app
    user: postgres
    password: ${TARGET_DB_PASSWORD:-secret}

settings:
  batch_size: 1000
  on_error: abort
  transaction_mode: per_batch
  log_file: logs/migration_{date}.log
  log_level: info

schemas:
  users:
    source_table: wp_users
    target_table: users
    fields:                     # source column: target column
      ID: id
      user_login: username
      user_email: email
    transforms:
      email:
        - { transformer: string, options: { trim: true, case: lower } }
    validations:
      email:
        - { validator: required }
        - { validator: email }
    where: "user_status = 0"
```

Values can read environment variables: `${NAME}`, or `${NAME:-default}` with a fallback.

The whole file is checked when it loads, before any database is touched. Unknown keys, misspelled options, unknown transformers or validators and invalid values are all reported at once:

```text
   ERROR  Invalid configuration in config/mygra.yaml (2 problems):
  - settings.on_error: 'abrot' is not allowed (did you mean abort?); use one of: skip, abort, rollback
  - schemas.users.options.remap_id: unknown option (did you mean remap_ids?); allowed: create_target, on_error, ...
```

### Connections

| Driver (`driver`) | Keys (default) |
| --- | --- |
| `mysql` (also `mariadb`) | `host` (localhost), `port` (3306), `database`, `user` (root), `password`, `charset` (utf8mb4) |
| `pgsql` (also `postgres`, `postgresql`) | `host` (localhost), `port` (5432), `database`, `user` (postgres), `password`, `schema` (public) |
| `sqlite` (also `sqlite3`) | `database`: file path or `:memory:`; `journal_mode` (WAL), `synchronous` (NORMAL) |
| `sqlsrv` (also `sqlserver`, `mssql`, `dblib`) | `host` (localhost), `port` (1433), `database`, `user` (sa), `password`, `schema` (dbo), `pdo_driver` (`sqlsrv` or `dblib`, detected), `trust_server_certificate` (needed for ODBC Driver 18 with a self-signed certificate) |

Every driver also accepts `options`: PDO attributes passed to the connection.

### Settings

| Setting | Default | Meaning |
| --- | --- | --- |
| `batch_size` | 1000 | Rows per batch; `--batch-size` overrides it |
| `on_error` | `skip` | What a row error does: `skip` the row, or `abort` the schema (`rollback` is an older name for `abort`) |
| `transaction_mode` | `per_batch` | `per_batch` or `single`; see [transactions](#errors-transactions-and-resuming) |
| `log_file` | `logs/migration_{date}.log` | `{date}`, `{year}`, `{month}`, `{day}` are filled in; `--log-file` overrides it |
| `log_level` | `info` | `debug`, `info`, `warning` or `error` |

### Schemas

Each entry under `schemas` moves one source table into one target table.

| Key | Meaning |
| --- | --- |
| `source_table`, `target_table` | Table names |
| `fields` | `source_column: target_column` pairs; only these columns are copied |
| `transforms` | Per target column, a list of transforms ([below](#transforms)) |
| `validations` | Per target column, a list of validators ([below](#validations)) |
| `where` | SQL condition on the source table |
| `joins` | Columns pulled in from other tables ([below](#joins)) |
| `references` | Columns (or IDs inside text) pointing at another schema's rows ([ID mapping](#id-mapping)) |
| `options` | Per-schema settings, listed below |

| Option | Meaning |
| --- | --- |
| `create_target` | Create the target table if missing (same as `--create`) |
| `on_error`, `transaction_mode` | Override the global setting for this schema |
| `upsert` | Always upsert this schema (same as `--upsert`) |
| `depends_on` | Schemas that must migrate first |
| `remap_ids` | `true` (offset) or `uuid`: give rows new IDs ([ID mapping](#id-mapping)) |
| `uuid_namespace` | Namespace for `remap_ids: uuid` |

### Joins

```yaml
posts:
  source_table: posts
  target_table: articles
  fields: { id: id, title: title }
  joins:
    - table: categories
      type: LEFT              # LEFT (default), INNER, ...
      source_key: category_id # column in posts
      target_key: id          # column in categories (defaults to source_key)
      fields:
        name: category_name   # categories.name lands in articles.category_name
      where: "categories.visible = 1"
      join: []                # further joins, nested from this one
```

## Transforms

Transforms run per target column, in order. Each item is `{ transformer: name, options: {...} }`, or `{ name: {...options} }`, or just the name.

| Transformer | Options |
| --- | --- |
| `string` | `trim: true`, `case` (lower, upper, title, ucfirst, lcfirst, snake, camel, pascal, kebab), `max_length` + `ellipsis`, `prefix`, `suffix`, `replace: {search, replacement}`, `regex_replace: {pattern, replacement}`, `trim_chars`. `operations` lists steps to run first, in order, including `ltrim`, `rtrim`, `slug`, `strip_tags` (with `allowed_tags`), `html_entities`, `html_decode` |
| `date` | `input_format` (PHP format; numbers are Unix timestamps; otherwise parsed freely), `output_format` (`Y-m-d H:i:s`), `input_timezone`, `output_timezone`, `default` for empty values, `on_error` value to use when parsing fails |
| `boolean` | `true_values`, `false_values`, `output_format` (`int` default, `bool`, `string`, `yes_no`), `null_value` |
| `typecast` | `type`: int, float, string, bool, array; `preserve_null` (true) |
| `nullable` | `empty_as_null` (true), `null_values`, `default` |
| `json` | `mode`: encode or decode; `assoc`, `depth`, `flags` |
| `hash` | `algorithm`: md5, sha1, sha256 (default), sha512, bcrypt (`cost`), argon2i, argon2id, or any `hash()` algorithm |
| `callable` | `function`: a PHP function name, `args`: extra arguments |

```yaml
transforms:
  created_at:
    - transformer: date
      options: { input_format: U, output_format: "Y-m-d H:i:s" }
  is_active:
    - transformer: boolean
      options: { true_values: [1, "active"], false_values: [0, "blocked"], output_format: int }
```

## Validations

Validations run on the transformed row, per target column. A row that fails is a row error and follows `on_error`.

| Validator | Options |
| --- | --- |
| `required` | — |
| `email` | — |
| `length` | `min`, `max` |
| `regex` | `pattern` |
| `type` | `type`: string, int, float/numeric, bool, array |

```yaml
validations:
  username:
    - { validator: required }
    - { validator: length, options: { min: 3, max: 50 } }
```

## Errors, transactions and resuming

**Row errors** — a failed transform, a failed validation, or a reference with no parent — skip the row (`on_error: skip`) or stop the schema (`on_error: abort`). Either way the run exits non-zero if any row was skipped, and the errors are in the log.

**Write errors** (a duplicate key, a column that does not exist) always stop the schema.

**`transaction_mode`**:

- `per_batch` (default): each batch commits on its own, so large tables never hold one huge transaction. After a failure, the committed batches stay: fix the cause and rerun with `--resume`, which continues after the highest key already in the target. Resume needs a single-column source primary key mapped to the target without a transform.
- `single`: the whole schema is one transaction; a failure leaves the target untouched. Best for small tables.

```bash
php mygra migrate --schema=users --resume
```

### Final sync before cutover

While the old app keeps running, rows keep changing. Rerun with `--upsert` just before switching over: rows already in the target (matched on its primary key) are updated, new rows are inserted. Rows deleted from the source are not deleted from the target.

```bash
php mygra migrate --upsert
```

After every load, Mygra moves PostgreSQL sequences past the IDs it inserted, so the application's next insert does not collide with migrated rows.

## Migration order

When migrating all schemas, parents run before children: a schema waits for every schema its source table references by foreign key, lists in `depends_on`, or names in `references`. Sources without declared foreign keys (MyISAM tables, for example) can state the order explicitly:

```yaml
posts:
  options:
    depends_on: [users]
```

If a schema fails, every schema that depends on it, directly or further down the chain, is skipped, so no child points at parent rows that never arrived. Independent schemas still run. Schemas in a dependency cycle keep their config order and are reported in a warning.

## ID mapping

By default IDs are copied unchanged, which is right for an empty target. Merging into a table that already has rows needs new IDs — and every row pointing at those rows must follow them, or a post silently ends up belonging to whoever already had that ID.

```yaml
schemas:
  users:
    source_table: wp_users
    target_table: users
    fields: { ID: id, user_email: email }
    options:
      remap_ids: true            # new ID = old ID + an offset, after the target's highest ID
  posts:
    source_table: wp_posts
    target_table: posts
    fields: { ID: id, post_author: author_id }
    references:
      author_id: users           # rewritten to the user's new ID; also makes users migrate first
```

- `migrate` prints each offset (`New IDs: old ID + 40`); keep it to redirect old URLs.
- Before loading, Mygra moves the table's auto-increment past its range, so an application that keeps running cannot take those IDs.
- A reference with no matching row (a post by a deleted user) is a row error. `author_id: { schema: users, on_missing: null }` keeps the row with a NULL reference instead.
- A schema can reference itself (`parent_id: categories`).
- Offsets are stored in a `mygra_offsets` table in the target, so resume, `verify` and `--upsert` reruns keep using the same IDs. `mygra finish` removes it after cutover.
- Source rows added after the first run extend the reserved range, but only into IDs that are still free; if the application has taken some of them meanwhile, the run stops instead of overwriting its rows.
- Needs a single-column primary key in the source.

### UUID keys

When the target uses UUID keys, set `remap_ids: uuid`. Each new ID is a UUIDv5 of `"<source table>:<old id>"`, so the same row always gets the same UUID, and references follow automatically.

- Reruns with `--upsert` are always safe. `--resume` is not available (UUIDs have no order); use `--upsert` instead.
- Several sources with the same table names into one target (two shops, two sites)? Give each its own `uuid_namespace`, or their user 7s get the same UUID: the second insert fails on a duplicate key, and with `--upsert` it would overwrite the first.
- With `--create`, the key and every reference to a UUID schema are created as `UUID` / `UNIQUEIDENTIFIER` / `CHAR(36)`.
- To redirect old URLs, compute the same UUIDv5 (namespace `5b0f8e3a-7c41-4d2e-9a6b-6d7967726101` unless you set one).

### IDs inside text

IDs also hide inside values: links (`/?p=812`), ID lists (`ids="4,9"`). A `pattern` finds them; its first capture group holds one ID or a comma-separated list. Several references can share a column via `column`:

```yaml
posts:
  references:
    links:   { column: body, schema: posts, pattern: '/\?p=(\d+)/' }
    gallery: { column: body, schema: media, pattern: '/ids="([^"]*)"/', on_missing: replace }
```

Only the IDs are rewritten; the rest of the text is untouched. For an ID with no matching row (a dead link):

- `on_missing: error` (default): the row fails and follows `on_error`.
- `on_missing: replace`: the dead ID becomes `0` (or `replacement: <value>`), so the link stays dead instead of pointing at someone else's record. Mygra refuses a replacement that could match a real row and reports how many it replaced.

## Verifying a migration

```bash
php mygra verify            # all schemas
php mygra verify -s users   # one schema
```

`verify` reads the source through the same query, transforms, validations and ID mapping as `migrate`, and compares it with the target: row counts, and an order-independent checksum of every mapped column. Rows that fail validation are reported as invalid, not expected in the target. For schemas with `remap_ids`, rows the application already had in the table are left out of the comparison; without it, every target row counts. It never changes the target, and exits non-zero on any mismatch.

```text
   INFO  Verifying 2 schemas · mysql 127.0.0.1/old_app → pgsql 127.0.0.1/new_app

  posts .................................................... 2 rows of 2  FAIL
    ⤷ Values differ: the checksum of the mapped columns does not match
  users .................................................... 2 rows of 2  OK

   ERROR  1 schema does not match the source.
```

### Finishing a migration

Once the application runs on the target and no more syncs are needed, run `mygra finish`. It verifies every schema once more and, only if all of them match, removes the `mygra_offsets` table. After that, `--resume` and `--upsert` can no longer reuse the same IDs, so finish last.

## Commands

Every command accepts `--config` / `-c`.

| Command | What it does | Options |
| --- | --- | --- |
| `init` | Creates a configuration file with a wizard | `--output`, `--format` (yaml, json, php) |
| `validate` | Checks the configuration, both connections and every table and field | `--schema` |
| `inspect [table]` | Lists tables, or shows one table's columns, keys and indexes | `--connection` (source, target); or connect directly with `--driver`, `--host`, `--database`, `--user`, `--password` |
| `compare` | Compares a schema's source and target tables | `--schema` |
| `suggest <source_table> <target_table>` | Suggests field mappings by name similarity | `--threshold` (0.0–1.0) |
| `migrate` | Migrates every schema in dependency order, or one | `--schema`, `--batch-size`, `--dry-run`, `--create`, `--resume`, `--upsert`, `--no-progress`, `--log-file` |
| `verify` | Compares target with source; never writes | `--schema` |
| `finish` | After cutover: verifies everything, then removes `mygra_offsets` | — |

`migrate`, `verify` and `finish` exit with 0 only when everything succeeded: no failed schema, no skipped row, no mismatch.

For CI, `migrate`, `verify`, `finish` and `validate` accept `--format=json`: one JSON document on stdout instead of the human-readable output, with per-schema status, row counts and the first row errors. Colour is turned off automatically when output is not a terminal, with `--no-ansi`, or when `NO_COLOR` is set.

## Limitations

- **Primary keys**: resume and ID mapping need a single-column primary key in the source. Tables with a composite key or none are read in a single pass instead of page by page; on SQL Server a table with no primary key at all cannot be read.
- **Deleted rows**: `--upsert` adds and updates, but never deletes target rows whose source row was removed.
- **Dependency cycles with ID mapping**: when two schemas with `remap_ids` reference each other (`users.current_team_id` and `teams.owner_id`), this is not supported yet: whichever runs first stops with "has no ID offset yet" rather than writing references it cannot map.
- **Self-references and skipped rows**: if a row fails validation, rows of the same table that point at it keep pointing at an ID that never arrived.
- **IDs inside JSON or PHP-serialized values** are not rewritten. A regex pattern can find IDs in serialized PHP, but rewriting them would corrupt it, because serialized strings record their own length.
- **Random output**: `bcrypt` and `argon2` hashes differ on every run, so `verify` always reports a checksum mismatch for those columns, and `finish` will not remove `mygra_offsets`.
- **Live SQLite targets**: Mygra does not reserve ID ranges on SQLite, so an application writing to it during a migration can collide with migrated IDs.
- **Created tables** get columns and the primary key, but no foreign keys or other indexes.

## Security

The configuration file is code: `where` and join conditions are raw SQL, a `.php` config is executed, and the `callable` transformer can call any PHP function. Only run configuration files you trust, and keep passwords in environment variables.

## Contributing

See [CONTRIBUTING](CONTRIBUTING.md), including how to run the integration tests against real databases.

## License

Mygra is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
