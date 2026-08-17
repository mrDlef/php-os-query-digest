<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Exception;

/**
 * A configuration array the library refuses to guess at.
 *
 * Unknown keys and wrong types throw rather than being ignored. An option that
 * silently does nothing is the worst kind of bug here: you find it months
 * later, in a dashboard built on hashes that were never grouped the way the
 * config claimed.
 */
final class InvalidOptionException extends \InvalidArgumentException
{
    /**
     * @param array<int,string> $known
     */
    public static function unknownOption(string $key, array $known): self
    {
        return new self(
            'Unknown option "' . $key . '". Known options: ' . implode(', ', $known) . '.',
        );
    }

    /**
     * @param mixed $value
     */
    public static function wrongType(string $key, string $expected, $value): self
    {
        return new self(
            'Option "' . $key . '" expects ' . $expected . ', got ' . gettype($value) . '.',
        );
    }

    /**
     * @param array<int,string> $allowed
     */
    public static function notAllowed(string $key, string $value, array $allowed): self
    {
        return new self(
            'Option "' . $key . '" does not accept "' . $value . '". Allowed: '
            . implode(', ', $allowed) . '.',
        );
    }
}
