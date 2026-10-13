<?php

declare(strict_types=1);

namespace Mygra\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;

/**
 * File Logger
 *
 * Logs messages to a file with timestamps and log levels.
 */
class FileLogger extends AbstractLogger
{
  private string $logFile;
  private string $minLevel;
  private bool $includeTimestamp;
  /** @var array<string, resource> Open handles by resolved path ({date}/{level} can give several) */
  private array $handles = [];

  private const LEVELS = [
    'debug' => 0,
    'info' => 1,
    'notice' => 2,
    'warning' => 3,
    'error' => 4,
    'critical' => 5,
    'alert' => 6,
    'emergency' => 7,
  ];

  public function __construct(
    string $logFile,
    string $minLevel = 'debug',
    bool $includeTimestamp = true,
  ) {
    $minLevel = strtolower($minLevel);
    if (!isset(self::LEVELS[$minLevel])) {
      throw new \InvalidArgumentException(
        "Unknown log level '{$minLevel}'; use one of: " . implode(', ', array_keys(self::LEVELS)),
      );
    }

    $this->logFile = $logFile;
    $this->minLevel = $minLevel;
    $this->includeTimestamp = $includeTimestamp;
  }

  public function __destruct()
  {
    foreach ($this->handles as $handle) {
      fclose($handle);
    }
  }

  public function log($level, string|\Stringable $message, array $context = []): void
  {
    $level = strtolower((string) $level);
    if (!isset(self::LEVELS[$level])) {
      throw new InvalidArgumentException(
        "Unknown log level '{$level}'; use one of: " . implode(', ', array_keys(self::LEVELS)),
      );
    }

    // Check minimum level
    if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
      return;
    }

    // Interpolate context into message
    $message = $this->interpolate((string) $message, $context);

    // Format the log entry
    $entry = $this->formatEntry($level, $message);

    fwrite($this->handle($this->resolveLogPath($level)), $entry);
  }

  /**
   * Open handle for a resolved path, opened (and its directory created) on first use.
   *
   * @return resource
   */
  private function handle(string $path)
  {
    if (!isset($this->handles[$path])) {
      $dir = dirname($path);
      if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new \RuntimeException("Cannot create log directory: {$dir}");
      }
      $handle = fopen($path, 'a');
      if ($handle === false) {
        throw new \RuntimeException("Cannot open log file: {$path}");
      }
      $this->handles[$path] = $handle;
    }
    return $this->handles[$path];
  }

  /**
   * Resolve dynamic placeholders in the log path.
   */
  private function resolveLogPath(string $level): string
  {
    $replacements = [
      '{date}' => date('Y-m-d'),
      '{level}' => $level,
      '{year}' => date('Y'),
      '{month}' => date('m'),
      '{day}' => date('d'),
    ];

    return strtr($this->logFile, $replacements);
  }

  /**
   * Interpolate context values into the message placeholders.
   */
  private function interpolate(string $message, array $context): string
  {
    $replace = [];
    foreach ($context as $key => $val) {
      if (is_string($val) || (is_object($val) && method_exists($val, '__toString'))) {
        $replace['{' . $key . '}'] = $val;
      } elseif (is_array($val) || is_object($val)) {
        $replace['{' . $key . '}'] = json_encode($val);
      } else {
        $replace['{' . $key . '}'] = (string) $val;
      }
    }
    return strtr($message, $replace);
  }

  /**
   * Format a log entry.
   */
  private function formatEntry(string $level, string $message): string
  {
    $parts = [];

    if ($this->includeTimestamp) {
      $parts[] = '[' . date('Y-m-d H:i:s') . ']';
    }

    $parts[] = '[' . strtoupper($level) . ']';
    $parts[] = $message;

    return implode(' ', $parts) . PHP_EOL;
  }
}
