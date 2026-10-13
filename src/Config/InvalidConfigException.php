<?php

declare(strict_types=1);

namespace Mygra\Config;

/**
 * Thrown when a configuration has problems; the message lists every one with its path.
 */
class InvalidConfigException extends \InvalidArgumentException
{
  /**
   * @param string[] $errors One line per problem, each starting with its path
   */
  public function __construct(public readonly array $errors, ?string $file = null)
  {
    $count = count($errors);
    parent::__construct(
      'Invalid configuration' . ($file !== null ? " in {$file}" : '')
        . " ({$count} problem" . ($count === 1 ? '' : 's') . "):\n  - " . implode("\n  - ", $errors),
    );
  }
}
