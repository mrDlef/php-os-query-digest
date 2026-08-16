<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Fingerprint;

/**
 * sha256, truncated, and prefixed with the algorithm version.
 *
 * The prefix is not decoration: when the normalisation rules change, every
 * hash changes. Carrying `q1:` / `q2:` in the value makes that break visible in
 * the data rather than silently invalidating existing dashboards.
 *
 * xxh3 would be faster but only exists from PHP 8.1, and this library targets
 * 7.4.
 */
final class Hasher
{
    private string $version;

    private int $length;

    public function __construct(string $version = 'q1', int $length = 12)
    {
        $this->version = $version;
        $this->length = $length;
    }

    /**
     * @param string $signature the *uncapped* signature — never a truncated one
     */
    public function hash(string $signature): string
    {
        return $this->version . ':' . substr(hash('sha256', $signature), 0, $this->length);
    }
}
