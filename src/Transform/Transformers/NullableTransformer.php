<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * Nullable Transformer
 *
 * Handles null value conversion and empty string handling.
 */
class NullableTransformer implements TransformerInterface
{
  public function getName(): string
  {
    return 'nullable';
  }

  public function getOptionNames(): array
  {
    return ['empty_as_null', 'null_values', 'default'];
  }

  public function transform(mixed $value, array $context = []): mixed
  {
    $emptyAsNull = $context['empty_as_null'] ?? true;
    $nullValues = $context['null_values'] ?? ['', 'null', 'NULL', 'nil', 'NIL'];
    $default = $context['default'] ?? null;

    // Convert empty string to null
    if ($emptyAsNull && $value === '') {
      return $default;
    }

    // Check for explicit null representations
    if (is_string($value) && in_array($value, $nullValues, true)) {
      return $default;
    }

    // Return null with default
    if ($value === null) {
      return $default;
    }

    return $value;
  }
}
