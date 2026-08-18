<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;

/**
 * How much of a query the signature erases.
 *
 * PHP 7.4 has no enums, so this is a small value object with named
 * constructors.
 *
 * @api
 */
final class Normalization
{
    public const NONE = 'none';
    public const VALUES = 'values';
    public const STRUCTURAL = 'structural';

    /**
     * Every level, weakest first. Public because a configuration front — a CLI
     * flag, a `<select>` — needs the list, and hard-coding it elsewhere is how
     * it drifts.
     *
     * @var array<int,string>
     */
    public const LEVELS = [self::NONE, self::VALUES, self::STRUCTURAL];

    private string $level;

    private function __construct(string $level)
    {
        $this->level = $level;
    }

    /**
     * Erase nothing: the signature equals the readable line. Useful when you
     * want grouping by exact query.
     */
    public static function none(): self
    {
        return new self(self::NONE);
    }

    /**
     * Erase literals but keep the shape, including how many values a terms
     * clause holds. The default.
     */
    public static function values(): self
    {
        return new self(self::VALUES);
    }

    /**
     * Also erase cardinality (a terms clause with 3 or 300 values looks the
     * same) and pagination. Groups the most aggressively.
     */
    public static function structural(): self
    {
        return new self(self::STRUCTURAL);
    }

    /**
     * The level named by one of the constants above, for configuration that
     * arrives as a string rather than as a call.
     *
     * @throws InvalidOptionException on any other name
     */
    public static function fromLevel(string $level): self
    {
        switch ($level) {
            case self::NONE:
                return self::none();
            case self::VALUES:
                return self::values();
            case self::STRUCTURAL:
                return self::structural();
        }

        throw InvalidOptionException::notAllowed('normalization', $level, self::LEVELS);
    }

    public function level(): string
    {
        return $this->level;
    }

    public function erasesValues(): bool
    {
        return $this->level !== self::NONE;
    }

    public function erasesCardinality(): bool
    {
        return $this->level === self::STRUCTURAL;
    }

    public function erasesPagination(): bool
    {
        return $this->level === self::STRUCTURAL;
    }
}
