<?php

declare(strict_types=1);

namespace Mygra\Migration;

use Mygra\Config\SchemaConfig;

/**
 * How a migration runs. Global values come from settings and command-line flags;
 * forSchema() applies a schema's own `options:` on top.
 */
final class RunOptions
{
  private const KEYS = ['create_target', 'on_error', 'transaction_mode', 'resume', 'upsert'];

  public function __construct(
    public readonly bool $createTarget = false,
    /** skip | abort ('rollback' is accepted as the legacy name for abort) */
    public readonly string $onError = 'skip',
    /** per_batch | single */
    public readonly string $transactionMode = 'per_batch',
    public readonly bool $resume = false,
    public readonly bool $upsert = false,
  ) {
    if (!in_array($onError, ['skip', 'abort', 'rollback'], true)) {
      throw new \InvalidArgumentException("on_error must be skip or abort, got '{$onError}'");
    }
    if (!in_array($transactionMode, ['per_batch', 'single'], true)) {
      throw new \InvalidArgumentException("transaction_mode must be per_batch or single, got '{$transactionMode}'");
    }
  }

  /**
   * @param array<string, mixed> $options create_target, on_error, transaction_mode, resume, upsert
   */
  public static function fromArray(array $options, ?self $defaults = null): self
  {
    $unknown = array_diff(array_keys($options), self::KEYS);
    if ($unknown) {
      throw new \InvalidArgumentException('Unknown run option(s): ' . implode(', ', $unknown));
    }
    $defaults ??= new self();

    return new self(
      createTarget: (bool) ($options['create_target'] ?? $defaults->createTarget),
      onError: (string) ($options['on_error'] ?? $defaults->onError),
      transactionMode: (string) ($options['transaction_mode'] ?? $defaults->transactionMode),
      resume: (bool) ($options['resume'] ?? $defaults->resume),
      upsert: (bool) ($options['upsert'] ?? $defaults->upsert),
    );
  }

  /**
   * These options with the schema's own overrides applied. A flag given on the command line
   * (create, upsert, resume) stays on even if the schema does not set it.
   */
  public function forSchema(SchemaConfig $schema): self
  {
    return new self(
      createTarget: $this->createTarget || (bool) $schema->getOption('create_target', false),
      onError: (string) $schema->getOption('on_error', $this->onError),
      transactionMode: (string) $schema->getOption('transaction_mode', $this->transactionMode),
      resume: $this->resume,
      upsert: $this->upsert || (bool) $schema->getOption('upsert', false),
    );
  }

  public function abortsOnRowError(): bool
  {
    return $this->onError !== 'skip';
  }
}
