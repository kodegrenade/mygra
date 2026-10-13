<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * JSON Transformer
 *
 * Encodes/decodes JSON values for storage/retrieval.
 */
class JsonTransformer implements TransformerInterface
{
  public const MODES = ['encode', 'decode', 'pretty'];

  public function getName(): string
  {
    return 'json';
  }

  public function getOptionNames(): array
  {
    return ['mode', 'assoc', 'depth', 'flags', 'null_value'];
  }

  public function transform(mixed $value, array $context = []): mixed
  {
    if ($value === null) {
      return $context['null_value'] ?? null;
    }

    $mode = $context['mode'] ?? 'encode'; // encode | decode
    $flags = $context['flags'] ?? JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $assoc = $context['assoc'] ?? true;
    $depth = $context['depth'] ?? 512;

    return match ($mode) {
      'encode' => $this->encode($value, $flags, $depth),
      'decode' => $this->decode($value, $assoc, $depth),
      'pretty' => $this->encode($value, $flags | JSON_PRETTY_PRINT, $depth),
      default => throw new \InvalidArgumentException("Unknown json mode '{$mode}'; use one of: " . implode(', ', self::MODES)),
    };
  }

  private function encode(mixed $value, int $flags, int $depth): string
  {
    if (is_string($value)) {
      // Check if already valid JSON
      $decoded = json_decode($value);
      if (json_last_error() === JSON_ERROR_NONE) {
        return $value; // Already JSON
      }
    }

    $result = json_encode($value, $flags, $depth);

    if ($result === false) {
      throw new \RuntimeException('JSON encode error: ' . json_last_error_msg());
    }

    return $result;
  }

  private function decode(string|array $value, bool $assoc, int $depth): mixed
  {
    if (is_array($value)) {
      return $value; // Already decoded
    }

    $result = json_decode($value, $assoc, $depth);

    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
    }

    return $result;
  }
}
