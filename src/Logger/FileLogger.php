<?php

declare(strict_types=1);

namespace Mygra\Logger;

/**
 * File Logger
 * 
 * Logs messages to a file with timestamps and log levels.
 */
class FileLogger implements LoggerInterface
{
    private string $logFile;
    private string $minLevel;
    private bool $includeTimestamp;

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
        bool $includeTimestamp = true
    ) {
        $this->logFile = $logFile;
        $this->minLevel = strtolower($minLevel);
        $this->includeTimestamp = $includeTimestamp;

        // Ensure log directory exists
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        // Check minimum level
        if ((self::LEVELS[$level] ?? 0) < (self::LEVELS[$this->minLevel] ?? 0)) {
            return;
        }

        // Interpolate context into message
        $message = $this->interpolate($message, $context);

        // Format the log entry
        $entry = $this->formatEntry($level, $message);

        // Write to file
        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
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

    /**
     * Get the log file path.
     */
    public function getLogFile(): string
    {
        return $this->logFile;
    }

    /**
     * Clear the log file.
     */
    public function clear(): void
    {
        file_put_contents($this->logFile, '');
    }
}
