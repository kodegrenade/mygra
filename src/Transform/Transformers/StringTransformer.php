<?php

declare(strict_types=1);

namespace Mygra\Transform\Transformers;

use Mygra\Transform\TransformerInterface;

/**
 * String Transformer
 * 
 * Performs string manipulations: trim, case conversion, truncation, etc.
 */
class StringTransformer implements TransformerInterface
{
    public function getName(): string
    {
        return 'string';
    }

    public function getDescription(): string
    {
        return 'String manipulations: trim, case conversion, truncation, padding, etc.';
    }

    public function supports(string $type): bool
    {
        return in_array(strtolower($type), ['string', 'char', 'varchar', 'text']);
    }

    public function transform(mixed $value, array $context = []): mixed
    {
        if ($value === null) {
            return $context['null_value'] ?? null;
        }

        $value = (string) $value;

        // Apply operations in order
        $operations = $context['operations'] ?? $this->getDefaultOperations($context);

        foreach ($operations as $operation) {
            $value = $this->applyOperation($value, $operation, $context);
        }

        return $value;
    }

    private function getDefaultOperations(array $context): array
    {
        $ops = [];

        if ($context['trim'] ?? false) {
            $ops[] = 'trim';
        }
        if (isset($context['case'])) {
            $ops[] = 'case';
        }
        if (isset($context['max_length'])) {
            $ops[] = 'truncate';
        }
        if (isset($context['prefix'])) {
            $ops[] = 'prefix';
        }
        if (isset($context['suffix'])) {
            $ops[] = 'suffix';
        }
        if (isset($context['replace'])) {
            $ops[] = 'replace';
        }
        if (isset($context['regex_replace'])) {
            $ops[] = 'regex_replace';
        }

        return $ops;
    }

    private function applyOperation(string $value, string $operation, array $context): string
    {
        return match ($operation) {
            'trim' => trim($value, $context['trim_chars'] ?? " \t\n\r\0\x0B"),
            'ltrim' => ltrim($value, $context['trim_chars'] ?? " \t\n\r\0\x0B"),
            'rtrim' => rtrim($value, $context['trim_chars'] ?? " \t\n\r\0\x0B"),
            'case' => $this->convertCase($value, $context['case']),
            'truncate' => $this->truncate($value, $context['max_length'], $context['ellipsis'] ?? ''),
            'prefix' => ($context['prefix'] ?? '') . $value,
            'suffix' => $value . ($context['suffix'] ?? ''),
            'replace' => str_replace(
                $context['replace']['search'] ?? '',
                $context['replace']['replacement'] ?? '',
                $value
            ),
            'regex_replace' => preg_replace(
                $context['regex_replace']['pattern'] ?? '//',
                $context['regex_replace']['replacement'] ?? '',
                $value
            ),
            'slug' => $this->slugify($value),
            'strip_tags' => strip_tags($value, $context['allowed_tags'] ?? ''),
            'html_entities' => htmlentities($value, ENT_QUOTES, 'UTF-8'),
            'html_decode' => html_entity_decode($value, ENT_QUOTES, 'UTF-8'),
            default => $value,
        };
    }

    private function convertCase(string $value, string $case): string
    {
        return match (strtolower($case)) {
            'lower', 'lowercase' => mb_strtolower($value),
            'upper', 'uppercase' => mb_strtoupper($value),
            'title', 'titlecase' => mb_convert_case($value, MB_CASE_TITLE),
            'ucfirst' => mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1),
            'lcfirst' => mb_strtolower(mb_substr($value, 0, 1)) . mb_substr($value, 1),
            'snake' => $this->toSnakeCase($value),
            'camel' => $this->toCamelCase($value),
            'pascal' => $this->toPascalCase($value),
            'kebab' => $this->toKebabCase($value),
            default => $value,
        };
    }

    private function truncate(string $value, int $length, string $ellipsis = ''): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        $truncateAt = $length - mb_strlen($ellipsis);
        return mb_substr($value, 0, max(0, $truncateAt)) . $ellipsis;
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $value);
        $value = preg_replace('/[\s-]+/', '-', $value);
        return trim($value, '-');
    }

    private function toSnakeCase(string $value): string
    {
        $value = preg_replace('/[A-Z]/', '_$0', $value);
        $value = preg_replace('/[\s-]+/', '_', $value);
        $value = preg_replace('/_+/', '_', $value);
        return strtolower(trim($value, '_'));
    }

    private function toCamelCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        $value = str_replace(' ', '', $value);
        return lcfirst($value);
    }

    private function toPascalCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    private function toKebabCase(string $value): string
    {
        $value = preg_replace('/[A-Z]/', '-$0', $value);
        $value = preg_replace('/[\s_]+/', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return strtolower(trim($value, '-'));
    }
}
