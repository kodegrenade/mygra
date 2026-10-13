<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Transform;

use Mygra\Transform\Transformers\StringTransformer;
use Mygra\Transform\TransformPipeline;
use PHPUnit\Framework\TestCase;

class StrictTransformersTest extends TestCase
{
  public function testUnknownTransformerFailsWhenConfiguredNotPerRow()
  {
    $this->expectExceptionMessage("Unknown transformer 'strng' for field 'email'");
    TransformPipeline::withDefaults()->forField('email', ['strng']);
  }

  public function testUnknownStringCaseThrows()
  {
    $this->expectExceptionMessage("Unknown case 'lowr'");
    (new StringTransformer())->transform('A', ['case' => 'lowr']);
  }

  public function testUnknownStringOperationThrows()
  {
    $this->expectExceptionMessage("Unknown string operation 'slugify'");
    (new StringTransformer())->transform('A', ['operations' => ['slugify']]);
  }
}
