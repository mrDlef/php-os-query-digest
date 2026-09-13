<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

/**
 * What kind of work a search is.
 *
 * A fingerprint says which searches are the *same*; it does not say what they
 * are *for*. A top-N of two hundred hashes is unreadable, and the same list
 * grouped by kind is a sentence: where the load goes, which autocompletes are
 * slow, how much traffic never asks for a document at all.
 *
 * It is read off the parsed request — never off the raw body — so it sees the
 * canonicalised tree and it costs nothing extra. It holds no literal, so it
 * survives {@see Options::withText()} set to false the way the hash does.
 *
 * PHP 7.4 has no enums, so this is a small value object with named
 * constructors, like {@see Normalization}.
 *
 * @api
 */
final class Kind
{
    /** A type-ahead: the user has not finished typing. */
    public const SUGGEST = 'suggest';

    /** No documents come back — buckets, or a count. */
    public const AGGREGATE = 'aggregate';

    /** A walk over a result set rather than a page of one. */
    public const SCAN = 'scan';

    /** Documents named by identity: these ids, this key. */
    public const LOOKUP = 'lookup';

    /** A page of what matches — the ordinary search. */
    public const BROWSE = 'browse';

    /** Documents come back, and nothing in the query can be read. */
    public const UNKNOWN = 'unknown';

    /**
     * Every kind, in the order {@see \MrDlef\OsQueryDigest\Classify\Classifier}
     * decides them — most specific first, which is why `unknown` is not last:
     * it is not a fallback but a verdict, and `browse` is what a readable
     * request falls back to.
     *
     * Public because a configuration front — a dashboard legend, a `<select>` —
     * needs the list, and hard-coding it elsewhere is how it drifts.
     *
     * @var array<int,string>
     */
    public const KINDS = [
        self::SUGGEST,
        self::AGGREGATE,
        self::SCAN,
        self::UNKNOWN,
        self::LOOKUP,
        self::BROWSE,
    ];

    private string $name;

    private function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function suggest(): self
    {
        return new self(self::SUGGEST);
    }

    public static function aggregate(): self
    {
        return new self(self::AGGREGATE);
    }

    public static function scan(): self
    {
        return new self(self::SCAN);
    }

    public static function lookup(): self
    {
        return new self(self::LOOKUP);
    }

    public static function browse(): self
    {
        return new self(self::BROWSE);
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * A kind read back from a record this library wrote.
     *
     * This used to be refused: a name-taking constructor has to decide what a
     * name from another release means, and nothing needed that promise. The
     * `report` sub-command needs it — it ranks digests it did not mint, and
     * dropping their kind would throw away the one field that says where the
     * load goes.
     *
     * **An unrecognised name is {@see unknown()}, not an error**, which is the
     * difference between this and {@see Normalization::fromLevel()} or
     * {@see IndexNormalizer::fromMode()}. Those read *configuration*, where a
     * typo must be loud. This reads *data*, where a kind minted by a newer
     * release must not stop a report about the shapes beside it — and `unknown`
     * is the honest answer to a name this version cannot place.
     */
    public static function fromName(string $name): self
    {
        return in_array($name, self::KINDS, true) ? new self($name) : self::unknown();
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
