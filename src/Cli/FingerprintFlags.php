<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Cli;

use MrDlef\OsQueryDigest\Exception\InvalidOptionException;
use MrDlef\OsQueryDigest\IndexNormalizer;
use MrDlef\OsQueryDigest\Normalization;

/**
 * The flags that shape a fingerprint, in one place.
 *
 * Every sub-command has to accept all of them: a report that grouped queries
 * under different rules than the application logging them would be a report
 * about nothing. What is shared is the mapping and the help text together — two
 * copies of `--max-values=none` are two chances for one of them to mean
 * something else.
 *
 * @internal
 */
final class FingerprintFlags
{
    /**
     * Options that consume a value, in either `--key=value` or `--key value`
     * form.
     *
     * @var array<int,string>
     */
    public const VALUED = [
        '-n', '--normalization',
        '--max-clauses',
        '--max-values',
        '--max-length',
        '--hash-version',
        '--hash-length',
    ];

    /**
     * The spec with this option applied, or null when it is not one of these —
     * which leaves the caller free to recognise its own.
     *
     * @param array<string,mixed> $spec
     *
     * @throws InvalidOptionException
     *
     * @return array<string,mixed>|null
     */
    public static function valued(array $spec, string $name, string $value): ?array
    {
        switch ($name) {
            case '-n':
            case '--normalization':
                $spec['normalization'] = $value;
                break;
            case '--max-clauses':
                $spec['maxClauses'] = self::cap($name, $value);
                break;
            case '--max-values':
                $spec['maxValues'] = self::cap($name, $value);
                break;
            case '--max-length':
                $spec['maxLength'] = self::cap($name, $value);
                break;
            case '--hash-version':
                $spec['hashVersion'] = $value;
                break;
            case '--hash-length':
                $spec['hashLength'] = self::number($name, $value);
                break;
            default:
                return null;
        }

        return $spec;
    }

    /**
     * @param array<string,mixed> $spec
     *
     * @return array<string,mixed>|null
     */
    public static function flag(array $spec, string $name): ?array
    {
        switch ($name) {
            case '--agg-names':
                $spec['aggNames'] = true;
                break;
            case '--raw-index':
                $spec['indexNormalizer'] = IndexNormalizer::IDENTITY;
                break;
            default:
                return null;
        }

        return $spec;
    }

    /** The block every `--help` prints, so no two can describe different flags. */
    public static function usage(): string
    {
        $levels = implode('|', Normalization::LEVELS);

        return <<<TXT
Fingerprint:
  -n, --normalization=L    {$levels} (default: values)
      --max-clauses=N      sibling clauses rendered per level, or `none`
      --max-values=N       values rendered inside a terms clause, or `none`
      --max-length=N       hard cap on the rendered lines, or `none`
      --agg-names          keep user-given aggregation names
      --raw-index          do not collapse logs-2026.08.13 to logs-*
      --hash-version=V     prefix marking which rules produced the hash
      --hash-length=N      hex characters kept from the sha256
TXT;
    }

    /**
     * A cap: a number, or `none` to lift it.
     *
     * @throws InvalidOptionException
     */
    private static function cap(string $option, string $value): ?int
    {
        if ($value === 'none') {
            return null;
        }

        return self::number($option, $value);
    }

    /**
     * @throws InvalidOptionException
     */
    private static function number(string $option, string $value): int
    {
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            throw InvalidOptionException::notAllowed($option, $value, ['a number', 'none']);
        }

        return (int) $value;
    }
}
