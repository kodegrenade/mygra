<?php

declare(strict_types=1);

namespace Mygra\Schema;

/**
 * Type Mapper
 *
 * Maps database-agnostic types to driver-specific types and vice versa.
 */
class TypeMapper
{
  private static array $typeMap = [
    'string' => [
      'mysql' => 'VARCHAR',
      'pgsql' => 'VARCHAR',
      'sqlite' => 'TEXT',
      'sqlsrv' => 'NVARCHAR',
    ],
    'integer' => [
      'mysql' => 'INT',
      'pgsql' => 'INTEGER',
      'sqlite' => 'INTEGER',
      'sqlsrv' => 'INT',
    ],
    'bigint' => [
      'mysql' => 'BIGINT',
      'pgsql' => 'BIGINT',
      'sqlite' => 'INTEGER',
      'sqlsrv' => 'BIGINT',
    ],
    'float' => [
      'mysql' => 'DOUBLE',
      'pgsql' => 'DOUBLE PRECISION',
      'sqlite' => 'REAL',
      'sqlsrv' => 'FLOAT',
    ],
    'decimal' => [
      'mysql' => 'DECIMAL',
      'pgsql' => 'DECIMAL',
      'sqlite' => 'NUMERIC',
      'sqlsrv' => 'DECIMAL',
    ],
    'boolean' => [
      'mysql' => 'TINYINT(1)',
      'pgsql' => 'BOOLEAN',
      'sqlite' => 'INTEGER',
      'sqlsrv' => 'BIT',
    ],
    'datetime' => [
      'mysql' => 'DATETIME',
      'pgsql' => 'TIMESTAMP',
      'sqlite' => 'TEXT',
      'sqlsrv' => 'DATETIME2',
    ],
    'date' => [
      'mysql' => 'DATE',
      'pgsql' => 'DATE',
      'sqlite' => 'TEXT',
      'sqlsrv' => 'DATE',
    ],
    'text' => [
      'mysql' => 'TEXT',
      'pgsql' => 'TEXT',
      'sqlite' => 'TEXT',
      'sqlsrv' => 'NVARCHAR(MAX)',
    ],
    'blob' => [
      'mysql' => 'LONGBLOB',
      'pgsql' => 'BYTEA',
      'sqlite' => 'BLOB',
      'sqlsrv' => 'VARBINARY(MAX)',
    ],
    'uuid' => [
      'mysql' => 'CHAR(36)',
      'pgsql' => 'UUID',
      'sqlite' => 'TEXT',
      'sqlsrv' => 'UNIQUEIDENTIFIER',
    ],
    'json' => [
      'mysql' => 'JSON',
      'pgsql' => 'JSONB',
      'sqlite' => 'TEXT',
      'sqlsrv' => 'NVARCHAR(MAX)',
    ],
  ];

  /**
   * Map a database-agnostic type to a driver-specific type.
   */
  public static function toNative(string $type, string $driver): string
  {
    $type = strtolower($type);
    $driver = strtolower($driver);

    return self::$typeMap[$type][$driver] ?? strtoupper($type);
  }

  /**
   * Get default length for a type if applicable.
   */
  public static function defaultLength(string $type, string $driver): ?int
  {
    $type = strtolower($type);
    if ($type === 'string' && $driver !== 'sqlite') {
      return 255;
    }
    return null;
  }
}
