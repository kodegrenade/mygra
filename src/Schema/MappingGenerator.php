<?php

declare(strict_types=1);

namespace Mygra\Schema;

/**
 * Mapping Generator
 *
 * Analyzes source and target tables to suggest field mappings.
 */
class MappingGenerator
{
  /**
   * Generate suggested mappings between source and target tables.
   *
   * @param TableSchema $source
   * @param TableSchema $target
   * @param float $threshold Similarity threshold (0.0 to 1.0, where 1.0 is exact match)
   * @return array Suggested mappings: ['source_field' => 'target_field']
   */
  public function suggest(TableSchema $source, TableSchema $target, float $threshold = 0.7): array
  {
    $suggestions = [];
    $sourceFields = $source->getColumnNames();
    $targetFields = $target->getColumnNames();

    foreach ($sourceFields as $src) {
      $bestMatch = null;
      $bestScore = -1.0;

      foreach ($targetFields as $tgt) {
        $score = $this->calculateSimilarity($src, $tgt);

        if ($score > $bestScore) {
          $bestScore = $score;
          $bestMatch = $tgt;
        }
      }

      if ($bestMatch && $bestScore >= $threshold) {
        $suggestions[$src] = $bestMatch;
      }
    }

    return $suggestions;
  }

  /**
   * Calculate similarity score between two strings.
   */
  private function calculateSimilarity(string $s1, string $s2): float
  {
    $s1 = strtolower($s1);
    $s2 = strtolower($s2);

    if ($s1 === $s2) {
      return 1.0;
    }

    $lev = levenshtein($s1, $s2);
    $maxLen = max(strlen($s1), strlen($s2));

    if ($maxLen === 0) {
      return 1.0;
    }

    return 1 - ($lev / $maxLen);
  }

  /**
   * Format suggestions as YAML snippet.
   */
  public function toYaml(array $suggestions): string
  {
    if (empty($suggestions)) {
      return 'fields: {}';
    }

    $lines = ['fields:'];
    foreach ($suggestions as $src => $tgt) {
      $lines[] = "  {$src}: {$tgt}";
    }

    return implode("\n", $lines);
  }
}
