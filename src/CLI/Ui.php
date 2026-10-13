<?php

declare(strict_types=1);

namespace Mygra\CLI;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Terminal output in one consistent style for every command:
 *
 *    INFO  Migrating 2 schemas · sqlite src.db → pgsql localhost/app
 *
 *   users ............................................ 2 rows · 3ms  DONE
 *     ⤷ New IDs: old ID + 1
 *
 * Colour follows Symfony's detection (TTY, --ansi/--no-ansi, NO_COLOR); errors go to stderr.
 */
final class Ui
{
  private const BADGES = [
    'info' => ['INFO', 'white', 'blue'],
    'done' => ['DONE', 'black', 'green'],
    'warn' => ['WARN', 'black', 'yellow'],
    'error' => ['ERROR', 'white', 'red'],
  ];

  /** Task status => [label, colour] */
  private const STATUSES = [
    'done' => ['DONE', 'green'],
    'warn' => ['WARN', 'yellow'],
    'fail' => ['FAIL', 'red'],
    'skip' => ['SKIP', 'gray'],
    'ok' => ['OK', 'green'],
  ];

  private readonly int $width;

  public function __construct(private readonly OutputInterface $output)
  {
    $this->width = min((new Terminal())->getWidth(), 110);

    $formatter = $output->getFormatter();
    foreach (self::BADGES as $name => [, $fg, $bg]) {
      $formatter->setStyle("badge-{$name}", new OutputFormatterStyle($fg, $bg, ['bold']));
    }
    foreach (self::STATUSES as $name => [, $fg]) {
      $formatter->setStyle("status-{$name}", new OutputFormatterStyle($fg, null, ['bold']));
    }
    $formatter->setStyle('muted', new OutputFormatterStyle('gray'));
  }

  public function info(string $message): void
  {
    $this->badge('info', $message);
  }

  public function done(string $message): void
  {
    $this->badge('done', $message);
  }

  public function warn(string $message): void
  {
    $this->badge('warn', $message);
  }

  public function error(string $message): void
  {
    $this->badge('error', $message, $this->stderr());
  }

  /**
   * One unit of work: "  label ........ detail  STATUS".
   *
   * @param string $status done, warn, fail, skip or ok
   */
  public function task(string $label, string $detail, string $status): void
  {
    [$word] = self::STATUSES[$status];
    $right = ($detail !== '' ? "<muted>{$detail}</muted>  " : '') . "<status-{$status}>{$word}</status-{$status}>";
    $used = Helper::width(Helper::removeDecoration($this->output->getFormatter(), "  {$label} {$right}")) + 1;
    $dots = str_repeat('.', max(3, $this->width - $used - 2));

    $this->output->writeln("  {$label} <muted>{$dots}</muted> {$right}");
  }

  /**
   * A line under the task above it.
   */
  public function detail(string $text, bool $muted = true): void
  {
    $this->output->writeln($muted ? "    <muted>⤷ {$text}</muted>" : "    ⤷ {$text}");
  }

  public function line(string $text = ''): void
  {
    $this->output->writeln($text === '' ? '' : "  {$text}");
  }

  /**
   * Live progress on the current line, replaced by the task line once the work is done.
   */
  public function progress(string $label): ProgressBar
  {
    $bar = new ProgressBar($this->output);
    $bar->setFormat("  {$label} <muted>%current%/%max%</muted> [%bar%] %percent:3s%% <muted>%elapsed:6s%</muted>");
    $bar->setBarCharacter('<fg=green>━</>');
    $bar->setEmptyBarCharacter('<muted>━</muted>');
    $bar->setProgressCharacter('<fg=green>━</>');
    $bar->setBarWidth(28);
    return $bar;
  }

  /**
   * "1 row", "2 rows".
   */
  public static function count(int $n, string $noun): string
  {
    return number_format($n) . ' ' . $noun . ($n === 1 ? '' : 's');
  }

  public static function duration(float $seconds): string
  {
    return match (true) {
      $seconds < 0.001 => '<1ms',
      $seconds < 1 => round($seconds * 1000) . 'ms',
      $seconds < 60 => round($seconds, 1) . 's',
      default => floor($seconds / 60) . 'm ' . round(fmod($seconds, 60)) . 's',
    };
  }

  /**
   * A connection as people recognise it, never with the password: "pgsql localhost/app", "sqlite app.db".
   */
  public static function connection(array $config): string
  {
    $driver = $config['driver'] ?? '?';
    $database = (string) ($config['database'] ?? '');
    if (str_starts_with($driver, 'sqlite')) {
      return "{$driver} " . ($database === ':memory:' ? $database : basename($database));
    }
    return "{$driver} " . ($config['host'] ?? 'localhost') . "/{$database}";
  }

  /**
   * A path relative to the working directory when it is inside it.
   */
  public static function path(string $path): string
  {
    $cwd = rtrim((string) getcwd(), '/') . '/';
    return str_starts_with($path, $cwd) ? substr($path, strlen($cwd)) : $path;
  }

  private function badge(string $kind, string $message, ?OutputInterface $to = null): void
  {
    [$word] = self::BADGES[$kind];
    $to ??= $this->output;
    $to->writeln('');
    $to->writeln("  <badge-{$kind}> {$word} </badge-{$kind}> {$message}");
    $to->writeln('');
  }

  private function stderr(): OutputInterface
  {
    if (!$this->output instanceof ConsoleOutputInterface) {
      return $this->output;
    }
    // stderr has its own formatter; give it the same styles
    $stderr = $this->output->getErrorOutput();
    foreach (self::BADGES as $name => [, $fg, $bg]) {
      $stderr->getFormatter()->setStyle("badge-{$name}", new OutputFormatterStyle($fg, $bg, ['bold']));
    }
    return $stderr;
  }
}
