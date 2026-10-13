<?php

declare(strict_types=1);

namespace Mygra\Config;

use Mygra\Adapters\AdapterFactory;
use Mygra\Support\Uuid;
use Mygra\Transform\TransformerInterface;
use Mygra\Transform\Transformers\BooleanTransformer;
use Mygra\Transform\Transformers\JsonTransformer;
use Mygra\Transform\Transformers\StringTransformer;
use Mygra\Transform\Transformers\TypeCastTransformer;
use Mygra\Transform\TransformPipeline;
use Mygra\Validation\ValidationRunner;
use Mygra\Validation\ValidatorInterface;
use Mygra\Validation\Validators\TypeValidator;

/**
 * Config Validator
 *
 * Checks a whole configuration once, at load time, so a typo fails before anything runs instead of
 * being silently ignored. Collects every problem and throws one InvalidConfigException listing them.
 */
final class ConfigValidator
{
  private const TOP_KEYS = ['version', 'connections', 'settings', 'schemas'];
  private const CONNECTION_KEYS = [
    'driver', 'host', 'port', 'database', 'user', 'password', 'charset', 'schema', 'options',
    'journal_mode', 'synchronous', 'pdo_driver', 'trust_server_certificate',
  ];
  private const SETTINGS = ['batch_size', 'on_error', 'transaction_mode', 'log_file', 'log_level'];
  private const ON_ERROR = ['skip', 'abort', 'rollback'];
  private const TRANSACTION_MODES = ['per_batch', 'single'];
  private const LOG_LEVELS = ['debug', 'info', 'warning', 'error'];
  private const SCHEMA_KEYS = [
    'source_table', 'target_table', 'fields', 'transforms', 'validations', 'where', 'joins', 'references', 'options',
  ];
  private const OPTIONS = ['create_target', 'on_error', 'transaction_mode', 'upsert', 'depends_on', 'remap_ids', 'uuid_namespace'];
  private const JOIN_KEYS = ['table', 'type', 'source_key', 'target_key', 'fields', 'where', 'join'];
  private const REFERENCE_KEYS = ['schema', 'column', 'pattern', 'on_missing', 'replacement'];
  // Listed first so a typo's suggestion prefers these over the rarer hash_algos() names
  private const COMMON_HASHES = ['md5', 'sha1', 'sha256', 'sha512', 'bcrypt', 'argon2i', 'argon2id'];

  /** @var string[] */
  private array $errors = [];

  /** @var array<string, TransformerInterface> */
  private array $transformers;

  /** @var array<string, ValidatorInterface> */
  private array $validators;

  private function __construct()
  {
    $this->transformers = TransformPipeline::withDefaults()->getTransformers();
    $this->validators = ValidationRunner::withDefaults()->getValidators();
  }

  /**
   * @throws InvalidConfigException listing every problem found
   */
  public static function validate(array $config, ?string $file = null): void
  {
    $errors = (new self())->check($config);
    if ($errors !== []) {
      throw new InvalidConfigException($errors, $file);
    }
  }

  /**
   * @return string[]
   */
  private function check(array $config): array
  {
    $this->keys($config, self::TOP_KEYS, '', 'top-level key');
    $this->connections($config['connections'] ?? null);

    if (isset($config['settings']) && $this->isMap($config['settings'], 'settings', 'a map of settings')) {
      $this->settings($config['settings']);
    }

    $schemas = $config['schemas'] ?? null;
    if ($schemas === null || $schemas === []) {
      $this->errors[] = 'schemas: missing; define at least one schema (source_table, target_table, fields)';
    } elseif ($this->isMap($schemas, 'schemas', 'a map of schema name: schema')) {
      foreach ($schemas as $name => $schema) {
        $this->schema((string) $name, $schema, array_map('strval', array_keys($schemas)));
      }
    }

    return $this->errors;
  }

  private function connections(mixed $connections): void
  {
    if ($connections === null) {
      $this->errors[] = 'connections: missing; define connections.source and connections.target';
      return;
    }
    if (!$this->isMap($connections, 'connections', 'a map with source and target')) {
      return;
    }
    $this->keys($connections, ['source', 'target'], 'connections', 'connection');

    $drivers = AdapterFactory::getSupportedDrivers();
    foreach (['source', 'target'] as $which) {
      $path = "connections.{$which}";
      $connection = $connections[$which] ?? null;
      if ($connection === null) {
        $this->errors[] = "{$path}: missing; every configuration needs a source and a target connection";
        continue;
      }
      if (!$this->isMap($connection, $path, 'a map of connection settings')) {
        continue;
      }
      $this->keys($connection, self::CONNECTION_KEYS, $path);

      $driver = $connection['driver'] ?? null;
      if (!is_string($driver) || $driver === '') {
        $this->errors[] = "{$path}.driver: missing; use one of: " . implode(', ', $drivers);
      } elseif (!AdapterFactory::isSupported($driver)) {
        $this->errors[] = "{$path}.driver: unknown driver '{$driver}'" . self::suggest($driver, $drivers)
          . '; use one of: ' . implode(', ', $drivers);
      }
      if (isset($connection['options']) && !is_array($connection['options'])) {
        $this->errors[] = "{$path}.options: must be a map of PDO attributes";
      }
    }
  }

  private function settings(array $settings): void
  {
    $this->keys($settings, self::SETTINGS, 'settings', 'setting');

    if (isset($settings['batch_size'])) {
      $this->positiveInt($settings['batch_size'], 'settings.batch_size');
    }
    if (isset($settings['on_error'])) {
      $this->oneOf($settings['on_error'], self::ON_ERROR, 'settings.on_error');
    }
    if (isset($settings['transaction_mode'])) {
      $this->oneOf($settings['transaction_mode'], self::TRANSACTION_MODES, 'settings.transaction_mode');
    }
    if (isset($settings['log_file']) && (!is_string($settings['log_file']) || $settings['log_file'] === '')) {
      $this->errors[] = 'settings.log_file: must be a file path, e.g. logs/migration_{date}.log';
    }
    if (isset($settings['log_level'])) {
      $this->oneOf($settings['log_level'], self::LOG_LEVELS, 'settings.log_level', true);
    }
  }

  /**
   * @param string[] $schemaNames
   */
  private function schema(string $name, mixed $schema, array $schemaNames): void
  {
    $path = "schemas.{$name}";
    if (!$this->isMap($schema, $path, 'a map with source_table, target_table and fields')) {
      return;
    }
    $this->keys($schema, self::SCHEMA_KEYS, $path);

    foreach (['source_table', 'target_table'] as $key) {
      if (!isset($schema[$key])) {
        $this->errors[] = "{$path}.{$key}: missing; name the table";
      } elseif (!is_string($schema[$key]) || $schema[$key] === '') {
        $this->errors[] = "{$path}.{$key}: must be a table name";
      }
    }
    if (isset($schema['where']) && !is_string($schema['where'])) {
      $this->errors[] = "{$path}.where: must be an SQL condition string";
    }

    // Target columns that transforms, validations and references may name; null when fields is broken (no noise)
    $columns = null;
    $fields = $schema['fields'] ?? null;
    if ($fields === null || $fields === []) {
      $this->errors[] = "{$path}.fields: missing; map at least one source_column: target_column";
    } elseif ($this->isMap($fields, "{$path}.fields", 'a map of source_column: target_column')) {
      $columns = [];
      foreach ($fields as $source => $target) {
        if (!is_string($target) || $target === '') {
          $this->errors[] = "{$path}.fields.{$source}: must be a target column name";
        } else {
          $columns[] = $target;
        }
      }
    }

    if (isset($schema['joins'])) {
      $joinColumns = $this->joins($schema['joins'], "{$path}.joins");
      $columns = $columns === null ? null : [...$columns, ...$joinColumns];
    }
    if (isset($schema['options'])) {
      $this->options($schema['options'], "{$path}.options", $schemaNames);
    }
    if (isset($schema['transforms'])) {
      $this->transforms($schema['transforms'], "{$path}.transforms", $columns);
    }
    if (isset($schema['validations'])) {
      $this->validations($schema['validations'], "{$path}.validations", $columns);
    }
    if (isset($schema['references'])) {
      $this->references($schema['references'], "{$path}.references", $columns, $schemaNames);
    }
  }

  /**
   * @return string[] Target columns the joins add, nested joins included
   */
  private function joins(mixed $joins, string $path): array
  {
    if (!is_array($joins)) {
      $this->errors[] = "{$path}: must be a list of joins, e.g. - { table: categories, source_key: category_id, target_key: id, fields: { name: category_name } }";
      return [];
    }

    $columns = [];
    foreach ($joins as $key => $join) {
      // Keyed by table name (`categories: {...}`) or a list item with `table`
      $at = is_int($key) ? "{$path}[{$key}]" : "{$path}.{$key}";
      if (!$this->isMap($join ?? [], $at, 'a map with table, source_key, target_key and fields')) {
        continue;
      }
      $join ??= [];
      $this->keys($join, self::JOIN_KEYS, $at);

      if (is_int($key) && !isset($join['table'])) {
        $this->errors[] = "{$at}.table: missing; name the table to join";
      }
      foreach (['table', 'source_key', 'target_key', 'where'] as $k) {
        if (isset($join[$k]) && !is_string($join[$k])) {
          $this->errors[] = "{$at}.{$k}: must be a string";
        }
      }
      if (isset($join['fields']) && $this->isMap($join['fields'], "{$at}.fields", 'a map of joined_column: target_column')) {
        foreach ($join['fields'] as $source => $target) {
          if (!is_string($target) || $target === '') {
            $this->errors[] = "{$at}.fields.{$source}: must be a target column name";
          } else {
            $columns[] = $target;
          }
        }
      }
      if (isset($join['join'])) {
        $columns = [...$columns, ...$this->joins($join['join'], "{$at}.join")];
      }
    }
    return $columns;
  }

  /**
   * @param string[] $schemaNames
   */
  private function options(mixed $options, string $path, array $schemaNames): void
  {
    if (!$this->isMap($options, $path, 'a map of schema options')) {
      return;
    }
    $this->keys($options, self::OPTIONS, $path, 'option');

    foreach ($options as $key => $value) {
      $at = "{$path}.{$key}";
      if ($key === 'create_target' || $key === 'upsert') {
        $this->oneOf($value, [true, false], $at);
      } elseif ($key === 'on_error') {
        $this->oneOf($value, self::ON_ERROR, $at);
      } elseif ($key === 'transaction_mode') {
        $this->oneOf($value, self::TRANSACTION_MODES, $at);
      } elseif ($key === 'remap_ids') {
        $this->oneOf($value, [true, false, 'offset', 'uuid'], $at);
      } elseif ($key === 'uuid_namespace' && !(is_string($value) && Uuid::isValid($value))) {
        $this->errors[] = "{$at}: must be a UUID such as 5b0f8e3a-7c41-4d2e-9a6b-6d7967726101, got " . self::show($value);
      } elseif ($key === 'depends_on') {
        $this->schemaList($value, $at, $schemaNames);
      }
    }
  }

  /**
   * @param string[] $schemaNames
   */
  private function schemaList(mixed $value, string $path, array $schemaNames): void
  {
    $names = is_string($value) ? [$value] : $value;
    if (!is_array($names) || !array_is_list($names)) {
      $this->errors[] = "{$path}: must be a list of schema names";
      return;
    }
    foreach ($names as $i => $name) {
      if (!is_string($name) || !in_array($name, $schemaNames, true)) {
        $this->errors[] = "{$path}[{$i}]: unknown schema " . self::show($name)
          . (is_string($name) ? self::suggest($name, $schemaNames) : '') . '; schemas: ' . implode(', ', $schemaNames);
      }
    }
  }

  /**
   * @param string[]|null $columns
   */
  private function transforms(mixed $transforms, string $path, ?array $columns): void
  {
    if (!$this->isMap($transforms, $path, 'a map of target_column: list of transforms')) {
      return;
    }
    $names = array_keys($this->transformers);

    foreach ($transforms as $column => $items) {
      $at = "{$path}.{$column}";
      $this->column((string) $column, $at, $columns);

      $items = is_string($items) ? [$items] : $items;
      if (!is_array($items) || !array_is_list($items)) {
        $this->errors[] = "{$at}: must be a list of transforms, e.g. - { transformer: string, options: { case: lower } }";
        continue;
      }
      foreach ($items as $i => $item) {
        $itemPath = "{$at}[{$i}]";
        if (is_string($item)) {
          $this->transformer($item, [], $itemPath, $itemPath);
        } elseif (!is_array($item) || $item === [] || array_is_list($item)) {
          $this->errors[] = "{$itemPath}: must be a transformer name or { transformer: name, options: {...} }";
        } elseif (array_key_exists('transformer', $item) || array_key_exists('options', $item)) {
          $this->keys($item, ['transformer', 'options'], $itemPath);
          if (!is_string($item['transformer'] ?? null)) {
            $this->errors[] = "{$itemPath}.transformer: missing; use one of: " . implode(', ', $names);
          } else {
            $this->transformer($item['transformer'], $item['options'] ?? [], "{$itemPath}.transformer", "{$itemPath}.options");
          }
        } else {
          // Shorthand `- { string: { case: lower } }`
          foreach ($item as $name => $options) {
            $this->transformer((string) $name, $options ?? [], "{$itemPath}.{$name}", "{$itemPath}.{$name}");
          }
        }
      }
    }
  }

  private function transformer(string $name, mixed $options, string $namePath, string $optionsPath): void
  {
    $transformer = $this->transformers[$name] ?? null;
    if ($transformer === null) {
      $names = array_keys($this->transformers);
      $this->errors[] = "{$namePath}: unknown transformer '{$name}'" . self::suggest($name, $names)
        . '; use one of: ' . implode(', ', $names);
      return;
    }
    if (!$this->isMap($options, $optionsPath, "a map of {$name} options")) {
      return;
    }
    $this->keys($options, $transformer->getOptionNames(), $optionsPath, "option for the {$name} transformer");

    $at = fn(string $key) => "{$optionsPath}.{$key}";
    switch ($name) {
      case 'string':
        if (isset($options['case'])) {
          $this->oneOf($options['case'], StringTransformer::CASES, $at('case'), true);
        }
        if (isset($options['operations'])) {
          if (!is_array($options['operations']) || !array_is_list($options['operations'])) {
            $this->errors[] = "{$at('operations')}: must be a list, e.g. [trim, strip_tags]";
          } else {
            foreach ($options['operations'] as $i => $operation) {
              $this->oneOf($operation, StringTransformer::OPERATIONS, "{$at('operations')}[{$i}]");
            }
          }
        }
        if (isset($options['max_length']) && !is_int($options['max_length'])) {
          $this->errors[] = "{$at('max_length')}: must be a whole number";
        }
        if (isset($options['replace']) && $this->isMap($options['replace'], $at('replace'), 'a map with search and replacement')) {
          $this->keys($options['replace'], ['search', 'replacement'], $at('replace'));
        }
        if (isset($options['regex_replace']) && $this->isMap($options['regex_replace'], $at('regex_replace'), 'a map with pattern and replacement')) {
          $this->keys($options['regex_replace'], ['pattern', 'replacement'], $at('regex_replace'));
          $this->regex($options['regex_replace']['pattern'] ?? null, "{$at('regex_replace')}.pattern");
        }
        break;
      case 'typecast':
        if (isset($options['type'])) {
          $this->oneOf($options['type'], TypeCastTransformer::TYPES, $at('type'), true);
        }
        break;
      case 'json':
        if (isset($options['mode'])) {
          $this->oneOf($options['mode'], JsonTransformer::MODES, $at('mode'));
        }
        break;
      case 'boolean':
        if (isset($options['output_format'])) {
          $this->oneOf($options['output_format'], BooleanTransformer::OUTPUT_FORMATS, $at('output_format'));
        }
        break;
      case 'hash':
        $algorithm = $options['algorithm'] ?? 'sha256';
        $algorithms = array_unique([...self::COMMON_HASHES, ...hash_algos()]);
        if (!is_string($algorithm) || !in_array(strtolower($algorithm), $algorithms, true)) {
          $this->errors[] = "{$at('algorithm')}: unknown algorithm " . self::show($algorithm)
            . (is_string($algorithm) ? self::suggest($algorithm, $algorithms) : '')
            . '; use md5, sha1, sha256, sha512, bcrypt, argon2i, argon2id or another name from PHP hash_algos()';
        }
        break;
      case 'date':
        foreach (['input_timezone', 'output_timezone'] as $key) {
          if (isset($options[$key])) {
            try {
              new \DateTimeZone((string) $options[$key]);
            } catch (\Exception) {
              $this->errors[] = "{$at($key)}: unknown timezone " . self::show($options[$key]) . '; use a name such as UTC or Europe/Berlin';
            }
          }
        }
        break;
    }
  }

  /**
   * @param string[]|null $columns
   */
  private function validations(mixed $validations, string $path, ?array $columns): void
  {
    if (!$this->isMap($validations, $path, 'a map of target_column: list of validators')) {
      return;
    }
    $names = array_keys($this->validators);

    foreach ($validations as $column => $rules) {
      $at = "{$path}.{$column}";
      $this->column((string) $column, $at, $columns);

      if (!is_array($rules) || !array_is_list($rules)) {
        $this->errors[] = "{$at}: must be a list of validators, e.g. - { validator: required }";
        continue;
      }
      foreach ($rules as $i => $rule) {
        $rulePath = "{$at}[{$i}]";
        if (!is_array($rule) || array_is_list($rule)) {
          $this->errors[] = "{$rulePath}: must be { validator: name, options: {...} }, e.g. { validator: required }";
          continue;
        }
        $this->keys($rule, ['validator', 'options'], $rulePath);
        if (!is_string($rule['validator'] ?? null)) {
          $this->errors[] = "{$rulePath}.validator: missing; use one of: " . implode(', ', $names);
          continue;
        }

        $name = $rule['validator'];
        $validator = $this->validators[$name] ?? null;
        if ($validator === null) {
          $this->errors[] = "{$rulePath}.validator: unknown validator '{$name}'" . self::suggest($name, $names)
            . '; use one of: ' . implode(', ', $names);
          continue;
        }

        $options = $rule['options'] ?? [];
        $optionsPath = "{$rulePath}.options";
        if (!$this->isMap($options, $optionsPath, "a map of {$name} options")) {
          continue;
        }
        $this->keys($options, $validator->getOptionNames(), $optionsPath, "option for the {$name} validator");

        if ($name === 'type' && isset($options['type'])) {
          $this->oneOf($options['type'], TypeValidator::TYPES, "{$optionsPath}.type");
        } elseif ($name === 'regex') {
          $this->regex($options['pattern'] ?? null, "{$optionsPath}.pattern");
        } elseif ($name === 'length') {
          foreach (['min', 'max'] as $key) {
            if (isset($options[$key])) {
              $this->positiveInt($options[$key], "{$optionsPath}.{$key}", true);
            }
          }
        }
      }
    }
  }

  /**
   * @param string[]|null $columns
   * @param string[] $schemaNames
   */
  private function references(mixed $references, string $path, ?array $columns, array $schemaNames): void
  {
    if (!$this->isMap($references, $path, 'a map of column: schema')) {
      return;
    }

    foreach ($references as $name => $ref) {
      $at = "{$path}.{$name}";
      // `author_id: users` names the schema directly
      $schemaPath = is_string($ref) ? $at : "{$at}.schema";
      $ref = is_string($ref) ? ['schema' => $ref] : $ref;
      if (!$this->isMap($ref, $at, 'a schema name or a map with schema, column, pattern, on_missing, replacement')) {
        continue;
      }
      $this->keys($ref, self::REFERENCE_KEYS, $at);

      $schema = $ref['schema'] ?? null;
      if (!is_string($schema)) {
        $this->errors[] = "{$schemaPath}: missing; name the schema whose rows this column points at ("
          . implode(', ', $schemaNames) . ')';
      } elseif (!in_array($schema, $schemaNames, true)) {
        $this->errors[] = "{$schemaPath}: unknown schema '{$schema}'" . self::suggest($schema, $schemaNames)
          . '; schemas: ' . implode(', ', $schemaNames);
      }

      if (isset($ref['column']) && !is_string($ref['column'])) {
        $this->errors[] = "{$at}.column: must be a target column name";
      } else {
        $this->column($ref['column'] ?? (string) $name, isset($ref['column']) ? "{$at}.column" : $at, $columns);
      }
      if (isset($ref['replacement']) && !is_scalar($ref['replacement'])) {
        $this->errors[] = "{$at}.replacement: must be a single value, e.g. 0";
      }
      foreach (SchemaConfig::referenceProblems($ref) as $key => $problem) {
        $this->errors[] = "{$at}.{$key}: {$problem}";
      }
    }
  }

  /**
   * A target column named by transforms, validations or references must be one the schema writes.
   *
   * @param string[]|null $columns null when fields itself is broken (already reported)
   */
  private function column(string $column, string $path, ?array $columns): void
  {
    if ($columns !== null && !in_array($column, $columns, true)) {
      $this->errors[] = "{$path}: '{$column}' is not a target column of this schema" . self::suggest($column, $columns)
        . '; target columns (fields and join fields values): ' . implode(', ', $columns);
    }
  }

  /**
   * Report every key of $map not in $allowed.
   */
  private function keys(array $map, array $allowed, string $path, string $what = 'key'): void
  {
    foreach (array_keys($map) as $key) {
      if (!in_array($key, $allowed, true)) {
        $at = $path === '' ? (string) $key : "{$path}.{$key}";
        $this->errors[] = "{$at}: unknown {$what}" . self::suggest((string) $key, $allowed)
          . '; allowed: ' . ($allowed === [] ? 'none' : implode(', ', $allowed));
      }
    }
  }

  private function oneOf(mixed $value, array $allowed, string $path, bool $caseInsensitive = false): void
  {
    $compare = $caseInsensitive && is_string($value) ? strtolower($value) : $value;
    if (!in_array($compare, $allowed, true)) {
      $this->errors[] = "{$path}: " . self::show($value) . ' is not allowed'
        . (is_string($value) ? self::suggest($value, array_filter($allowed, 'is_string')) : '')
        . '; use one of: ' . implode(', ', array_map(fn($v) => is_string($v) ? $v : self::show($v), $allowed));
    }
  }

  /**
   * True for a map (or empty array); reports anything else, a YAML list included.
   */
  private function isMap(mixed $value, string $path, string $expected): bool
  {
    if (is_array($value) && ($value === [] || !array_is_list($value))) {
      return true;
    }
    $got = is_array($value) ? 'a list' : get_debug_type($value);
    $this->errors[] = "{$path}: must be {$expected}, got {$got}";
    return false;
  }

  /**
   * Whole number above zero (or zero with $allowZero); a numeric string counts, as ${VAR} values are strings.
   */
  private function positiveInt(mixed $value, string $path, bool $allowZero = false): void
  {
    $int = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    if ($int === null || $int < ($allowZero ? 0 : 1)) {
      $this->errors[] = "{$path}: must be a whole number" . ($allowZero ? ' of 0 or more' : ' above 0') . ', got ' . self::show($value);
    }
  }

  private function regex(mixed $pattern, string $path): void
  {
    if (!is_string($pattern) || @preg_match($pattern, '') === false) {
      $this->errors[] = "{$path}: must be a valid regex with delimiters, e.g. '/^[a-z]+$/', got " . self::show($pattern);
    }
  }

  /**
   * " (did you mean x?)" for the closest candidate, if it is close enough to be a typo.
   */
  private static function suggest(string $given, array $candidates): string
  {
    $best = null;
    $bestDistance = PHP_INT_MAX;
    foreach ($candidates as $candidate) {
      $distance = levenshtein(strtolower($given), strtolower((string) $candidate));
      if ($distance < $bestDistance) {
        [$best, $bestDistance] = [$candidate, $distance];
      }
    }
    $limit = max(2, intdiv(strlen($given), 2));
    return $best !== null && $bestDistance <= $limit && $bestDistance < strlen($given) ? " (did you mean {$best}?)" : '';
  }

  private static function show(mixed $value): string
  {
    return match (true) {
      is_string($value) => "'{$value}'",
      is_bool($value) => $value ? 'true' : 'false',
      $value === null => 'null',
      is_scalar($value) => (string) $value,
      default => get_debug_type($value),
    };
  }
}
