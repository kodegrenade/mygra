<?php

declare(strict_types=1);

namespace Mygra\Tests\Unit\Config;

use Mygra\Config\ConfigLoader;
use Mygra\Config\ConfigValidator;
use Mygra\Config\InvalidConfigException;
use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
  private function config(array $users = [], array $root = []): array
  {
    return array_replace_recursive([
      'connections' => [
        'source' => ['driver' => 'sqlite', 'database' => ':memory:'],
        'target' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'app'],
      ],
      'schemas' => [
        'users' => $users + [
          'source_table' => 'wp_users',
          'target_table' => 'users',
          'fields' => ['ID' => 'id', 'user_email' => 'email'],
        ],
        'posts' => [
          'source_table' => 'wp_posts',
          'target_table' => 'posts',
          'fields' => ['ID' => 'id', 'post_author' => 'author_id', 'post_content' => 'body'],
        ],
      ],
    ], $root);
  }

  /** @return string[] */
  private function errors(array $config): array
  {
    try {
      ConfigValidator::validate($config);
    } catch (InvalidConfigException $e) {
      $this->assertStringContainsString(implode("\n  - ", $e->errors), $e->getMessage());
      return $e->errors;
    }
    return [];
  }

  public function testSampleConfigLoadsCleanly()
  {
    $config = ConfigLoader::load(__DIR__ . '/../../../config/mygra.yaml');

    $this->assertSame(['users'], $config->getSchemaNames());
  }

  public function testValidFullConfigPasses()
  {
    $this->assertSame([], $this->errors($this->config([
      'where' => 'user_status = 0',
      'options' => ['create_target' => true, 'upsert' => false, 'remap_ids' => 'uuid', 'uuid_namespace' => '5b0f8e3a-7c41-4d2e-9a6b-6d7967726101', 'on_error' => 'rollback', 'transaction_mode' => 'single'],
      'joins' => [
        ['table' => 'profiles', 'type' => 'LEFT', 'source_key' => 'ID', 'target_key' => 'user_id', 'fields' => ['bio' => 'bio'], 'join' => [
          ['table' => 'avatars', 'source_key' => 'avatar_id', 'fields' => ['url' => 'avatar']],
        ]],
      ],
      'transforms' => [
        'email' => [['transformer' => 'string', 'options' => ['trim' => true, 'case' => 'Lower', 'operations' => ['strip_tags']]], 'nullable'],
        'bio' => [['json' => ['mode' => 'decode']], ['hash' => ['algorithm' => 'bcrypt', 'cost' => 12]]],
        'avatar' => 'string',
      ],
      'validations' => [
        'email' => [['validator' => 'required'], ['validator' => 'email'], ['validator' => 'regex', 'options' => ['pattern' => '/@/']]],
        'id' => [['validator' => 'type', 'options' => ['type' => 'int']], ['validator' => 'length', 'options' => ['min' => 1, 'max' => 20]]],
      ],
    ], [
      'version' => '2.0',
      'settings' => ['batch_size' => '500', 'on_error' => 'skip', 'transaction_mode' => 'per_batch', 'log_file' => 'logs/m.log', 'log_level' => 'warning'],
      'schemas' => ['posts' => [
        'options' => ['depends_on' => ['users']],
        'references' => [
          'author_id' => 'users',
          'body' => ['schema' => 'posts', 'pattern' => '/\?p=(\d+)/', 'on_missing' => 'replace', 'replacement' => 0],
          'gallery' => ['column' => 'body', 'schema' => 'users', 'pattern' => '/ids="([^"]*)"/'],
        ],
      ]],
    ])));
  }

  public function testTyposGetDidYouMean()
  {
    $errors = $this->errors($this->config(['options' => ['remap_id' => true]], [
      'setings' => [],
      'settings' => ['on_error' => 'abrot'],
      'connections' => ['source' => ['driver' => 'postgress', 'hots' => 'db']],
    ]));

    $this->assertContains('schemas.users.options.remap_id: unknown option (did you mean remap_ids?); allowed: create_target, on_error, transaction_mode, upsert, depends_on, remap_ids, uuid_namespace', $errors);
    $this->assertContains('settings.on_error: \'abrot\' is not allowed (did you mean abort?); use one of: skip, abort, rollback', $errors);
    $this->assertContains('setings: unknown top-level key (did you mean settings?); allowed: version, connections, settings, schemas', $errors);
    $this->assertStringStartsWith("connections.source.driver: unknown driver 'postgress' (did you mean postgres?); use one of: mysql", $this->find($errors, 'connections.source.driver'));
    $this->assertStringStartsWith('connections.source.hots: unknown key (did you mean host?)', $this->find($errors, 'connections.source.hots'));
  }

  public function testCollectsEveryProblemInOneException()
  {
    $errors = $this->errors(['connections' => ['source' => ['driver' => 'mysql']], 'schemas' => [
      'users' => ['source_tabel' => 'x', 'fields' => []],
      'posts' => ['source_table' => 'p', 'target_table' => 'p', 'fields' => ['id']],
    ]]);

    $this->assertContains('connections.target: missing; every configuration needs a source and a target connection', $errors);
    $this->assertContains('schemas.users.source_table: missing; name the table', $errors);
    $this->assertContains('schemas.users.target_table: missing; name the table', $errors);
    $this->assertContains('schemas.users.fields: missing; map at least one source_column: target_column', $errors);
    $this->assertContains('schemas.posts.fields: must be a map of source_column: target_column, got a list', $errors);
    $this->assertNotNull($this->find($errors, 'schemas.users.source_tabel: unknown key (did you mean source_table?)'));
  }

  public function testMissingSections()
  {
    $errors = $this->errors([]);

    $this->assertContains('connections: missing; define connections.source and connections.target', $errors);
    $this->assertNotNull($this->find($errors, 'schemas: missing'));
    $this->assertNotNull($this->find($this->errors($this->config([], ['connections' => ['target' => ['driver' => null]]])), 'connections.target.driver: missing; use one of: mysql'));
  }

  public function testBadEnumValues()
  {
    $errors = $this->errors($this->config(['options' => ['remap_ids' => 'yes', 'upsert' => 'true', 'transaction_mode' => 'batch', 'uuid_namespace' => 'abc']], [
      'settings' => ['batch_size' => 0, 'log_level' => 'verbose', 'transaction_mode' => 'single_tx'],
    ]));

    $this->assertContains("schemas.users.options.remap_ids: 'yes' is not allowed; use one of: true, false, offset, uuid", $errors);
    $this->assertContains("schemas.users.options.upsert: 'true' is not allowed; use one of: true, false", $errors);
    $this->assertContains('settings.batch_size: must be a whole number above 0, got 0', $errors);
    $this->assertContains("settings.log_level: 'verbose' is not allowed; use one of: debug, info, warning, error", $errors);
    $this->assertNotNull($this->find($errors, "settings.transaction_mode: 'single_tx' is not allowed (did you mean single?)"));
    $this->assertNotNull($this->find($errors, 'schemas.users.options.transaction_mode:'));
    $this->assertNotNull($this->find($errors, 'schemas.users.options.uuid_namespace: must be a UUID'));
  }

  public function testUnknownTransformersOptionsAndEnums()
  {
    $errors = $this->errors($this->config(['transforms' => [
      'emial' => ['string'],
      'email' => [
        ['transformer' => 'strng'],
        ['string' => ['case' => 'lowr', 'operations' => ['trim', 'slugify'], 'max_len' => 3]],
        ['transformer' => 'typecast', 'option' => ['type' => 'int']],
        ['typecast' => ['type' => 'number']],
        ['hash' => ['algorithm' => 'sha265']],
        ['json' => ['mode' => 'encod']],
      ],
    ]]));

    $this->assertStringStartsWith("schemas.users.transforms.emial: 'emial' is not a target column of this schema (did you mean email?)", $this->find($errors, 'transforms.emial:'));
    $this->assertStringStartsWith("schemas.users.transforms.email[0].transformer: unknown transformer 'strng' (did you mean string?); use one of:", $this->find($errors, 'email[0]'));
    $this->assertStringStartsWith("schemas.users.transforms.email[1].string.case: 'lowr' is not allowed (did you mean lower?)", $this->find($errors, '.case:'));
    $this->assertStringStartsWith("schemas.users.transforms.email[1].string.operations[1]: 'slugify' is not allowed (did you mean slug?)", $this->find($errors, 'operations[1]'));
    $this->assertStringStartsWith('schemas.users.transforms.email[1].string.max_len: unknown option for the string transformer (did you mean max_length?)', $this->find($errors, 'max_len'));
    $this->assertStringStartsWith('schemas.users.transforms.email[2].option: unknown key (did you mean options?)', $this->find($errors, 'email[2]'));
    $this->assertNotNull($this->find($errors, "email[3].typecast.type: 'number' is not allowed"));
    $this->assertNotNull($this->find($errors, "email[4].hash.algorithm: unknown algorithm 'sha265' (did you mean sha256?)"));
    $this->assertNotNull($this->find($errors, "email[5].json.mode: 'encod' is not allowed (did you mean encode?)"));
  }

  public function testUnknownValidatorsAndOptions()
  {
    $errors = $this->errors($this->config(['validations' => ['email' => [
      ['validator' => 'emial'],
      ['validator' => 'type', 'options' => ['type' => 'integr']],
      ['validator' => 'length', 'options' => ['minimum' => 3]],
      ['validator' => 'regex', 'options' => ['pattern' => 'no-delimiters']],
      ['valdator' => 'required'],
    ]]]));

    $this->assertStringStartsWith("schemas.users.validations.email[0].validator: unknown validator 'emial' (did you mean email?); use one of: required, email, length, type, regex", $this->find($errors, 'email[0]'));
    $this->assertNotNull($this->find($errors, "email[1].options.type: 'integr' is not allowed (did you mean integer?)"));
    $this->assertNotNull($this->find($errors, 'email[2].options.minimum: unknown option for the length validator; allowed: min, max'));
    $this->assertNotNull($this->find($errors, 'email[3].options.pattern: must be a valid regex'));
    $this->assertNotNull($this->find($errors, 'email[4].valdator: unknown key (did you mean validator?)'));
  }

  public function testJoinsAndReferences()
  {
    $errors = $this->errors($this->config([
      'joins' => [['type' => 'LEFT', 'fields' => ['bio' => 'bio']], ['table' => 'a', 'join' => [['tabel' => 'b']]]],
    ], ['schemas' => ['posts' => ['references' => [
      'author_id' => 'user',
      'auhtor_id' => ['schema' => 'users', 'on_missing' => 'skip'],
      'body' => ['schema' => 'posts', 'pattern' => '/\?p=\d+/'],
    ]]]]));

    $this->assertContains('schemas.users.joins[0].table: missing; name the table to join', $errors);
    $this->assertNotNull($this->find($errors, 'schemas.users.joins[1].join[0].tabel: unknown key (did you mean table?)'));
    $this->assertNotNull($this->find($errors, 'schemas.users.joins[1].join[0].table: missing'));
    $this->assertNotNull($this->find($errors, "schemas.posts.references.author_id: unknown schema 'user' (did you mean users?)"));
    $this->assertNotNull($this->find($errors, "schemas.posts.references.auhtor_id: 'auhtor_id' is not a target column of this schema (did you mean author_id?)"));
    $this->assertContains("schemas.posts.references.auhtor_id.on_missing: must be error or null, got 'skip'", $errors);
    $this->assertNotNull($this->find($errors, 'schemas.posts.references.body.pattern: must be a valid regex with a capture group'));
  }

  public function testYamlNullOnMissingMeansNull()
  {
    $this->assertSame([], $this->errors($this->config([], ['schemas' => ['posts' => ['references' => [
      'author_id' => ['schema' => 'users', 'on_missing' => null],
    ]]]])));
  }

  private function find(array $errors, string $needle): ?string
  {
    foreach ($errors as $error) {
      if (str_contains($error, $needle)) {
        return $error;
      }
    }
    $this->fail("No error containing '{$needle}' in:\n" . implode("\n", $errors));
  }
}
