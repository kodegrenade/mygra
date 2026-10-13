<?php

declare(strict_types=1);

namespace Mygra\Transform;

/**
 * Transformer Interface
 *
 * Defines the contract for data transformers that convert values
 * from source format to target format during migration.
 */
interface TransformerInterface
{
  /**
   * Transform a value.
   *
   * @param mixed $value The value to transform
   * @param array $context Additional context (row data, options, etc.)
   * @return mixed The transformed value
   */
  public function transform(mixed $value, array $context = []): mixed;

  /**
   * Get the unique name of this transformer.
   */
  public function getName(): string;

  /**
   * Option names this transformer reads; the config validator rejects any other.
   *
   * @return string[]
   */
  public function getOptionNames(): array;
}
