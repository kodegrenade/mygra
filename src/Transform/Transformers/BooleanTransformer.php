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
  private array $defaultTrueValues = ['1', 'true', 'yes', 'on', 'y', 'active', 'enabled'];
  private array $defaultFalseValues = ['0', 'false', 'no', 'off', 'n', 'inactive', 'disabled', ''];

  public function getName(): string
  {
    return 'boolean';
  }

  public function getDescription(): string
  {
    return 'Convert various values to boolean (1/0, true/false, yes/no, etc.)';
  }

  public function supports(string $type): bool
  {
    return in_array(strtolower($type), ['bool', 'boolean', 'bit']);
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
      default => $isTrue ? 1 : 0,
    };
  }
}
