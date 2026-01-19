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
  public function getName(): string
  {
    return 'typecast';
  }

  public function getDescription(): string
  {
    return 'Cast values to specific types (int, float, string, bool, array)';
  }

  public function supports(string $type): bool
  {
    return in_array(strtolower($type), ['int', 'integer', 'float', 'double', 'string', 'bool', 'boolean', 'array']);
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
      default => $value,
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
