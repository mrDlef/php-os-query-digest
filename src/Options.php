<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\Extension\ClauseRenderer;

/**
 * Immutable configuration. PHP 7.4 has no named arguments, hence withers.
 *
 * @api
 */
final class Options
{
    /**
     * Every key {@see fromArray()} accepts, in the order the README documents
     * the withers.
     *
     * `redactor` is absent on purpose: a callable has no array form.
     *
     * @var array<int,string>
     */
    public const KEYS = [
        'normalization',
        'maxClauses',
        'maxValues',
        'maxLength',
        'indexNormalizer',
        'aggNames',
        'hashVersion',
        'hashLength',
    ];

    private Normalization $normalization;

    /** @var array<string,ClauseRenderer> */
    private array $clauseRenderers = [];

    private ?int $maxClauses = 12;

    private ?int $maxValues = 5;

    private ?int $maxLength = 512;

    private IndexNormalizer $indexNormalizer;

    /** @var callable|null */
    private $redactor;

    private bool $includeAggNames = false;

    private int $hashLength = 12;

    /**
     * Bumped whenever the normalisation rules change, so an algorithm change is
     * visible in the data instead of silently rewriting every dashboard.
     */
    private string $hashVersion = 'q5';

    private function __construct()
    {
        $this->normalization = Normalization::values();
        $this->indexNormalizer = IndexNormalizer::datePatterns();
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * The same options from a plain array: a YAML file, a framework config
     * block, a decoded request. Every key is optional and every unlisted key
     * keeps its default.
     *
     * Types are taken as JSON gives them — `int|null` for the caps, `bool` for
     * the switches, `string` for the named modes. Coercing `"12"` is the
     * caller's job, because a front that guesses is a front that accepts
     * `"twelve"` too.
     *
     * @param array<mixed> $spec
     *
     * @throws InvalidOptionException on an unknown key or a wrong type
     */
    public static function fromArray(array $spec): self
    {
        $options = new self();

        foreach ($spec as $key => $value) {
            $options = $options->with((string) $key, $value);
        }

        return $options;
    }

    public function withNormalization(Normalization $normalization): self
    {
        $clone = clone $this;
        $clone->normalization = $normalization;

        return $clone;
    }

    /**
     * Maximum sibling clauses rendered per and/or level before the rest is
     * summarised as `+N more`. Null disables the limit.
     */
    public function withMaxClauses(?int $maxClauses): self
    {
        $clone = clone $this;
        $clone->maxClauses = $maxClauses;

        return $clone;
    }

    /**
     * Maximum values rendered inside a terms clause. Null disables the limit.
     */
    public function withMaxValues(?int $maxValues): self
    {
        $clone = clone $this;
        $clone->maxValues = $maxValues;

        return $clone;
    }

    /**
     * Hard character cap on the rendered lines. Never affects the hash.
     */
    public function withMaxLength(?int $maxLength): self
    {
        $clone = clone $this;
        $clone->maxLength = $maxLength;

        return $clone;
    }

    public function withIndexNormalizer(IndexNormalizer $indexNormalizer): self
    {
        $clone = clone $this;
        $clone->indexNormalizer = $indexNormalizer;

        return $clone;
    }

    /**
     * fn(string $field, mixed $value): mixed — applied to literal values before
     * rendering. Use it to keep PII out of your logs.
     */
    public function withRedactor(?callable $redactor): self
    {
        $clone = clone $this;
        $clone->redactor = $redactor;

        return $clone;
    }

    /**
     * Include user-given aggregation names (`by_host:terms(host)`). Off by
     * default: names are often generated, which would make hashes brittle.
     */
    public function withAggNames(bool $includeAggNames): self
    {
        $clone = clone $this;
        $clone->includeAggNames = $includeAggNames;

        return $clone;
    }

    /**
     * Hex characters kept from the sha256 digest. 12 (48 bits) keeps collisions
     * negligible at any realistic number of distinct query shapes.
     */
    public function withHashLength(int $hashLength): self
    {
        $clone = clone $this;
        $clone->hashLength = max(4, min(64, $hashLength));

        return $clone;
    }

    public function withHashVersion(string $hashVersion): self
    {
        $clone = clone $this;
        $clone->hashVersion = $hashVersion;

        return $clone;
    }

    public function normalization(): Normalization
    {
        return $this->normalization;
    }

    public function maxClauses(): ?int
    {
        return $this->maxClauses;
    }

    public function maxValues(): ?int
    {
        return $this->maxValues;
    }

    public function maxLength(): ?int
    {
        return $this->maxLength;
    }

    public function indexNormalizer(): IndexNormalizer
    {
        return $this->indexNormalizer;
    }

    /**
     * Teach the library a query type it does not model, keyed by type name.
     *
     * Like the redactor, this has no array form and no {@see self::KEYS} entry:
     * an object cannot come out of a configuration array.
     *
     * Registering one changes the fingerprints of queries using that type —
     * that is the point — so {@see \MrDlef\OsQueryDigest\Formatter} marks the
     * hash version to say the rules are no longer this library's alone.
     */
    public function withClauseRenderer(string $type, ClauseRenderer $renderer): self
    {
        $clone = clone $this;
        $clone->clauseRenderers[$type] = $renderer;

        return $clone;
    }

    /**
     * @return array<string,ClauseRenderer>
     */
    public function clauseRenderers(): array
    {
        return $this->clauseRenderers;
    }

    public function redactor(): ?callable
    {
        return $this->redactor;
    }

    public function includeAggNames(): bool
    {
        return $this->includeAggNames;
    }

    public function hashLength(): int
    {
        return $this->hashLength;
    }

    public function hashVersion(): string
    {
        return $this->hashVersion;
    }

    /**
     * One key of {@see fromArray()}'s spec. A `switch` rather than a map of
     * closures: the arms are the documentation of what each key accepts.
     *
     * @param mixed $value
     */
    private function with(string $key, $value): self
    {
        switch ($key) {
            case 'normalization':
                return $this->withNormalization(Normalization::fromLevel(self::asString($key, $value)));
            case 'maxClauses':
                return $this->withMaxClauses(self::asIntOrNull($key, $value));
            case 'maxValues':
                return $this->withMaxValues(self::asIntOrNull($key, $value));
            case 'maxLength':
                return $this->withMaxLength(self::asIntOrNull($key, $value));
            case 'indexNormalizer':
                return $this->withIndexNormalizer(IndexNormalizer::fromMode(self::asString($key, $value)));
            case 'aggNames':
                return $this->withAggNames(self::asBool($key, $value));
            case 'hashVersion':
                return $this->withHashVersion(self::asString($key, $value));
            case 'hashLength':
                return $this->withHashLength(self::asInt($key, $value));
        }

        throw InvalidOptionException::unknownOption($key, self::KEYS);
    }

    /**
     * @param mixed $value
     */
    private static function asString(string $key, $value): string
    {
        if (!is_string($value)) {
            throw InvalidOptionException::wrongType($key, 'a string', $value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function asBool(string $key, $value): bool
    {
        if (!is_bool($value)) {
            throw InvalidOptionException::wrongType($key, 'a boolean', $value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function asInt(string $key, $value): int
    {
        if (!is_int($value)) {
            throw InvalidOptionException::wrongType($key, 'an integer', $value);
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function asIntOrNull(string $key, $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!is_int($value)) {
            throw InvalidOptionException::wrongType($key, 'an integer or null', $value);
        }

        return $value;
    }
}
