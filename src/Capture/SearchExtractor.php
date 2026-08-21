<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Capture;

use MrDlef\OsQueryDigest\Support\Arr;

/**
 * Decides whether an outgoing HTTP request is a search, and if so pulls the
 * searches out of it.
 *
 * This is the whole of what the transport integrations know about OpenSearch;
 * the PSR-18 decorator and the Guzzle middleware are adapters that hand it a
 * method, a path and a body and pass what comes back to a formatter. Keeping it
 * here means it is testable without a client, a server or a dependency.
 *
 * **It never throws.** It runs in the path of a request the application is
 * about to make, where losing the digest is acceptable and losing the request
 * is not. Anything it cannot read yields no searches.
 *
 * Four things about the URL are worth knowing before changing this:
 *
 * - **The index reaches the fingerprint.** It is in the text and in the
 *   signature, so a wrong index is a wrong hash, not a cosmetic slip. That is
 *   why the shapes below are recognised strictly rather than guessed at.
 * - **`/proxy/_search` and `/logs/_search` are the same URL** to anything
 *   reading it. A cluster behind a path prefix must say so — hence
 *   {@see create()}'s `$basePath`.
 * - **An index name can contain a slash.** Date-math names arrive percent-
 *   encoded — `%3Clogs-%7Bnow%2Fd%7D%3E` for `<logs-{now/d}>` — so the path is
 *   split on `/` *first* and each segment decoded after, never the reverse.
 * - **`_search` is a prefix of endpoints that carry no query.**
 *   `_search/scroll` sends a scroll id, `_search/template` an id and its
 *   params, `_search/point_in_time` nothing at all. Digesting those would mint
 *   fingerprints for requests that have no shape — so the endpoint has to be the
 *   *last* segment of the path, not merely one of them.
 * - **An index expression can start with an underscore.** `_all` is one, and it
 *   is the reason the endpoint is found from the right: a rule that took the
 *   first underscored segment for the endpoint would read `/_all/_search` as an
 *   endpoint called `_all` and digest none of it.
 *
 * @internal
 */
final class SearchExtractor
{
    private const SEARCH = '_search';

    private const MSEARCH = '_msearch';

    /**
     * The only methods that carry a search body. A body on anything else is
     * some other API, and digesting it would put a fingerprint on a request
     * that has no query in it.
     *
     * @var array<int,string>
     */
    private const METHODS = ['GET', 'POST'];

    private string $basePath;

    private function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * @param string $basePath the path prefix the cluster is mounted under, if
     *                         it is behind a proxy — `/opensearch` for a node
     *                         reached at `/opensearch/logs/_search`. Without
     *                         it that prefix is read as the index name, and
     *                         lands in every fingerprint.
     */
    public static function create(string $basePath = ''): self
    {
        return new self(trim($basePath, '/'));
    }

    /**
     * The searches in one request: none if it is not one, one for a `_search`,
     * and one per pair of lines for a `_msearch`.
     *
     * @return array<int,CapturedSearch>
     */
    public function extract(string $method, string $path, string $body): array
    {
        if ($body === '' || !in_array(strtoupper($method), self::METHODS, true)) {
            return [];
        }

        $segments = $this->segments($path);
        if ($segments === null) {
            return [];
        }

        [$endpoint, $index] = $segments;

        if ($endpoint === self::SEARCH) {
            return [new CapturedSearch($index, $body)];
        }

        return $this->msearch($index, $body);
    }

    /**
     * The endpoint and the index it names, or null when the path is not a shape
     * this reads.
     *
     * @return array{0:string,1:?string}|null
     */
    private function segments(string $path): ?array
    {
        $path = $this->strip($path);
        if ($path === null) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment !== '') {
                $segments[] = rawurldecode($segment);
            }
        }

        // The endpoint is the last segment, and nothing else counts. That is
        // what makes `/_search/scroll` and `/logs/_search/template` fall out on
        // their own, and what lets an index expression carry an underscore.
        $endpoint = array_pop($segments);
        if ($endpoint !== self::SEARCH && $endpoint !== self::MSEARCH) {
            return null;
        }

        // `/logs/_search` names one index and `/_search` names none. Two
        // segments is the removed mapping-type form, `/logs/type/_search`,
        // which no supported version accepts — and picking one of the two to
        // call the index would put a name of our choosing in the fingerprint.
        if (count($segments) > 1) {
            return null;
        }

        return [$endpoint, $segments === [] ? null : $segments[0]];
    }

    /**
     * The path with the configured prefix removed, or null when the prefix is
     * configured and the path does not carry it — that request went somewhere
     * else.
     *
     * Cut before decoding: a percent-encoded `?` inside a date-math index name
     * is part of the name, not the start of a query string.
     */
    private function strip(string $path): ?string
    {
        $cut = strcspn($path, '?#');
        $path = trim(substr($path, 0, $cut), '/');

        if ($this->basePath === '') {
            return $path;
        }

        if ($path === $this->basePath) {
            return '';
        }

        $prefix = $this->basePath . '/';

        return strncmp($path, $prefix, strlen($prefix)) === 0
            ? substr($path, strlen($prefix))
            : null;
    }

    /**
     * A `_msearch` body is newline-delimited pairs: a header line naming the
     * target, then the search body. The header may be `{}`, and the index it
     * names — a string or a list of them — overrides the one in the URL, which
     * is the default for the lines that leave it out.
     *
     * A header with no line after it is a truncated body, and is dropped rather
     * than digested as a search of its own.
     *
     * @return array<int,CapturedSearch>
     */
    private function msearch(?string $urlIndex, string $body): array
    {
        $searches = [];
        $header = null;
        $position = 0;

        // Split on the newline the format is defined in terms of, and let the
        // trim take the `\r` of a CRLF client with it — along with any padding
        // whoever assembled the body left behind.
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($header === null) {
                $header = $line;
                continue;
            }

            $searches[] = new CapturedSearch(self::headerIndex($header, $urlIndex), $line, $position);
            ++$position;
            $header = null;
        }

        return $searches;
    }

    /**
     * A header that will not decode falls back to the URL's index rather than
     * dropping the search: the pairing is positional, so the body on the next
     * line is still a search, and one whose target is merely less precise.
     */
    private static function headerIndex(string $header, ?string $fallback): ?string
    {
        $decoded = json_decode($header, true);
        if (!is_array($decoded)) {
            return $fallback;
        }

        $index = Arr::get($decoded, 'index');

        $names = [];
        foreach (is_array($index) ? Arr::strings($index) : [Arr::str($index)] as $name) {
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? $fallback : implode(',', $names);
    }
}
