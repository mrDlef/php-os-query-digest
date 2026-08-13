<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest;

use MrDlef\OsQueryDigest\Exception\InvalidQueryException;
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
 */
final class Formatter
{
    /** @var Options */
    private $options;

    /** @var RequestParser */
    private $parser;

    /** @var Canonicalizer */
    private $canonicalizer;

    /** @var LineRenderer */
    private $renderer;

    /** @var Hasher */
    private $hasher;

    private function __construct(Options $options)
    {
        $this->options = $options;
        $this->parser = new RequestParser();
        $this->canonicalizer = new Canonicalizer();
        $this->renderer = new LineRenderer();
        $this->hasher = new Hasher($options->hashVersion(), $options->hashLength());
    }

    public static function create(?Options $options = null): self
    {
        return new self($options !== null ? $options : Options::create());
    }

    public function options(): Options
    {
        return $this->options;
    }

    /**
     * @param array<string,mixed>|string $request a search body, an
     *                                            `['index' => …, 'body' => …]`
     *                                            envelope, or the JSON of either
     */
    public function describe($request, ?string $index = null): Digest
    {
        $model = $this->model($this->toArray($request), $index);

        $textProfile = new RenderProfile(
            new LiteralValueRenderer($this->options->redactor()),
            false,
            $this->options->maxClauses(),
            $this->options->maxValues(),
            false,
            false,
            $this->options->includeAggNames()
        );

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
            $this->options->includeAggNames()
        );

        $text = $this->renderer->render($model, $textProfile);
        $signature = $this->renderer->render($model, $signatureProfile);

        // The hash is computed on the uncapped signature: display limits must
        // never influence identity, or a 200-value and a 300-value terms clause
        // would collide by accident of truncation.
        $hashInput = $this->renderer->render($model, $signatureProfile->uncapped());

        return new Digest(
            $model->index(),
            Truncator::apply($text, $this->options->maxLength()),
            Truncator::apply($signature, $this->options->maxLength()),
            $this->hasher->hash($hashInput),
            $model->notes()
        );
    }

    /**
     * Same as {@see describe()} but nothing is parsed until the value is read.
     *
     * @param array<string,mixed>|string $request
     */
    public function lazy($request, ?string $index = null): LazyDigest
    {
        return new LazyDigest(function () use ($request, $index): Digest {
            return $this->describe($request, $index);
        });
    }

    /**
     * @param array<string,mixed> $request
     */
    private function model(array $request, ?string $index): QueryModel
    {
        $model = $this->parser->parse($request, $index);

        $query = $model->query();
        $model = $model->withTree(
            $query !== null ? $this->canonicalizer->node($query) : null,
            $this->canonicalizer->aggs($model->aggs())
        );

        return $model->withIndex($this->options->indexNormalizer()->normalize($model->index()));
    }

    /**
     * @param mixed $request
     *
     * @return array<string,mixed>
     */
    private function toArray($request): array
    {
        if (is_array($request)) {
            return $request;
        }

        if (!is_string($request)) {
            throw InvalidQueryException::unexpectedType(gettype($request));
        }

        /** @var mixed $decoded */
        $decoded = json_decode($request, true);
        if (!is_array($decoded)) {
            throw InvalidQueryException::notDecodable(json_last_error_msg());
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }
}
