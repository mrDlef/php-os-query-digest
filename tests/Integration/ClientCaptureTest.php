<?php

declare(strict_types=1);

namespace MrDlef\OsQueryDigest\Tests\Integration;

use Elasticsearch\ClientBuilder as ElasticsearchClientBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use MrDlef\OsQueryDigest\Formatter;
use MrDlef\OsQueryDigest\Http\DigestingClient;
use MrDlef\OsQueryDigest\Http\Guzzle\DigestMiddleware;
use MrDlef\OsQueryDigest\Http\ObservedSearch;
use MrDlef\OsQueryDigest\Http\Ring\DigestingHandler;
use MrDlef\OsQueryDigest\Http\SearchObserver;
// Both clients ship a `ClientBuilder`, and they are not interchangeable: one
// speaks ringphp and the other is the deprecated half of a package whose modern
// half speaks PSR-18. Aliased so no line in this file is ambiguous about which.
use OpenSearch\Client as OpenSearchClient;
use OpenSearch\ClientBuilder as OpenSearchClientBuilder;
use OpenSearch\EndpointFactory;
use OpenSearch\GuzzleClientFactory;
use OpenSearch\RequestFactory;
use OpenSearch\Serializers\SmartSerializer;
use OpenSearch\TransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

/**
 * Which clients the transport integrations can actually capture, against the
 * clients themselves.
 *
 * Every other claim this library makes about the outside world is checked
 * against it: which OpenSearch versions accept what we render is a committed
 * matrix replayed on real nodes, and which query types exist is a snapshot of
 * the official specification. **Which clients can be captured was prose**, and
 * it was wrong in one direction and then wrong in the other without anything
 * failing — see the entry for `v0.13.0` in `CHANGELOG.md`.
 *
 * So it is a test now, and the table in `docs/reference/coverage.md` is what it
 * asserts.
 *
 *     cd tools/clients && composer update      # once
 *     OPENSEARCH_URL=http://localhost:9202 vendor/bin/phpunit --testsuite=integration
 *
 * The clients live in `tools/clients/composer.json` rather than in the root
 * `require-dev`, because `opensearch-project/opensearch-php` requires PHP 8.2
 * and this library's floor is 7.4. Without that tree, this whole class skips —
 * so nothing in the ordinary matrix has to know it exists.
 */
final class ClientCaptureTest extends TestCase
{
    private const INDEX = 'os-query-digest-clients';

    /**
     * One search, sent by every client, so the fingerprints are comparable —
     * which is the assertion the others exist to make possible.
     */
    private const BODY = '{"query":{"bool":{"filter":[{"term":{"service":"api"}}],'
        . '"must_not":[{"term":{"status":200}}]}},"size":5}';

    private const BATCH = '{}' . "\n"
        . '{"query":{"term":{"service":"api"}}}' . "\n"
        . '{"index":"' . self::INDEX . '"}' . "\n"
        . '{"query":{"match_all":{}}}' . "\n";

    private string $url = '';

    protected function setUp(): void
    {
        $url = getenv('OPENSEARCH_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set OPENSEARCH_URL to run against a cluster.');
        }

        $autoload = dirname(__DIR__, 2) . '/tools/clients/vendor/autoload.php';
        if (!is_file($autoload)) {
            self::markTestSkipped('Run `cd tools/clients && composer update` to check the clients.');
        }

        require_once $autoload;

        $this->url = rtrim($url, '/');

        $bare = new GuzzleClient(['base_uri' => $this->url, 'http_errors' => false]);
        $bare->delete('/' . self::INDEX);
        $bare->put('/' . self::INDEX, [
            'json' => ['mappings' => ['properties' => [
                'service' => ['type' => 'keyword'],
                'status' => ['type' => 'integer'],
            ]]],
        ]);
        $bare->post('/' . self::INDEX . '/_doc?refresh=true', [
            'json' => ['service' => 'api', 'status' => 500],
        ]);
    }

    /**
     * `opensearch-php` ≥ 2.4 takes the PSR-18 client you give it and calls
     * `sendRequest()` on it synchronously, so the decorator sees everything —
     * including both lines of an `_msearch`, split by the extractor rather than
     * by the client.
     */
    public function testTheDecoratorCapturesModernOpenSearchPhp(): void
    {
        $observer = self::observer();
        $client = $this->openSearchPhpThrough(new DigestingClient(
            new GuzzleClient([
                'base_uri' => $this->url,
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ]),
            $observer,
        ));

        $client->search(['index' => self::INDEX, 'body' => json_decode(self::BODY, true)]);
        $client->msearch(['body' => self::BATCH]);

        self::assertCount(3, $observer->seen);
        self::assertIsInt($observer->seen[0]->tookMillis(), 'A single search reports its own took.');
        self::assertNull($observer->seen[1]->tookMillis(), 'A batch reports one took for all of its lines.');
        self::assertSame([null, 0, 1], [
            $observer->seen[0]->position(),
            $observer->seen[1]->position(),
            $observer->seen[2]->position(),
        ]);
    }

    /**
     * And the middleware reaches the same client, because `GuzzleClientFactory`
     * takes one — which is the half of the guide that used to be right for the
     * wrong reason.
     */
    public function testTheMiddlewareCapturesModernOpenSearchPhp(): void
    {
        $observer = self::observer();

        $client = (new GuzzleClientFactory())->create([
            'base_uri' => $this->url,
            'middleware' => [new DigestMiddleware($observer)],
        ]);

        $client->search(['index' => self::INDEX, 'body' => json_decode(self::BODY, true)]);

        self::assertCount(1, $observer->seen);
        self::assertIsInt($observer->seen[0]->tookMillis());
    }

    /**
     * `elasticsearch/elasticsearch` 7.x transports over `ezimuel/ringphp`, which
     * is still a common way to reach an OpenSearch cluster and the reason the
     * ring handler exists.
     */
    public function testTheRingHandlerCapturesElasticsearchPhp7(): void
    {
        $observer = self::observer();
        $client = ElasticsearchClientBuilder::create()
            ->setHosts([$this->url])
            ->setHandler(new DigestingHandler(
                ElasticsearchClientBuilder::defaultHandler(),
                $observer,
            ))
            ->build();

        $client->search(['index' => self::INDEX, 'body' => json_decode(self::BODY, true)]);
        $client->indices()->stats(['index' => self::INDEX]);

        self::assertCount(1, $observer->seen, 'The stats call is not a search and must not be counted.');
        self::assertIsInt($observer->seen[0]->tookMillis());
    }

    /**
     * `opensearch-php`'s own legacy transport, which the coverage table names
     * separately. Deprecated in 2.4.0 and to be removed in 3.0.0 — when it goes,
     * this skips and says so, which is the notice the table needs.
     */
    public function testTheRingHandlerCapturesTheDeprecatedClientBuilder(): void
    {
        if (!class_exists(OpenSearchClientBuilder::class)) {
            self::markTestSkipped('opensearch-php has dropped ClientBuilder — the coverage table can lose its row.');
        }

        $observer = self::observer();

        $previous = error_reporting();
        error_reporting($previous & ~E_USER_DEPRECATED);

        try {
            $client = OpenSearchClientBuilder::create()
                ->setHosts([$this->url])
                ->setHandler(new DigestingHandler(
                    OpenSearchClientBuilder::defaultHandler(),
                    $observer,
                ))
                ->build();

            $client->search(['index' => self::INDEX, 'body' => json_decode(self::BODY, true)]);
        } finally {
            error_reporting($previous);
        }

        self::assertCount(1, $observer->seen);
        self::assertIsInt($observer->seen[0]->tookMillis());
    }

    /**
     * The claim the other four exist to make possible, and the one a reader of
     * the coverage table actually depends on: **the same search minted by
     * different clients through different integrations is one shape.** Two
     * integrations disagreeing on one query is a bug neither one's own tests
     * would find.
     */
    public function testEveryPathMintsOneFingerprintForOneSearch(): void
    {
        $hashes = ['formatter' => Formatter::create()->describe(self::BODY, self::INDEX)->hash()];

        $decorator = self::observer();
        $this->openSearchPhpThrough(new DigestingClient(
            new GuzzleClient([
                'base_uri' => $this->url,
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            ]),
            $decorator,
        ))->search(['index' => self::INDEX, 'body' => json_decode(self::BODY, true)]);
        $hashes['decorator, opensearch-php'] = $decorator->seen[0]->digest()->digest()->hash();

        $middleware = self::observer();
        $stack = HandlerStack::create();
        $stack->push(new DigestMiddleware($middleware));
        (new GuzzleClient(['handler' => $stack, 'base_uri' => $this->url, 'http_errors' => false]))
            ->post('/' . self::INDEX . '/_search', [
                'body' => self::BODY,
                'headers' => ['Content-Type' => 'application/json'],
            ]);
        $hashes['middleware, plain Guzzle'] = $middleware->seen[0]->digest()->digest()->hash();

        $ring = self::observer();
        ElasticsearchClientBuilder::create()
            ->setHosts([$this->url])
            ->setHandler(new DigestingHandler(ElasticsearchClientBuilder::defaultHandler(), $ring))
            ->build()
            ->search(['index' => self::INDEX, 'body' => json_decode(self::BODY, true)]);
        $hashes['ring handler, elasticsearch-php 7'] = $ring->seen[0]->digest()->digest()->hash();

        self::assertMatchesRegularExpression('/^q\d+x?:[0-9a-f]{12}$/', $hashes['formatter']);
        self::assertSame(
            [$hashes['formatter']],
            array_values(array_unique($hashes)),
            'Four ways to the same search, and they do not agree: ' . json_encode($hashes),
        );
    }

    /**
     * Why the ring handler had to exist at all, asserted rather than asserted
     * about. If a client grows one of these, the coverage table is out of date.
     */
    public function testARingphpClientStillOffersNeitherSeam(): void
    {
        self::assertInstanceOf(
            \Closure::class,
            ElasticsearchClientBuilder::defaultHandler(),
            'A ring handler is a callable, not a Guzzle handler stack.',
        );
        self::assertNotInstanceOf(
            ClientInterface::class,
            ElasticsearchClientBuilder::create()->setHosts([$this->url])->build(),
            'A ringphp client is not a PSR-18 client, so there is no sendRequest() to decorate.',
        );
    }

    /**
     * The client the modern transport builds, wrapped around whatever PSR-18
     * client it is handed.
     */
    private function openSearchPhpThrough(DigestingClient $psr18): OpenSearchClient
    {
        $serializer = new SmartSerializer();
        $factory = new HttpFactory();

        return new OpenSearchClient(
            (new TransportFactory())
                ->setHttpClient($psr18)
                ->setRequestFactory(
                    new RequestFactory($factory, $factory, $factory, $serializer),
                )
                ->create(),
            new EndpointFactory($serializer),
            [],
        );
    }

    /**
     * @return SearchObserver&object{seen: array<int,ObservedSearch>}
     */
    private static function observer(): object
    {
        return new class implements SearchObserver {
            /** @var array<int,ObservedSearch> */
            public array $seen = [];

            public function observe(ObservedSearch $search): void
            {
                $this->seen[] = $search;
            }
        };
    }
}
