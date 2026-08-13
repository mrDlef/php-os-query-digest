<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use JsonSerializable;

/**
 * Defers all parsing until something actually reads the value.
 *
 * Pass one of these in a PSR-3 context array: if the record is filtered out by
 * level, the handler never serialises it and the query is never parsed.
 */
final class LazyDigest implements JsonSerializable
{
    /** @var callable():Digest */
    private $factory;

    /** @var Digest|null */
    private $digest;

    /**
     * @param callable():Digest $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    public function digest(): Digest
    {
        if ($this->digest === null) {
            $this->digest = ($this->factory)();
        }

        return $this->digest;
    }

    /**
     * @return array<string,mixed>
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->digest()->toArray();
    }

    public function __toString(): string
    {
        return $this->digest()->text();
    }
}
