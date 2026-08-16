<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Parser;

use MrDlef\OsQueryDigest\Support\Arr;
use MrDlef\OsQueryDigest\Tree\AggNode;

/**
 * Turns the `aggs` section into a tree of {@see AggNode}.
 *
 * Aggregations carry a lot of intent — a query with `terms(host) > p95(latency)`
 * is recognisably a latency-per-host dashboard panel — so they are part of the
 * digest, not an afterthought.
 */
final class AggParser
{
    /** Keys that live beside the agg type and are not agg types themselves. */
    private const RESERVED = ['aggs', 'aggregations', 'meta'];

    /**
     * @param array<mixed> $aggs
     *
     * @return AggNode[]
     */
    public function parse(array $aggs): array
    {
        $out = [];

        foreach ($aggs as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $node = $this->one((string) $name, $definition);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $definition
     */
    private function one(string $name, array $definition): ?AggNode
    {
        $type = null;
        /** @var array<mixed> $body */
        $body = [];

        foreach ($definition as $key => $value) {
            if (in_array((string) $key, self::RESERVED, true)) {
                continue;
            }
            $type = (string) $key;
            $body = is_array($value) ? $value : [];
            break;
        }

        if ($type === null) {
            return null;
        }

        $children = [];
        foreach (['aggs', 'aggregations'] as $slot) {
            $sub = Arr::get($definition, $slot);
            if (is_array($sub)) {
                $children = array_merge($children, $this->parse($sub));
            }
        }

        [$type, $field, $params] = $this->describe($type, $body);

        return new AggNode($name, $type, $field, $params, $children);
    }

    /**
     * @param array<mixed> $body
     *
     * @return array{0:string,1:string|null,2:array<int,string>}
     */
    private function describe(string $type, array $body): array
    {
        $field = Arr::get($body, 'field');
        $field = is_string($field) ? $field : null;
        $params = [];

        switch ($type) {
            case 'terms':
            case 'significant_terms':
            case 'rare_terms':
                $size = Arr::get($body, 'size');
                if (is_int($size) || (is_string($size) && ctype_digit($size))) {
                    $params[] = (string) (int) $size;
                }
                break;

            case 'date_histogram':
                foreach (['calendar_interval', 'fixed_interval', 'interval'] as $key) {
                    $interval = Arr::get($body, $key);
                    if (is_string($interval)) {
                        $params[] = $interval;
                        break;
                    }
                }
                break;

            case 'histogram':
                $interval = Arr::get($body, 'interval');
                if (is_scalar($interval)) {
                    $params[] = (string) $interval;
                }
                break;

            case 'percentiles':
                $percents = Arr::get($body, 'percents');
                if (is_array($percents) && count($percents) === 1) {
                    // The common single-percentile case reads much better as p95.
                    return ['p' . $this->trimNumber(Arr::str(reset($percents))), $field, []];
                }
                if (is_array($percents) && $percents !== []) {
                    $trimmed = [];
                    foreach (Arr::strings($percents) as $percent) {
                        $trimmed[] = $this->trimNumber($percent);
                    }
                    $params[] = '[' . implode(',', $trimmed) . ']';
                }
                break;

            case 'filter':
            case 'filters':
            case 'nested':
            case 'reverse_nested':
            case 'composite':
            case 'top_hits':
                // Structural aggs: the type alone is the information.
                $field = null;
                break;
        }

        return [$type, $field, $params];
    }

    private function trimNumber(string $value): string
    {
        if (strpos($value, '.') === false) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }
}
