<?php

declare(strict_types=1);

namespace Mygra\Transform;

/**
 * Transformer Interface
 * 
 * Defines the contract for data transformers that convert values
 * from source format to target format during migration.
 */
interface TransformerInterface
{
    /**
     * Transform a value.
     *
     * @param mixed $value The value to transform
     * @param array $context Additional context (row data, options, etc.)
     * @return mixed The transformed value
     */
    public function transform(mixed $value, array $context = []): mixed;

    /**
     * Check if this transformer supports a given type.
     *
     * @param string $type The type to check
     * @return bool True if supported
     */
    public function supports(string $type): bool;

    /**
     * Get the unique name of this transformer.
     */
    public function getName(): string;

    /**
     * Get a description of what this transformer does.
     */
    public function getDescription(): string;
}
