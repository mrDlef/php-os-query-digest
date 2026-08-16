<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

/**
 * The result: a readable line, a normalised signature, and a stable hash.
 */
final class Digest implements \JsonSerializable
{
    private string $index;

    private string $text;

    private string $signature;

    private string $hash;

    /** @var array<int,string> */
    private array $notes;

    /**
     * @param array<int,string> $notes
     */
    public function __construct(string $index, string $text, string $signature, string $hash, array $notes = [])
    {
        $this->index = $index;
        $this->text = $text;
        $this->signature = $signature;
        $this->hash = $hash;
        $this->notes = array_values($notes);
    }

    /** Normalised index pattern. */
    public function index(): string
    {
        return $this->index;
    }

    /** The readable, DQL-flavoured line with real values. */
    public function text(): string
    {
        return $this->text;
    }

    /** The same line with literals erased — the shape of the query. */
    public function signature(): string
    {
        return $this->signature;
    }

    /** Versioned fingerprint of the signature, e.g. `q1:8f3ac1d2b901`. */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * Parts that were acknowledged but not rendered inline (boost-only `should`
     * groups, unsupported top-level sections).
     *
     * @return array<int,string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $out = [
            'idx' => $this->index,
            'q' => $this->text,
            'sig' => $this->signature,
            'hash' => $this->hash,
        ];

        if ($this->notes !== []) {
            $out['notes'] = $this->notes;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
