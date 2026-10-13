<?php

declare(strict_types=1);

namespace Mygra\Support;

/**
 * Name-based UUIDs (RFC 9562 version 5): the same namespace and name always give the same UUID.
 */
final class Uuid
{
  /** Default namespace for IDs Mygra derives; override per schema with options.uuid_namespace */
  public const MYGRA_NAMESPACE = '5b0f8e3a-7c41-4d2e-9a6b-6d7967726101';

  public static function v5(string $namespace, string $name): string
  {
    $hash = sha1(hex2bin(str_replace('-', '', $namespace)) . $name);

    return sprintf(
      '%s-%s-%04x-%04x-%s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000, // version 5
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000, // RFC variant
      substr($hash, 20, 12),
    );
  }

  public static function isValid(string $value): bool
  {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
  }
}
