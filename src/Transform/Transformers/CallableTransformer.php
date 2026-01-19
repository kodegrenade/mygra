<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * Callable Transformer
 * 
 * Applies custom callable functions for advanced transformations.
 */
class CallableTransformer implements TransformerInterface
{
    /** @var array<string, callable> Registered custom functions */
    private static array $functions = [];

    public function getName(): string
    {
        return 'callable';
    }

    public function getDescription(): string
    {
        return 'Apply custom callable functions for advanced transformations';
    }

    public function supports(string $type): bool
    {
        return true; // Can apply to any type
    }

    /**
     * Register a custom function by name.
     */
    public static function register(string $name, callable $function): void
    {
        self::$functions[$name] = $function;
    }

    /**
     * Get all registered functions.
     */
    public static function getRegisteredFunctions(): array
    {
        return array_keys(self::$functions);
    }

    public function transform(mixed $value, array $context = []): mixed
    {
        $function = $context['function'] ?? null;
        $args = $context['args'] ?? [];

        if ($function === null) {
            return $value;
        }

        // Check for registered function
        if (is_string($function) && isset(self::$functions[$function])) {
            return self::$functions[$function]($value, $context, ...$args);
        }

        // Check for built-in PHP function
        if (is_string($function) && function_exists($function)) {
            return $function($value, ...$args);
        }

        // Direct callable
        if (is_callable($function)) {
            return $function($value, $context, ...$args);
        }

        throw new \InvalidArgumentException("Unknown function: {$function}");
    }
}
