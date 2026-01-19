<?php

declare(strict_types=1);

namespace Mygra\Validation;

/**
 * Validation Result
 * 
 * Stores validation status and errors for a single row or field.
 */
class ValidationResult
{
  private array $errors = [];

  public function addError(string $field, string $message): void
  {
    $this->errors[$field][] = $message;
  }

  public function isValid(): bool
  {
    return empty($this->errors);
  }

  public function getErrors(): array
  {
    return $this->errors;
  }

  public function getFormattedErrors(): string
  {
    $output = [];
    foreach ($this->errors as $field => $messages) {
      $output[] = "{$field}: " . implode(', ', $messages);
    }
    return implode('; ', $output);
  }
}
