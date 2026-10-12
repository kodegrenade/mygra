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

# Mygra - Universal Database Migration Tool

Mygra is a powerful, database-agnostic migration engine designed to move data across different database systems (MySQL, PostgreSQL, SQLite, SQL Server) with ease. It features a pluggable transformation pipeline, robust validation, and resumable migrations.

## Key Features

- **Multi-Database Support**: Connect and migrate between MySQL, PostgreSQL, SQLite, and SQL Server.
- **Smart Schema Sync**: Automatically convert schemas between different database drivers, including type mapping and foreign key detection.
- **Transformation Pipeline**: Clean, hash, or reformat your data on the fly as it moves.
- **Data Validation**: Ensure data integrity with built-in validators (Email, Regex, Length, Type, etc.).
- **Resumable Migrations**: Batches commit independently; after an interruption, `--resume` continues where the target left off. Prefer all-or-nothing? Set `transaction_mode: single`.
- **Powerful CLI**: Progress bars, colored output, and interactive wizards for easy management.

## Installation

### 1. Clone the repository
```bash
git clone https://github.com/kodegrenade/mygra.git
cd mygra
```

### 2. Install dependencies
```bash
composer install
```

### 3. Make CLI executable
```bash
chmod +x mygra
```

## Quick Start

### 1. Initialize Configuration

Run the wizard to create your first `mygra.yaml`:

```bash
php mygra init
```

### 2. Compare Schemas

See what's missing in your target database:

```bash
php mygra compare --schema=users
```

### 3. Run Migration

Execute the migration with progress tracking:

```bash
php mygra migrate --schema=users --create
```

### 4. Verify

Check the target holds exactly what the migration should have written:

```bash
php mygra verify --schema=users
```

## Configuration

Mygra uses a YAML-based configuration. Example:

```yaml
connections:
  source:
    driver: mysql
    host: localhost
    database: old_app
    user: root
  target:
    driver: pgsql
    host: localhost
    database: new_app

schemas:
  users:
    source_table: wp_users
    target_table: users
    fields:
      id: ID
      user_login: username
      user_email: email
    transforms:
      email:
        - { transformer: string, options: { case: lower } }
    validations:
      email:
        - { validator: email }
```

## Transactions and Resuming

Set `settings.transaction_mode` (or per schema under `options:`):

- `per_batch` (default): each batch commits on its own. If a run fails, the committed batches stay; fix the cause and rerun with `--resume`. Mygra reads the highest primary key already in the target and continues from the next source row. Resume needs a single-column source primary key mapped to the target without a transform.
- `single`: the whole schema runs in one transaction, so a failure leaves the target untouched. Best for small tables; there is nothing to resume.

```bash
php mygra migrate --schema=users --resume
```

## Commands

- `migrate`: Execute data migration.
- `verify`: Compare source and target after a migration (row counts and a checksum of the mapped columns). Exits non-zero on mismatch.
- `inspect`: View database schema details.
- `compare`: Compare source vs target schemas.
- `suggest`: Get field mapping suggestions.
- `validate`: Check configuration and connectivity.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The Mygra database migration tool is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
