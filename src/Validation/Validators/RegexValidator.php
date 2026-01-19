<?php

declare(strict_types=1);

namespace Mygra\Validation\Validators;

use Mygra\Validation\ValidatorInterface;

class RegexValidator implements ValidatorInterface
{
  public function validate(mixed $value, array $options = []): bool
  {
    if ($value === null || $value === '') {
      return true;
    }

    $pattern = $options['pattern'] ?? null;
    if (!$pattern) {
      return true;
    }

    return (bool) preg_match($pattern, (string)$value);
  }

  public function getMessage(): string
  {
    return "Value does not match required pattern";
  }

  public function getName(): string
  {
    return 'regex';
  }
}
