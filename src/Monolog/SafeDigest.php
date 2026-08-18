<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Monolog;

use MrDlef\OsQueryDigest\LazyDigest;

/**
 * A {@see LazyDigest} that cannot take the log line down with it.
 *
 * Laziness moves the parsing out of the processor and into the handler, which
 * is what makes a dropped record free — but it also moves any failure there. An
 * unparseable request would then throw while Monolog is formatting, which turns
 * "this one query could not be digested" into "this log line was lost", and
 * possibly into a failed request.
 *
 * So the failure is caught where it now happens. Losing the digest is
 * acceptable; losing the record is not.
 *
 * The original request is deliberately *not* used as a fallback: dumping it
 * back into the log would restore the wall of nested braces this library exists
 * to keep out, at the size that made it unloggable in the first place. The
 * error message says what went wrong, which is the part you can act on.
 *
 * @api
 */
final class SafeDigest implements \JsonSerializable
{
    private LazyDigest $digest;

    public function __construct(LazyDigest $digest)
    {
        $this->digest = $digest;
    }

    /**
     * @return array<string,mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        try {
            return $this->digest->jsonSerialize();
        } catch (\Throwable $error) {
            return ['error' => self::describe($error)];
        }
    }

    public function __toString(): string
    {
        try {
            return $this->digest->__toString();
        } catch (\Throwable $error) {
            return self::describe($error);
        }
    }

    private static function describe(\Throwable $error): string
    {
        return 'os-query-digest could not read this request: ' . $error->getMessage();
    }
}
