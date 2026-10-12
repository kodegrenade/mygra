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

Mygra is a powerful, database-agnostic migration engine designed to move data across different database systems (MySQL, PostgreSQL, SQLite, SQL Server) with ease. It features a pluggable transformation pipeline, robust validation, and crash-recovery support.

## Key Features

- **Multi-Database Support**: Connect and migrate between MySQL, PostgreSQL, SQLite, and SQL Server.
- **Smart Schema Sync**: Automatically convert schemas between different database drivers, including type mapping and foreign key detection.
- **Transformation Pipeline**: Clean, hash, or reformat your data on the fly as it moves.
- **Data Validation**: Ensure data integrity with built-in validators (Email, Regex, Length, Type, etc.).
- **Resilient Transactions**: Journal-based rollback and recovery ensures you never leave your target database in a partial state.
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

## Commands

- `migrate`: Execute data migration.
- `rollback`: Recover from a failed/interrupted migration.
- `inspect`: View database schema details.
- `compare`: Compare source vs target schemas.
- `suggest`: Get field mapping suggestions.
- `validate`: Check configuration and connectivity.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The Mygra database migration tool is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
