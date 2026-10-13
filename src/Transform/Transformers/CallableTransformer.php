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
  public function getName(): string
  {
    return 'callable';
  }

  public function getOptionNames(): array
  {
    return ['function', 'args'];
  }

  public function transform(mixed $value, array $context = []): mixed
  {
    $function = $context['function'] ?? null;
    $args = $context['args'] ?? [];

    if ($function === null) {
      return $value;
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
