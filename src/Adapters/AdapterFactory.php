<?php

declare(strict_types=1);

namespace Mygra\Adapters;

/**
 * Adapter Factory
 *
 * Creates database adapters based on configuration.
 * Supports MySQL, PostgreSQL, SQLite, and SQL Server.
 */
class AdapterFactory
{
  private const DRIVER_MAP = [
    'mysql' => MySQLAdapter::class,
    'mariadb' => MySQLAdapter::class,
    'pgsql' => PostgreSQLAdapter::class,
    'postgresql' => PostgreSQLAdapter::class,
    'postgres' => PostgreSQLAdapter::class,
    'sqlite' => SQLiteAdapter::class,
    'sqlite3' => SQLiteAdapter::class,
    'sqlsrv' => SQLServerAdapter::class,
    'sqlserver' => SQLServerAdapter::class,
    'mssql' => SQLServerAdapter::class,
    'dblib' => SQLServerAdapter::class,
  ];

  /**
   * Create an adapter from configuration.
   *
   * @param array $config Configuration array with 'driver' key
   * @return AdapterInterface Connected adapter instance
   * @throws \InvalidArgumentException If driver is not supported
   */
  public static function create(array $config): AdapterInterface
  {
    $driver = strtolower($config['driver'] ?? '');

    if (!isset(self::DRIVER_MAP[$driver])) {
      throw new \InvalidArgumentException(
        "Unsupported database driver: '{$driver}'. " .
          'Supported drivers: ' . implode(', ', array_keys(self::DRIVER_MAP)),
      );
    }

    $adapterClass = self::DRIVER_MAP[$driver];
    $adapter = new $adapterClass();
    $adapter->connect($config);

    return $adapter;
  }

  /**
   * Get list of supported drivers.
   */
  public static function getSupportedDrivers(): array
  {
    return array_unique(array_keys(self::DRIVER_MAP));
  }

  /**
   * Check if a driver is supported.
   */
  public static function isSupported(string $driver): bool
  {
    return isset(self::DRIVER_MAP[strtolower($driver)]);
  }
}
