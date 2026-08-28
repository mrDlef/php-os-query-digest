<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

/**
 * The result: a readable line, a normalised signature, and a stable hash.
 *
 * @api
 */
final class Digest implements \JsonSerializable
{
    private string $index;

    /** Null when the formatter was told not to render a line with values in it. */
    private ?string $text;

    private string $signature;

    private string $hash;

    /** @var array<int,string> */
    private array $notes;

    private Kind $kind;

    /**
     * @param string|null       $text  the readable line, or null when the
     *                                 formatter was configured not to produce
     *                                 one — see
     *                                 {@see Options::withText()}
     * @param array<int,string> $notes
     * @param Kind|null         $kind  what kind of work the search is; a digest
     *                                 built by hand rather than parsed says
     *                                 {@see Kind::UNKNOWN}, which is what it
     *                                 knows
     */
    public function __construct(
        string $index,
        ?string $text,
        string $signature,
        string $hash,
        array $notes = [],
        ?Kind $kind = null
    ) {
        $this->index = $index;
        $this->text = $text;
        $this->signature = $signature;
        $this->hash = $hash;
        $this->notes = array_values($notes);
        $this->kind = $kind ?? Kind::unknown();
    }

    /** Normalised index pattern. */
    public function index(): string
    {
        return $this->index;
    }

    /**
     * The readable, DQL-flavoured line with real values.
     *
     * Under {@see Options::withText()} set to false there is no such line, and
     * this returns the signature instead of an empty string: every caller —
     * `__toString()`, the CLI, a log line — then shows the shape rather than
     * nothing, and none of them can hand out a value.
     */
    public function text(): string
    {
        return $this->text ?? $this->signature;
    }

    /** The same line with literals erased — the shape of the query. */
    public function signature(): string
    {
        return $this->signature;
    }

    /** Versioned fingerprint of the signature, e.g. `q5:8f3ac1d2b901`. */
    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * What kind of work this search is — a type-ahead, a page of results, a
     * bucket count. Read off the parsed request, so it holds no literal and
     * survives {@see Options::withText()} set to false.
     */
    public function kind(): Kind
    {
        return $this->kind;
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
        // Before the query itself: it is the coarse grouping, the one a reader
        // scanning a log file reads first and the one a dashboard facets on.
        $out = ['idx' => $this->index, 'kind' => $this->kind->name()];

        // Omitted rather than filled with the signature: a deployment that turns
        // the line off is one deciding per *field* whether it may ship these
        // logs, and a `q` that duplicates `sig` would answer that question with
        // a field it has to inspect first.
        if ($this->text !== null) {
            $out['q'] = $this->text;
        }

        $out['sig'] = $this->signature;
        $out['hash'] = $this->hash;

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
        return $this->text();
    }
}
