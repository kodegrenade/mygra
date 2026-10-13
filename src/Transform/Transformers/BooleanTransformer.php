<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * Boolean Transformer
 *
 * Converts various representations of boolean values.
 */
class BooleanTransformer implements TransformerInterface
{
  public const OUTPUT_FORMATS = ['int', 'integer', 'bool', 'boolean', 'string', 'yes_no'];

  private array $defaultTrueValues = ['1', 'true', 'yes', 'on', 'y', 'active', 'enabled'];
  private array $defaultFalseValues = ['0', 'false', 'no', 'off', 'n', 'inactive', 'disabled', ''];

  public function getName(): string
  {
    return 'boolean';
  }

  public function getOptionNames(): array
  {
    return ['true_values', 'false_values', 'output_format', 'null_value'];
  }

  public function transform(mixed $value, array $context = []): mixed
  {
    if ($value === null) {
      return $context['null_value'] ?? null;
    }

    $trueValues = $context['true_values'] ?? $this->defaultTrueValues;
    $falseValues = $context['false_values'] ?? $this->defaultFalseValues;
    $outputFormat = $context['output_format'] ?? 'int'; // int, bool, string

    // Convert to comparable string
    $stringValue = strtolower(trim((string) $value));

    $isTrue = in_array($stringValue, array_map('strtolower', $trueValues), true);
    $isFalse = in_array($stringValue, array_map('strtolower', $falseValues), true);

    if (!$isTrue && !$isFalse) {
      // Default to truthy check if not in either list
      $isTrue = (bool) $value;
    }

    return match ($outputFormat) {
      'int', 'integer' => $isTrue ? 1 : 0,
      'bool', 'boolean' => $isTrue,
      'string' => $isTrue ? 'true' : 'false',
      'yes_no' => $isTrue ? 'yes' : 'no',
      default => throw new \InvalidArgumentException(
        "Unknown boolean output_format '{$outputFormat}'; use one of: " . implode(', ', self::OUTPUT_FORMATS),
      ),
    };
  }
}
