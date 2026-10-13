<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Validation;

use Mygra\Validation\ValidationRunner;
use Mygra\Validation\Validators\TypeValidator;
use PHPUnit\Framework\TestCase;

class StrictValidationTest extends TestCase
{
  public function testUnknownValidatorThrows()
  {
    $this->expectExceptionMessage("Unknown validator 'emial' for field 'email'");
    ValidationRunner::withDefaults()->validateRow(['email' => 'a@b.c'], ['email' => [['validator' => 'emial']]]);
  }

  public function testUnknownTypeThrows()
  {
    $this->expectExceptionMessage("Unknown type 'integr'");
    (new TypeValidator())->validate('1', ['type' => 'integr']);
  }
}
