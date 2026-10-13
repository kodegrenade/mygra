<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * Date Transformer
 *
 * Transforms date/datetime values between formats.
 * Supports Unix timestamps, various date string formats, and timezone conversion.
 */
class DateTransformer implements TransformerInterface
{
  public function getName(): string
  {
    return 'date';
  }

  public function getOptionNames(): array
  {
    return ['input_format', 'output_format', 'input_timezone', 'output_timezone', 'default', 'on_error'];
  }

  public function transform(mixed $value, array $context = []): mixed
  {
    // Handle null/empty values
    if ($value === null || $value === '') {
      return $context['default'] ?? null;
    }

    $inputFormat = $context['input_format'] ?? null;
    $outputFormat = $context['output_format'] ?? 'Y-m-d H:i:s';
    $inputTimezone = $context['input_timezone'] ?? null;
    $outputTimezone = $context['output_timezone'] ?? null;

    try {
      // Parse input
      if (is_numeric($value)) {
        // Unix timestamp
        $date = new \DateTime("@{$value}");
      } elseif ($inputFormat) {
        // Specific input format
        $date = \DateTime::createFromFormat($inputFormat, (string) $value);
        if ($date === false) {
          throw new \InvalidArgumentException("Cannot parse date with format: {$inputFormat}");
        }
      } else {
        // Auto-detect format
        $date = new \DateTime((string) $value);
      }

      // Handle input timezone
      if ($inputTimezone && is_numeric($value) === false) {
        $date->setTimezone(new \DateTimeZone($inputTimezone));
      }

      // Convert to output timezone if specified
      if ($outputTimezone) {
        $date->setTimezone(new \DateTimeZone($outputTimezone));
      }

      return $date->format($outputFormat);
    } catch (\Exception $e) {
      // Return original value or default on error
      if (isset($context['on_error'])) {
        return $context['on_error'];
      }
      throw $e;
    }
  }
}
