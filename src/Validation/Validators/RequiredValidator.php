<?php

declare(strict_types=1);

namespace Mygra\Validation\Validators;

use Mygra\Validation\ValidatorInterface;

class RequiredValidator implements ValidatorInterface
{
  public function validate(mixed $value, array $options = []): bool
  {
    if ($value === null) {
      return false;
    }

    if (is_string($value) && trim($value) === '') {
      return false;
    }

    if (is_array($value) && empty($value)) {
      return false;
    }

    return true;
  }

  public function getMessage(): string
  {
    return "This field is required";
  }

  public function getName(): string
  {
    return 'required';
  }
}
