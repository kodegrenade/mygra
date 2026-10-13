<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * Type Cast Transformer
 *
 * Casts values to specific PHP types.
 */
class TypeCastTransformer implements TransformerInterface
{
  public const TYPES = ['int', 'integer', 'float', 'double', 'decimal', 'string', 'bool', 'boolean', 'array'];

  public function getName(): string
  {
    return 'typecast';
  }

  public function getOptionNames(): array
  {
    return ['type', 'preserve_null'];
  }

  public function transform(mixed $value, array $context = []): mixed
  {
    if ($value === null && ($context['preserve_null'] ?? true)) {
      return null;
    }

    $type = $context['type'] ?? 'string';

    return match (strtolower($type)) {
      'int', 'integer' => (int) $value,
      'float', 'double', 'decimal' => (float) $value,
      'string' => (string) $value,
      'bool', 'boolean' => $this->toBoolean($value),
      'array' => (array) $value,
      default => throw new \InvalidArgumentException("Unknown typecast type '{$type}'; use one of: " . implode(', ', self::TYPES)),
    };
  }

  private function toBoolean(mixed $value): bool
  {
    if (is_bool($value)) {
      return $value;
    }

    if (is_string($value)) {
      $lower = strtolower(trim($value));
      return in_array($lower, ['1', 'true', 'yes', 'on', 'y'], true);
    }

    return (bool) $value;
  }
}
