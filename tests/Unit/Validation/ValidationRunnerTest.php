<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Mygra\Validation\ValidationRunner;

class ValidationRunnerTest extends TestCase
{
  public function testValidateRow()
  {
    $runner = ValidationRunner::withDefaults();

    $row = [
      'email' => 'valid@example.com',
      'age' => 25,
      'name' => ''
    ];

    $rules = [
      'email' => [['validator' => 'email']],
      'age' => [['validator' => 'type', 'options' => ['type' => 'int']]],
      'name' => [['validator' => 'required']]
    ];

    $result = $runner->validateRow($row, $rules);

    $this->assertFalse($result->isValid());
    $this->assertCount(1, $result->getErrors());
    $this->assertArrayHasKey('name', $result->getErrors());
  }

  public function testEmailValidator()
  {
    $runner = ValidationRunner::withDefaults();

    $rules = ['email' => [['validator' => 'email']]];

    $validRow = ['email' => 'test@example.com'];
    $invalidRow = ['email' => 'not-an-email'];

    $this->assertTrue($runner->validateRow($validRow, $rules)->isValid());
    $this->assertFalse($runner->validateRow($invalidRow, $rules)->isValid());
  }
}
