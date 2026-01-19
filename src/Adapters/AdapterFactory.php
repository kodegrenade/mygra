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
  private static array $driverMap = [
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

    if (!isset(self::$driverMap[$driver])) {
      throw new \InvalidArgumentException(
        "Unsupported database driver: '{$driver}'. " .
          "Supported drivers: " . implode(', ', array_keys(self::$driverMap))
      );
    }

    $adapterClass = self::$driverMap[$driver];
    $adapter = new $adapterClass();
    $adapter->connect($config);

    return $adapter;
  }

  /**
   * Create an adapter without connecting.
   *
   * @param string $driver Driver name
   * @return AdapterInterface Adapter instance (not connected)
   */
  public static function createWithoutConnect(string $driver): AdapterInterface
  {
    $driver = strtolower($driver);

    if (!isset(self::$driverMap[$driver])) {
      throw new \InvalidArgumentException(
        "Unsupported database driver: '{$driver}'. " .
          "Supported drivers: " . implode(', ', array_keys(self::$driverMap))
      );
    }

    return new (self::$driverMap[$driver])();
  }

  /**
   * Register a custom adapter for a driver.
   *
   * @param string $driver Driver name
   * @param string $adapterClass Fully qualified class name
   */
  public static function register(string $driver, string $adapterClass): void
  {
    if (!is_subclass_of($adapterClass, AdapterInterface::class)) {
      throw new \InvalidArgumentException(
        "Adapter class must implement AdapterInterface"
      );
    }

    self::$driverMap[strtolower($driver)] = $adapterClass;
  }

  /**
   * Get list of supported drivers.
   */
  public static function getSupportedDrivers(): array
  {
    return array_unique(array_keys(self::$driverMap));
  }

  /**
   * Check if a driver is supported.
   */
  public static function isSupported(string $driver): bool
  {
    return isset(self::$driverMap[strtolower($driver)]);
  }
}
