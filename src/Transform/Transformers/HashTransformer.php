<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * Hash Transformer
 * 
 * Hashes values using various algorithms (for passwords, checksums, etc.).
 */
class HashTransformer implements TransformerInterface
{
    public function getName(): string
    {
        return 'hash';
    }

    public function getDescription(): string
    {
        return 'Hash values using various algorithms (md5, sha256, bcrypt, etc.)';
    }

    public function supports(string $type): bool
    {
        return true; // Can hash any value
    }

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $algorithm = $context['algorithm'] ?? 'sha256';
        $value = (string) $value;

        return match (strtolower($algorithm)) {
            'md5' => md5($value),
            'sha1' => sha1($value),
            'sha256' => hash('sha256', $value),
            'sha512' => hash('sha512', $value),
            'bcrypt' => password_hash($value, PASSWORD_BCRYPT, [
                'cost' => $context['cost'] ?? 10,
            ]),
            'argon2i' => password_hash($value, PASSWORD_ARGON2I),
            'argon2id' => password_hash($value, PASSWORD_ARGON2ID),
            default => hash($algorithm, $value),
        };
    }
}
