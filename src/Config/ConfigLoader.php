<?php

declare(strict_types=1);

namespace Mygra\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Configuration Loader
 * 
 * Loads and validates migration configuration from YAML files.
 * Supports environment variable interpolation.
 */
class ConfigLoader
{
    private array $config = [];
    private string $basePath = '';

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

        $this->basePath = dirname(realpath($path));
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $content = match ($extension) {
            'yaml', 'yml' => Yaml::parseFile($path),
            'json' => json_decode(file_get_contents($path), true),
            'php' => require $path,
            default => throw new \InvalidArgumentException("Unsupported config format: {$extension}"),
        };

        $this->config = $this->interpolateEnvVars($content);
        $this->validate();

        return $this;
    }

    /**
     * Load configuration from array.
     */
    public function loadArray(array $config): self
    {
        $this->config = $config;
        $this->validate();
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
     * Get global settings.
     */
    public function getSettings(): array
    {
        return $this->config['settings'] ?? [];
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
     * Get the raw configuration array.
     */
    public function toArray(): array
    {
        return $this->config;
    }

    /**
     * Get base path of the configuration file.
     */
    public function getBasePath(): string
    {
        return $this->basePath;
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
                                "Environment variable not set: {$envVar}"
                            );
                        }

                        return $envValue;
                    },
                    $value
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
                    $value
                );
            }
        });

        return $config;
    }

    /**
     * Validate the configuration structure.
     */
    private function validate(): void
    {
        $errors = [];

        // Check required sections
        if (!isset($this->config['connections'])) {
            $errors[] = "Missing required section: 'connections'";
        } else {
            if (!isset($this->config['connections']['source'])) {
                $errors[] = "Missing required connection: 'connections.source'";
            }
            if (!isset($this->config['connections']['target'])) {
                $errors[] = "Missing required connection: 'connections.target'";
            }
        }

        if (!isset($this->config['schemas']) || empty($this->config['schemas'])) {
            $errors[] = "At least one schema must be defined in 'schemas'";
        }

        // Validate each schema
        foreach ($this->config['schemas'] ?? [] as $name => $schema) {
            if (!isset($schema['source_table'])) {
                $errors[] = "Schema '{$name}' missing required field: 'source_table'";
            }
            if (!isset($schema['target_table'])) {
                $errors[] = "Schema '{$name}' missing required field: 'target_table'";
            }
            if (!isset($schema['fields']) || empty($schema['fields'])) {
                $errors[] = "Schema '{$name}' must have at least one field mapping";
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                "Configuration validation failed:\n - " . implode("\n - ", $errors)
            );
        }
    }
}
