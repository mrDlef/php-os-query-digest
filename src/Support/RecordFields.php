<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Support;

use MrDlef\OsQueryDigest\Digest;
use MrDlef\OsQueryDigest\LazyDigest;

/**
 * One lazy digest, resolved at most once, as the fields a log record carries.
 *
 * Two callers need exactly this and would otherwise each get it slightly wrong.
 * A nested record serialises the digest as one object; a flat one puts a value
 * under every key, which means several readers of the same digest. Both must
 * parse **once** — and on a request that cannot be read, both must fail once
 * too: a {@see LazyDigest} memoises its result but not its exception, so five
 * readers of a broken request would mean five parses and five throws.
 *
 * The failure is caught rather than propagated, for the reason
 * {@see \MrDlef\OsQueryDigest\Monolog\SafeDigest} exists: losing the digest is
 * acceptable, losing the log record is not.
 *
 * @internal
 */
final class RecordFields
{
    public const ERROR = 'error';

    private LazyDigest $lazy;

    private bool $resolved = false;

    private ?Digest $digest = null;

    private string $error = '';

    public function __construct(LazyDigest $lazy)
    {
        $this->lazy = $lazy;
    }

    /**
     * The whole record, in the shape a nested layout serialises: the digest's
     * own fields, or the one field a failure leaves behind.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $this->resolve();

        return $this->digest !== null ? $this->digest->toArray() : [self::ERROR => $this->error];
    }

    /**
     * One field, or null when this record has none — which is every field of a
     * request that could not be read, and `error` on one that could.
     *
     * @return mixed
     */
    public function value(string $field)
    {
        $fields = $this->all();

        return $fields[$field] ?? null;
    }

    /**
     * The readable line, for callers that print the digest rather than
     * serialise it — the digest's own, so `withText(false)` keeps falling back
     * to the signature exactly as it does everywhere else.
     */
    public function text(): string
    {
        $this->resolve();

        return $this->digest !== null ? $this->digest->text() : $this->error;
    }

    private static function describe(\Throwable $error): string
    {
        return 'os-query-digest could not read this request: ' . $error->getMessage();
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        try {
            $this->digest = $this->lazy->digest();
        } catch (\Throwable $error) {
            $this->error = self::describe($error);
        }
    }
}
