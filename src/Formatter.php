<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Exception\InvalidQueryException;
use MrDlef\OsQueryDigest\Explain\Explanation;
use MrDlef\OsQueryDigest\Explain\Rule;
use MrDlef\OsQueryDigest\Explain\Trace;
use MrDlef\OsQueryDigest\Extension\ClauseRenderer;
use MrDlef\OsQueryDigest\Fingerprint\Hasher;
use MrDlef\OsQueryDigest\Normalizer\Canonicalizer;
use MrDlef\OsQueryDigest\Parser\RequestParser;
use MrDlef\OsQueryDigest\Render\LineRenderer;
use MrDlef\OsQueryDigest\Render\LiteralValueRenderer;
use MrDlef\OsQueryDigest\Render\PlaceholderValueRenderer;
use MrDlef\OsQueryDigest\Render\RenderProfile;
use MrDlef\OsQueryDigest\Support\Truncator;
use MrDlef\OsQueryDigest\Tree\QueryModel;

/**
 * Entry point.
 *
 *   $formatter = Formatter::create();
 *   $logger->info('search', ['q' => $formatter->lazy($request, 'logs-*')]);
 *
 * @api
 */
final class Formatter
{
    private Options $options;

    private RequestParser $parser;

    private Canonicalizer $canonicalizer;

    private LineRenderer $renderer;

    private Hasher $hasher;

    private function __construct(Options $options)
    {
        $this->options = $options;
        $this->parser = new RequestParser($options->clauseRenderers());
        $this->canonicalizer = new Canonicalizer();
        $this->renderer = new LineRenderer();
        $this->hasher = new Hasher(self::hashVersion($options), $options->hashLength());
    }

    /**
     * The algorithm version, marked when the rules are no longer this library's
     * alone.
     *
     * A registered {@see ClauseRenderer} is a local normalisation rule: it
     * changes the fingerprint of every query using its type. That is exactly
     * what a prefix bump exists to make visible — `q1:` → `q2:` → `q3:` →
     * `q4:` → `q5:`
     * says "an older set of rules minted this", and `q5x:` says "someone's
     * plugin code took part in minting this, so do not expect it to match a
     * stock digest of the same query".
     *
     * A plain marker rather than a digest of the registered types: a renderer's
     * *behaviour* can change without its type name changing, so anything more
     * precise would promise a completeness it cannot deliver. Bumping
     * `withHashVersion()` when your own renderer changes is yours to do, the
     * same way it is ours.
     */
    private static function hashVersion(Options $options): string
    {
        return $options->clauseRenderers() === []
            ? $options->hashVersion()
            : $options->hashVersion() . 'x';
    }

    public static function create(?Options $options = null): self
    {
        return new self($options ?? Options::create());
    }

    public function options(): Options
    {
        return $this->options;
    }

    /**
     * @param array<mixed>|string $request a search body, an
     *                                     `['index' => …, 'body' => …]`
     *                                     envelope, or the JSON of either
     */
    public function describe($request, ?string $index = null): Digest
    {
        return $this->digest($this->model($this->toArray($request), $index, new Trace()));
    }

    /**
     * The same digest, plus the list of normalisation rules that produced it.
     *
     * Use it to answer "why do these two queries share a hash?" — diff the two
     * explanations and the rule that merged them is named.
     *
     * @param array<mixed>|string $request
     */
    public function explain($request, ?string $index = null): Explanation
    {
        $trace = new Trace();
        $model = $this->model($this->toArray($request), $index, $trace);

        return new Explanation($this->digest($model), $trace->rules());
    }

    private function digest(QueryModel $model): Digest
    {
        $normalization = $this->options->normalization();
        $signatureProfile = new RenderProfile(
            $normalization->erasesValues()
                ? new PlaceholderValueRenderer()
                : new LiteralValueRenderer($this->options->redactor()),
            true,
            $this->options->maxClauses(),
            $this->options->maxValues(),
            $normalization->erasesCardinality(),
            $normalization->erasesPagination(),
            $this->options->includeAggNames(),
        );

        // Not rendered at all when it is not wanted, rather than rendered and
        // dropped: it is the one of the three renders that has values in it.
        $text = null;
        if ($this->options->emitText()) {
            $text = $this->renderer->render($model, new RenderProfile(
                new LiteralValueRenderer($this->options->redactor()),
                false,
                $this->options->maxClauses(),
                $this->options->maxValues(),
                false,
                false,
                $this->options->includeAggNames(),
            ));
        }

        $signature = $this->renderer->render($model, $signatureProfile);

        // The hash is computed on the uncapped signature: display limits must
        // never influence identity, or a 200-value and a 300-value terms clause
        // would collide by accident of truncation.
        $hashInput = $this->renderer->render($model, $signatureProfile->uncapped());

        return new Digest(
            $model->index(),
            $text === null ? null : Truncator::apply($text, $this->options->maxLength()),
            Truncator::apply($signature, $this->options->maxLength()),
            $this->hasher->hash($hashInput),
            $model->notes(),
        );
    }

    /**
     * Same as {@see describe()} but nothing is parsed until the value is read.
     *
     * @param array<mixed>|string $request
     */
    public function lazy($request, ?string $index = null): LazyDigest
    {
        return new LazyDigest(fn(): Digest => $this->describe($request, $index));
    }

    /**
     * @param array<mixed> $request
     */
    private function model(array $request, ?string $index, Trace $trace): QueryModel
    {
        $model = $this->parser->parse($request, $index, $trace);

        $query = $model->query();
        $postFilter = $model->postFilter();
        $model = $model->withTree(
            $query !== null ? $this->canonicalizer->node($query, $trace) : null,
            $postFilter !== null ? $this->canonicalizer->node($postFilter, $trace) : null,
            $this->canonicalizer->aggs($model->aggs(), $trace),
        );

        $pattern = $this->options->indexNormalizer()->normalize($model->index());
        if ($pattern !== $model->index()) {
            $trace->record(Rule::INDEX_PATTERN, $model->index() . ' -> ' . $pattern);
        }

        return $model->withIndex($pattern);
    }

    /**
     * @param mixed $request
     *
     * @return array<mixed>
     */
    private function toArray($request): array
    {
        if (is_array($request)) {
            return $request;
        }

        if (!is_string($request)) {
            throw InvalidQueryException::unexpectedType(gettype($request));
        }

        $decoded = json_decode($request, true);
        if (!is_array($decoded)) {
            throw InvalidQueryException::notDecodable(json_last_error_msg());
        }

        return $decoded;
    }
}
