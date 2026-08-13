<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

/**
 * Collapses rolling index names so a daily index does not mint a new
 * fingerprint every midnight: `logs-2026.08.13` → `logs-*`.
 *
 * This is on by default because the alternative silently breaks any dashboard
 * built on the hash.
 */
final class IndexNormalizer
{
    /** @var bool */
    private $collapse;

    private function __construct(bool $collapse)
    {
        $this->collapse = $collapse;
    }

    public static function datePatterns(): self
    {
        return new self(true);
    }

    /** Leave index names exactly as given. */
    public static function identity(): self
    {
        return new self(false);
    }

    public function normalize(string $index): string
    {
        $index = trim($index);
        if ($index === '') {
            return '';
        }

        $parts = [];
        foreach (explode(',', $index) as $one) {
            $one = trim($one);
            if ($one === '') {
                continue;
            }
            $parts[] = $this->collapse ? $this->one($one) : $one;
        }

        $parts = array_values(array_unique($parts));
        sort($parts);

        return implode(',', $parts);
    }

    private function one(string $index): string
    {
        // 2026.08.13, 2026-08-13, 20260813 …
        $index = (string) preg_replace('/\d{4}[-._]?\d{2}[-._]?\d{2}/', '*', $index);
        // Standalone numeric segments: logs-000042 → logs-*, but v2 stays v2.
        $index = (string) preg_replace('/(?<=^|[-._])\d+(?=$|[-._])/', '*', $index);

        // Collapse runs of wildcards: logs-*-* → logs-*
        do {
            $previous = $index;
            $index = (string) preg_replace('/\*[-._]?\*/', '*', $index);
        } while ($index !== $previous);

        return $index;
    }
}
