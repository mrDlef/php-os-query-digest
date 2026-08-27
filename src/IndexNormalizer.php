<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Support\Arr;

/**
 * Collapses rolling index names so a daily index does not mint a new
 * fingerprint every midnight: `logs-2026.08.13` → `logs-*`.
 *
 * This is on by default because the alternative silently breaks any dashboard
 * built on the hash.
 *
 * It collapses what any cluster does. A suffix whose meaning is the
 * application's — a mapping hash, a tenant token — needs
 * {@see self::custom()}.
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

    /** @var callable|null */
    private $rewrite;

    /**
     * @param callable|null $rewrite
     */
    private function __construct(bool $collapse, $rewrite = null)
    {
        $this->collapse = $collapse;
        $this->rewrite = $rewrite;
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
     * A rule of your own, for what only you can recognise in an index name.
     *
     *     IndexNormalizer::custom(
     *         fn (string $index): string => preg_replace('/_[0-9a-f]{32}$/', '', $index)
     *     );
     *
     * `fn(string $index): string`, called once per name — so a request against
     * `a,b` gets two calls, and the deduplicating and sorting of a
     * comma-separated list stays where it is rather than becoming yours to
     * reimplement.
     *
     * **Your rule runs first, then the shipped one.** The point of the hook is
     * to strip what this library cannot know is meaningless — a mapping hash
     * from a blue/green reindex, a tenant token — and dates and numeric
     * segments are collapsed afterwards exactly as they always are. So the
     * example above turns `tenant_0178_members_4f171971a955af948fae1c7a964c49b8`
     * into `tenant_*_members`, not into `tenant_0178_members`.
     *
     * Like {@see Options::withRedactor()} and unlike the two modes, this has no
     * array form and no {@see self::MODES} entry: a callable cannot come out of
     * a configuration file.
     *
     * A rule that changes what an index collapses to changes the fingerprint of
     * every query against it — which is the point — so it is yours to roll out
     * the way a prefix bump would be.
     *
     * @param callable $rewrite fn(string $index): string
     */
    public static function custom(callable $rewrite): self
    {
        return new self(true, $rewrite);
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
            $one = $this->collapse ? $this->one($one) : $one;

            // A custom rule is allowed to erase a name entirely; what it leaves
            // is dropped here rather than becoming an empty comma-separated part.
            if ($one === '') {
                continue;
            }

            $parts[] = $one;
        }

        $parts = array_values(array_unique($parts));
        sort($parts);

        return implode(',', $parts);
    }

    private function one(string $index): string
    {
        if ($this->rewrite !== null) {
            // Not trusted to return a string, the same way the redactor is not:
            // this runs in a logging path, where a TypeError from someone's
            // closure would cost the log line rather than the digest.
            $index = trim(Arr::str(($this->rewrite)($index)));

            // A rule that erases the name leaves nothing to collapse, and the
            // caller drops what comes back empty.
            if ($index === '') {
                return '';
            }
        }

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
