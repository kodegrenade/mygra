<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Transform;

use Mygra\Transform\Transformers\DateTransformer;
use Mygra\Transform\TransformPipeline;
use PHPUnit\Framework\TestCase;

class TransformPipelineTest extends TestCase
{
  public function testPipelineExecutesBatch()
  {
    $pipeline = TransformPipeline::withDefaults();

    $batch = [
      ['username' => 'testuser', 'email' => 'TEST@EXAMPLE.COM', 'status' => '1'],
      ['username' => 'admin', 'email' => 'ADMIN@MYGRA.APP', 'status' => '0'],
    ];

    $mappings = [
      'username' => 'user_login',
      'email' => 'user_email',
      'status' => 'user_status',
    ];

    $pipeline->forField('user_email', [['string' => ['case' => 'lower']]]);
    $pipeline->forField('user_status', [['boolean' => ['output_format' => 'bool']]]);

    $result = iterator_to_array($pipeline->processMany($batch, $mappings));

    $this->assertCount(2, $result);

    $this->assertEquals('testuser', $result[0]['user_login']);
    $this->assertEquals('test@example.com', $result[0]['user_email']);
    $this->assertTrue($result[0]['user_status']);

    $this->assertEquals('admin', $result[1]['user_login']);
    $this->assertEquals('admin@mygra.app', $result[1]['user_email']);
    $this->assertFalse($result[1]['user_status']);
  }

  public function testDateTransformer()
  {
    $transformer = new DateTransformer();
    $options = ['output_format' => 'Y-m-d'];

    $this->assertEquals('2023-10-27', $transformer->transform('2023-10-27 15:30:00', $options));
    $this->assertEquals('2023-01-01', $transformer->transform(1672531200, $options));
  }

  public function testColumnsNamedLikeOptionsDoNotBecomeOptions()
  {
    $pipeline = TransformPipeline::withDefaults();
    $pipeline->forField('name', ['string']);
    $pipeline->forField('qty', [['typecast' => ['type' => 'int']]]);

    $row = ['name' => 'ada', 'prefix' => 'DR. ', 'qty' => '7', 'type' => 'bool'];
    $result = $pipeline->process($row, ['name' => 'name', 'qty' => 'qty']);

    $this->assertSame(['name' => 'ada', 'qty' => 7], $result);
  }

  public function testStringOptionsApplyAlongsideListedOperations()
  {
    $pipeline = TransformPipeline::withDefaults();
    $pipeline->forField('username', [['string' => ['operations' => ['trim'], 'case' => 'lower', 'max_length' => 3]]]);

    $this->assertSame(['username' => 'ada'], $pipeline->process(['u' => '  ADALOVELACE '], ['u' => 'username']));
  }
}
