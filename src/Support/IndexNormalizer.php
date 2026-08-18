<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;

/**
 * Collapses rolling index names so a daily index does not mint a new
 * fingerprint every midnight: `logs-2026.08.13` → `logs-*`.
 *
 * This is on by default because the alternative silently breaks any dashboard
 * built on the hash.
 *
 * @api
 */
final class IndexNormalizer
{
    public const DATE_PATTERNS = 'date-patterns';
    public const IDENTITY = 'identity';

    /**
     * @var array<int,string>
     */
    public const MODES = [self::DATE_PATTERNS, self::IDENTITY];

    private bool $collapse;

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

    /**
     * The mode named by one of the constants above, for configuration that
     * arrives as a string.
     *
     * @throws InvalidOptionException on any other name
     */
    public static function fromMode(string $mode): self
    {
        switch ($mode) {
            case self::DATE_PATTERNS:
                return self::datePatterns();
            case self::IDENTITY:
                return self::identity();
        }

        throw InvalidOptionException::notAllowed('indexNormalizer', $mode, self::MODES);
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
