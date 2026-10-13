<?php

declare(strict_types=1);

namespace Mygra\Validation;

/**
 * Validator Interface
 *
 * Defines the contract for all data validators.
 */
interface ValidatorInterface
{
  /**
   * Validate a value.
   *
   * @param mixed $value The value to validate
   * @param array $options Validator options
   * @return bool True if valid, false otherwise
   */
  public function validate(mixed $value, array $options = []): bool;

  /**
   * Get the validation error message.
   */
  public function getMessage(): string;

  /**
   * Get the validator name.
   */
  public function getName(): string;

  /**
   * Option names this validator reads; the config validator rejects any other.
   *
   * @return string[]
   */
  public function getOptionNames(): array;
}
