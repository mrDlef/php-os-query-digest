<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Monolog\SafeDigest;
use MrDlef\OsQueryDigest\Support\LazyField;
use MrDlef\OsQueryDigest\Support\RecordFields;

/**
 * Where the digest's fields go in a log record: under one key, or beside it.
 *
 *     new LoggingObserver($logger, RecordLayout::flat());
 *
 *     nested   {"dsl": {"idx": "…", "kind": "…", "hash": "q5:…"}, "took": 12}
 *     flat     {"dsl_idx": "…", "dsl_kind": "…", "dsl_hash": "q5:…", "took": 12}
 *
 * Nested is the default and what the shipped dashboard pack maps. Flat exists
 * because **the shape belongs to the collector, not to this library**: some log
 * platforms cannot group or chart on a node below the top level, and pinning
 * the nested shape would leave everyone on one of those writing the same
 * flattener by hand. Both spellings carry the same fields under the same names,
 * so `dsl.hash` and `dsl_hash` hold the same value and neither is a different
 * contract.
 *
 * Laziness survives either way: a flat record puts one deferred value under
 * every key and they share a single parse, so a record dropped by a level
 * filter still costs nothing. What a flat record cannot keep is a list — the
 * `notes` become one `; `-joined string, since a collector that cannot query a
 * nested object cannot query an array either.
 *
 * @api
 */
final class RecordLayout
{
    /**
     * Every key a flat layout writes, whether or not this digest has one — the
     * set is fixed before anything is parsed. `q` is absent under
     * {@see Options::withText()} set to false, `notes` on most requests and
     * `error` on every request that could be read; those come out null.
     *
     * @var array<int,string>
     */
    public const FIELDS = ['idx', 'kind', 'q', 'sig', 'hash', 'notes', RecordFields::ERROR];

    private const DEFAULT_KEY = 'dsl';

    private ?string $key;

    private string $prefix;

    private function __construct(?string $key, string $prefix)
    {
        $this->key = $key;
        $this->prefix = $prefix;
    }

    /**
     * The digest as one object, under `$key`. What the dashboard pack's index
     * template maps, and the default everywhere.
     */
    public static function nested(string $key = self::DEFAULT_KEY): self
    {
        return new self($key, '');
    }

    /**
     * One key per field, `$prefix` in front of each. The prefix carries its own
     * separator so that a collector wanting `dsl-hash`, or no prefix at all,
     * is not arguing with a hard-coded underscore.
     */
    public static function flat(string $prefix = self::DEFAULT_KEY . '_'): self
    {
        return new self(null, $prefix);
    }

    public function isFlat(): bool
    {
        return $this->key === null;
    }

    /**
     * The context with this digest laid into it.
     *
     * Nothing is resolved here: what lands in the array is deferred either way,
     * which is the whole point of taking a {@see LazyDigest} rather than a
     * {@see Digest}.
     *
     * @param array<mixed> $context a log record's context, whose own keys are
     *                              passed through untouched
     *
     * @return array<mixed>
     */
    public function apply(array $context, LazyDigest $digest): array
    {
        if ($this->key !== null) {
            $context[$this->key] = new SafeDigest($digest);

            return $context;
        }

        $fields = new RecordFields($digest);
        foreach (self::FIELDS as $field) {
            $context[$this->prefix . $field] = new LazyField($fields, $field);
        }

        return $context;
    }
}
