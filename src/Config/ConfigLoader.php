<?php

declare(strict_types=1);

namespace Mygra\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Configuration Loader
 *
 * Loads migration configuration from YAML, JSON or PHP files, interpolates environment variables,
 * and validates the result (ConfigValidator) so every command sees a checked configuration.
 */
class ConfigLoader
{
  private array $config = [];

  /**
   * Load configuration from a file.
   */
  public static function load(string $path): self
  {
    $loader = new self();
    $loader->loadFile($path);
    return $loader;
  }

  /**
   * Load configuration from a file.
   */
  public function loadFile(string $path): self
  {
    if (!file_exists($path)) {
      throw new \InvalidArgumentException("Configuration file not found: {$path}");
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    try {
      $content = match ($extension) {
        'yaml', 'yml' => Yaml::parseFile($path),
        'json' => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR),
        'php' => require $path,
        default => throw new \InvalidArgumentException("Unsupported config format: {$extension}"),
      };
    } catch (\JsonException $e) {
      throw new InvalidConfigException(["invalid JSON: {$e->getMessage()}"], $path);
    }

    if (!is_array($content) || $content === []) {
      throw new InvalidConfigException([
        'the file is ' . ($content === null || $content === [] ? 'empty' : 'not a map of settings (got ' . get_debug_type($content) . ')')
          . '; it needs connections and schemas (see config/mygra.yaml)',
      ], $path);
    }

    $this->config = $this->interpolateEnvVars($content);
    ConfigValidator::validate($this->config, $path);

    return $this;
  }

  /**
   * Get a configuration value by dot-notation key.
   */
  public function get(string $key, mixed $default = null): mixed
  {
    $keys = explode('.', $key);
    $value = $this->config;

    foreach ($keys as $k) {
      if (!is_array($value) || !array_key_exists($k, $value)) {
        return $default;
      }
      $value = $value[$k];
    }

    return $value;
  }

  /**
   * Get source connection configuration.
   */
  public function getSourceConnection(): array
  {
    return $this->config['connections']['source'] ?? [];
  }

  /**
   * Get target connection configuration.
   */
  public function getTargetConnection(): array
  {
    return $this->config['connections']['target'] ?? [];
  }

  /**
   * Get a specific schema configuration.
   */
  public function getSchema(string $name): ?SchemaConfig
  {
    $schemas = $this->config['schemas'] ?? [];

    if (!isset($schemas[$name])) {
      return null;
    }

    return SchemaConfig::fromArray($name, $schemas[$name]);
  }

  /**
   * Get all schema names.
   */
  public function getSchemaNames(): array
  {
    return array_keys($this->config['schemas'] ?? []);
  }

  /**
   * Get all schemas as SchemaConfig objects.
   *
   * @return SchemaConfig[]
   */
  public function getAllSchemas(): array
  {
    $schemas = [];
    foreach ($this->getSchemaNames() as $name) {
      $schemas[$name] = $this->getSchema($name);
    }
    return $schemas;
  }

  /**
   * Interpolate environment variables in configuration values.
   * Supports ${VAR_NAME} syntax.
   */
  private function interpolateEnvVars(array $config): array
  {
    array_walk_recursive($config, function (&$value) {
      if (is_string($value)) {
        $value = preg_replace_callback(
          '/\$\{([A-Z_][A-Z0-9_]*)\}/',
          function ($matches) {
            $envVar = $matches[1];
            $envValue = getenv($envVar);

            if ($envValue === false) {
              throw new \RuntimeException(
                "Environment variable not set: {$envVar}",
              );
            }

            return $envValue;
          },
          $value,
        );

        // Also support ${VAR:-default} syntax
        $value = preg_replace_callback(
          '/\$\{([A-Z_][A-Z0-9_]*):-([^}]*)\}/',
          function ($matches) {
            $envVar = $matches[1];
            $default = $matches[2];
            $envValue = getenv($envVar);
            return $envValue !== false ? $envValue : $default;
          },
          $value,
        );
      }
    });

    return $config;
  }
}
