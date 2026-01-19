<?php

declare(strict_types=1);

namespace Mygra\Validation\Validators;

use Mygra\Validation\ValidatorInterface;

class TypeValidator implements ValidatorInterface
{
  private string $message = '';

  public function validate(mixed $value, array $options = []): bool
  {
    if ($value === null) {
      return true;
    }

    $type = $options['type'] ?? 'string';

    $valid = match ($type) {
      'string' => is_string($value),
      'int', 'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
      'float', 'double', 'numeric' => is_numeric($value),
      'bool', 'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
      'array' => is_array($value),
      default => true,
    };

    if (!$valid) {
      $this->message = "Expected type {$type}";
      return false;
    }

    return true;
  }

  public function getMessage(): string
  {
    return $this->message;
  }

  public function getName(): string
  {
    return 'type';
  }
}
