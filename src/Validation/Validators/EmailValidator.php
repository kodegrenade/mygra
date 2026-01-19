<?php

declare(strict_types=1);

namespace Mygra\Validation\Validators;

use Mygra\Validation\ValidatorInterface;

class EmailValidator implements ValidatorInterface
{
  public function validate(mixed $value, array $options = []): bool
  {
    if ($value === null || $value === '') {
      return true; // Use RequiredValidator for presence check
    }

    return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
  }

  public function getMessage(): string
  {
    return "Invalid email format";
  }

  public function getName(): string
  {
    return 'email';
  }
}
