<?php

declare(strict_types=1);

namespace Mygra\Validation\Validators;

use Mygra\Validation\ValidatorInterface;

class LengthValidator implements ValidatorInterface
{
  private string $message = '';

  public function validate(mixed $value, array $options = []): bool
  {
    if ($value === null || $value === '') {
      return true;
    }

    $length = is_string($value) ? mb_strlen($value) : count((array)$value);
    $min = $options['min'] ?? null;
    $max = $options['max'] ?? null;

    if ($min !== null && $length < $min) {
      $this->message = "Minimum length is {$min}";
      return false;
    }

    if ($max !== null && $length > $max) {
      $this->message = "Maximum length is {$max}";
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
    return 'length';
  }
}
