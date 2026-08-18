<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Render;

use MrDlef\OsQueryDigest\Support\Arr;

/**
 * Renders real values, quoting only when DQL requires it, and running them
 * through the optional redactor first.
 *
 * @internal
 */
final class LiteralValueRenderer implements ValueRenderer
{
    /** @var callable|null */
    private $redactor;

    public function __construct(?callable $redactor = null)
    {
        $this->redactor = $redactor;
    }

    /**
     * @param mixed $value
     */
    public function scalar(string $field, $value): string
    {
        $value = $this->redact($field, $value);

        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            return '<object>';
        }

        $string = Arr::str($value);
        if ($string === '') {
            return '""';
        }

        // Quote anything that would break the surrounding DQL expression.
        if (preg_match('/[\s"\'()\\\\:<>|{}\[\]]/', $string) === 1) {
            return $this->quote($string);
        }

        return $string;
    }

    /**
     * @param mixed $value
     */
    public function phrase(string $field, $value): string
    {
        $value = $this->redact($field, $value);

        return $this->quote(is_scalar($value) ? (string) $value : '<object>');
    }

    public function raw(string $field, string $value): string
    {
        return $value;
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function redact(string $field, $value)
    {
        if ($this->redactor === null) {
            return $value;
        }

        return ($this->redactor)($field, $value);
    }
}
