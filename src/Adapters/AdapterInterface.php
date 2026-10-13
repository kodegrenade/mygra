<?php

declare(strict_types=1);

namespace Mygra\Adapters;

use Mygra\Schema\TableSchema;

/**
 * Database Adapter Interface
 *
 * Defines the contract for all database adapters, providing a unified API
 * for database operations across different vendors (MySQL, PostgreSQL, SQLite, SQL Server).
 */
interface AdapterInterface
{
  // =========================================================================
  // Connection Management
  // =========================================================================

  /**
   * Establish a connection to the database.
   *
   * @param array $config Connection configuration (host, port, database, user, password, etc.)
   * @throws \PDOException If connection fails
   */
  public function connect(array $config): void;

  // =========================================================================
  // Query Execution
  // =========================================================================

  /**
   * Execute a SELECT query and return all results.
   *
   * @param string $sql SQL query with optional placeholders
   * @param array $params Parameters to bind
   * @return array Array of associative arrays
   */
  public function query(string $sql, array $params = []): array;

  /**
   * Execute an INSERT, UPDATE, or DELETE statement.
   *
   * @param string $sql SQL statement
   * @param array $params Parameters to bind
   * @return int Number of affected rows
   */
  public function execute(string $sql, array $params = []): int;

  /**
   * Execute a query and yield results one row at a time (for large datasets).
   *
   * @param string $sql SQL query
   * @param array $params Parameters to bind
   * @return \Generator Yields rows one at a time
   */
  public function streamQuery(string $sql, array $params = []): \Generator;

  // =========================================================================
  // Schema Introspection
  // =========================================================================

  /**
   * Get a list of all tables in the database.
   *
   * @return array List of table names
   */
  public function getTables(): array;

  /**
   * Get the full schema of a table including columns, keys, and indexes.
   *
   * @param string $table Table name
   * @return TableSchema Complete table schema
   */
  public function getTableSchema(string $table): TableSchema;

  /**
   * Get the primary key column(s) for a table.
   *
   * @param string $table Table name
   * @return string|array|null Primary key column(s) or null if none
   */
  public function getPrimaryKey(string $table): string|array|null;

  /**
   * Get foreign key constraints for a table.
   *
   * @param string $table Table name
   * @return array Array of foreign key definitions
   */
  public function getForeignKeys(string $table): array;

  // =========================================================================
  // Transaction Support
  // =========================================================================

  /**
   * Begin a new transaction.
   */
  public function beginTransaction(): void;

  /**
   * Commit the current transaction.
   */
  public function commit(): void;

  /**
   * Roll back the current transaction.
   */
  public function rollback(): void;

  /**
   * Check if currently inside a transaction.
   */
  public function inTransaction(): bool;

  // =========================================================================
  // Batch Operations
  // =========================================================================

  /**
   * Insert multiple rows in a single operation.
   *
   * @param string $table Target table name
   * @param array $columns Column names
   * @param array $rows Array of row data (each row is an array of values)
   * @return int Number of inserted rows
   */
  public function batchInsert(string $table, array $columns, array $rows): int;

  /**
   * Insert rows, or update every non-key column of rows whose key already exists.
   *
   * @param string $table Table name
   * @param array $columns Column names
   * @param array $rows Array of row data
   * @param array $keyColumns Columns that identify a row (the primary key)
   * @return int Rows affected, as the driver counts them (MySQL counts an update as 2)
   */
  public function batchUpsert(string $table, array $columns, array $rows, array $keyColumns): int;

  /**
   * Move a table's ID generators (sequences) past the IDs already in it.
   * Needed where inserting explicit IDs does not advance the generator (PostgreSQL).
   *
   * @param string $table Table name
   */
  public function syncSequences(string $table): void;

  /**
   * Make the table's ID generator hand out values of at least $next from now on,
   * so an app writing during a migration cannot take an ID Mygra is about to insert.
   *
   * @param string $table Table name
   * @param int $next Lowest ID the generator may hand out next
   */
  public function reserveIds(string $table, int $next): void;

  /**
   * Get the total row count of a table with optional WHERE clause.
   *
   * @param string $table Table name
   * @param string|null $where Optional WHERE clause (without "WHERE" keyword)
   * @return int Row count
   */
  public function getRowCount(string $table, ?string $where = null): int;

  // =========================================================================
  // Utility Methods
  // =========================================================================

  /**
   * Quote an identifier (table/column name) for safe use in SQL.
   *
   * @param string $identifier Identifier to quote
   * @return string Quoted identifier
   */
  public function quoteIdentifier(string $identifier): string;

  /**
   * Quote a table name, qualified with the connection's schema where the driver needs it (SQL Server).
   */
  public function quoteTable(string $table): string;

  /**
   * Limit a query that already has an ORDER BY to its first $count rows.
   */
  public function limit(string $sql, int $count): string;

  /**
   * Get the driver name (mysql, pgsql, sqlite, sqlsrv).
   */
  public function getDriverName(): string;
}
