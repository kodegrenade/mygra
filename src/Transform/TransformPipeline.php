<?php

declare(strict_types=1);

namespace Mygra\Transform;

/**
 * Transform Pipeline
 * 
 * Orchestrates the execution of transformers on data rows.
 * Allows registering transformers and defining field-specific transformation chains.
 */
class TransformPipeline
{
  /** @var array<string, TransformerInterface> */
  private array $transformers = [];

  /** @var array<string, array> Field-specific transformer configurations */
  private array $fieldTransformers = [];

  /** @var array<string, callable> Global row transformers */
  private array $rowTransformers = [];

  /**
   * Register a transformer by its name.
   */
  public function register(TransformerInterface $transformer): self
  {
    $this->transformers[$transformer->getName()] = $transformer;
    return $this;
  }

  /**
   * Register multiple transformers at once.
   *
   * @param TransformerInterface[] $transformers
   */
  public function registerMany(array $transformers): self
  {
    foreach ($transformers as $transformer) {
      $this->register($transformer);
    }
    return $this;
  }

  /**
   * Configure transformers for a specific field.
   *
   * @param string $field Target field name
   * @param string|array $transformers Transformer name(s) or config array(s)
   */
  public function forField(string $field, string|array $transformers): self
  {
    $this->fieldTransformers[$field] = $this->normalizeTransformerConfig($transformers);
    return $this;
  }

  /**
   * Remove all field-specific transformer configurations.
   */
  public function clearFieldTransformers(): self
  {
    $this->fieldTransformers = [];
    return $this;
  }

  /**
   * Add a row transformer (operates on entire row).
   *
   * @param string $name Unique name for this row transformer
   * @param callable $transformer Function that receives and returns a row array
   */
  public function addRowTransformer(string $name, callable $transformer): self
  {
    $this->rowTransformers[$name] = $transformer;
    return $this;
  }

  /**
   * Process a single row through the transformation pipeline.
   *
   * @param array $row Source row data
   * @param array $fieldMap Source field => target field mapping
   * @return array Transformed row with target field names
   */
  public function process(array $row, array $fieldMap): array
  {
    $result = [];

    // Apply field mappings and transformations
    foreach ($fieldMap as $sourceField => $targetField) {
      $value = $row[$sourceField] ?? null;

      // Apply field-specific transformers
      if (isset($this->fieldTransformers[$targetField])) {
        $value = $this->applyTransformers(
          $value,
          $this->fieldTransformers[$targetField],
          // The row goes under its own key: merged in, a column named like an option (type, prefix, ...) became that option
          ['_row' => $row, '_source_field' => $sourceField, '_target_field' => $targetField]
        );
      }

      $result[$targetField] = $value;
    }

    // Apply row transformers
    foreach ($this->rowTransformers as $transformer) {
      $result = $transformer($result, $row);
    }

    return $result;
  }

  /**
   * Process multiple rows through the pipeline.
   *
   * @param iterable $rows Source rows
   * @param array $fieldMap Source => target field mapping
   * @return \Generator Yields transformed rows
   */
  public function processMany(iterable $rows, array $fieldMap): \Generator
  {
    foreach ($rows as $row) {
      yield $this->process($row, $fieldMap);
    }
  }

  /**
   * Get a registered transformer by name.
   */
  public function getTransformer(string $name): ?TransformerInterface
  {
    return $this->transformers[$name] ?? null;
  }

  /**
   * Get all registered transformers.
   *
   * @return array<string, TransformerInterface>
   */
  public function getTransformers(): array
  {
    return $this->transformers;
  }

  /**
   * Apply a chain of transformers to a value.
   */
  private function applyTransformers(mixed $value, array $configs, array $context): mixed
  {
    foreach ($configs as $config) {
      $name = $config['transformer'];
      $options = $config['options'] ?? [];

      if (!isset($this->transformers[$name])) {
        throw new \InvalidArgumentException("Unknown transformer: {$name}");
      }

      $value = $this->transformers[$name]->transform(
        $value,
        array_merge($context, $options)
      );
    }

    return $value;
  }

  /**
   * Normalize transformer configuration to a consistent format.
   */
  private function normalizeTransformerConfig(string|array $transformers): array
  {
    // Handle single string transformer name
    if (is_string($transformers)) {
      return [['transformer' => $transformers, 'options' => []]];
    }

    // Handle array of mixed formats
    $normalized = [];
    foreach ($transformers as $key => $item) {
      if (is_string($item)) {
        // Simple transformer name
        $normalized[] = ['transformer' => $item, 'options' => []];
      } elseif (is_array($item)) {
        if (isset($item['transformer'])) {
          // Already in correct format
          $normalized[] = $item;
        } else {
          // Assume first key is transformer name, value is options
          foreach ($item as $name => $options) {
            $normalized[] = [
              'transformer' => $name,
              'options' => is_array($options) ? $options : [],
            ];
          }
        }
      }
    }

    return $normalized;
  }

  /**
   * Create a pipeline with default transformers registered.
   */
  public static function withDefaults(): self
  {
    $pipeline = new self();

    // Register built-in transformers
    $pipeline->registerMany([
      new Transformers\DateTransformer(),
      new Transformers\TypeCastTransformer(),
      new Transformers\BooleanTransformer(),
      new Transformers\StringTransformer(),
      new Transformers\HashTransformer(),
      new Transformers\NullableTransformer(),
      new Transformers\JsonTransformer(),
      new Transformers\CallableTransformer(),
    ]);

    return $pipeline;
  }
}
