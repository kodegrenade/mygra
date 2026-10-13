<?php

declare(strict_types=1);

namespace Mygra\Validation;

use Mygra\Validation\Validators\EmailValidator;
use Mygra\Validation\Validators\LengthValidator;
use Mygra\Validation\Validators\RegexValidator;
use Mygra\Validation\Validators\RequiredValidator;
use Mygra\Validation\Validators\TypeValidator;

/**
 * Validation Runner
 *
 * Orchestrates the validation of data rows against rules.
 */
class ValidationRunner
{
  /** @var array<string, ValidatorInterface> */
  private array $validators = [];

  public function __construct()
  {
    $this->registerDefaultValidators();
  }

  /**
   * Create a ValidationRunner with default validators.
   */
  public static function withDefaults(): self
  {
    return new self();
  }

  /**
   * Register a custom validator.
   */
  public function register(ValidatorInterface $validator): self
  {
    $this->validators[$validator->getName()] = $validator;
    return $this;
  }

  /**
   * Validate a single row against field rules.
   *
   * @param array $row The data row to validate
   * @param array $rules Field rules: ['field' => [['validator' => 'name', 'options' => []], ...]]
   * @return ValidationResult
   */
  public function validateRow(array $row, array $rules): ValidationResult
  {
    $result = new ValidationResult();

    foreach ($rules as $field => $fieldRules) {
      $value = $row[$field] ?? null;

      foreach ($fieldRules as $rule) {
        $name = $rule['validator'] ?? null;
        $options = $rule['options'] ?? [];

        if (!is_string($name) || !isset($this->validators[$name])) {
          throw new \InvalidArgumentException(
            "Unknown validator '" . (is_string($name) ? $name : '') . "' for field '{$field}'; use one of: "
              . implode(', ', array_keys($this->validators)),
          );
        }

        $validator = $this->validators[$name];
        if (!$validator->validate($value, $options)) {
          $result->addError($field, $validator->getMessage());
        }
      }
    }

    return $result;
  }

  /**
   * Get all registered validators.
   *
   * @return array<string, ValidatorInterface>
   */
  public function getValidators(): array
  {
    return $this->validators;
  }

  private function registerDefaultValidators(): void
  {
    $this->register(new RequiredValidator());
    $this->register(new EmailValidator());
    $this->register(new LengthValidator());
    $this->register(new TypeValidator());
    $this->register(new RegexValidator());
  }
}
