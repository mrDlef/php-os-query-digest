<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Exception;

/**
 * @api
 */
final class InvalidQueryException extends \InvalidArgumentException
{
    public static function notDecodable(string $reason): self
    {
        return new self('The query could not be decoded as JSON: ' . $reason);
    }

    public static function unexpectedType(string $given): self
    {
        return new self('Expected an array or a JSON string, got ' . $given . '.');
    }
}
