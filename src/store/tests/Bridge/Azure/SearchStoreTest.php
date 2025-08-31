<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Bridge\Azure;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\NullVector;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\Azure\SearchStore;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Uid\Uuid;

#[CoversClass(SearchStore::class)]
#[UsesClass(VectorDocument::class)]
#[UsesClass(Vector::class)]
final class SearchStoreTest extends TestCase
{
    public function testAddDocumentsSuccessfully()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'value' => [
                    ['key' => 'doc1', 'status' => true, 'errorMessage' => null, 'statusCode' => 201],
                ],
            ], [
                'http_code' => 200,
            ]),
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
        );

        $uuid = Uuid::v4();
        $document = new VectorDocument($uuid, new Vector([0.1, 0.2, 0.3]));

        $store->add($document);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testAddDocumentsWithMetadata()
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options): JsonMockResponse {
                $this->assertSame('POST', $method);
                $this->assertSame('https://test.search.windows.net/indexes/test-index/docs/index?api-version=2023-11-01', $url);
                // Check normalized headers as Symfony HTTP client might lowercase them
                $this->assertArrayHasKey('normalized_headers', $options);
                $this->assertIsArray($options['normalized_headers']);
                $this->assertArrayHasKey('api-key', $options['normalized_headers']);
                $this->assertSame(['api-key: test-api-key'], $options['normalized_headers']['api-key']);

                $this->assertArrayHasKey('body', $options);
                $this->assertIsString($options['body']);
                $body = json_decode($options['body'], true);
                $this->assertIsArray($body);
                $this->assertArrayHasKey('value', $body);
                $this->assertIsArray($body['value']);
                $this->assertCount(1, $body['value']);
                $this->assertArrayHasKey(0, $body['value']);
                $this->assertIsArray($body['value'][0]);
                $this->assertArrayHasKey('title', $body['value'][0]);
                $this->assertSame('Test Document', $body['value'][0]['title']);

                return new JsonMockResponse([
                    'value' => [
                        ['key' => 'doc1', 'status' => true, 'errorMessage' => null, 'statusCode' => 201],
                    ],
                ], [
                    'http_code' => 200,
                ]);
            },
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
        );

        $uuid = Uuid::v4();
        $document = new VectorDocument($uuid, new Vector([0.1, 0.2, 0.3]), new Metadata(['title' => 'Test Document']));

        $store->add($document);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testAddDocumentsFailure()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'error' => [
                    'code' => 'InvalidRequest',
                    'message' => 'Invalid document format',
                ],
            ], [
                'http_code' => 400,
            ]),
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
        );

        $uuid = Uuid::v4();
        $document = new VectorDocument($uuid, new Vector([0.1, 0.2, 0.3]));

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP 400 returned');
        $this->expectExceptionCode(400);

        $store->add($document);
    }

    public function testQueryReturnsDocuments()
    {
        $uuid1 = Uuid::v4();
        $uuid2 = Uuid::v4();

        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'value' => [
                    [
                        'id' => $uuid1->toRfc4122(),
                        'vector' => [0.1, 0.2, 0.3],
                        '@search.score' => 0.95,
                        'title' => 'First Document',
                    ],
                    [
                        'id' => $uuid2->toRfc4122(),
                        'vector' => [0.4, 0.5, 0.6],
                        '@search.score' => 0.85,
                        'title' => 'Second Document',
                    ],
                ],
            ], [
                'http_code' => 200,
            ]),
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
        );

        $results = $store->query(new Vector([0.1, 0.2, 0.3]));

        $this->assertCount(2, $results);
        $this->assertInstanceOf(VectorDocument::class, $results[0]);
        $this->assertInstanceOf(VectorDocument::class, $results[1]);
        $this->assertEquals($uuid1, $results[0]->id);
        $this->assertEquals($uuid2, $results[1]->id);
        $this->assertSame('First Document', $results[0]->metadata['title']);
        $this->assertSame('Second Document', $results[1]->metadata['title']);
    }

    public function testQueryWithCustomVectorFieldName()
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options): JsonMockResponse {
                $this->assertArrayHasKey('body', $options);
                $this->assertIsString($options['body']);
                $body = json_decode($options['body'], true);
                $this->assertIsArray($body);
                $this->assertArrayHasKey('vectorQueries', $body);
                $this->assertIsArray($body['vectorQueries']);
                $this->assertArrayHasKey(0, $body['vectorQueries']);
                $this->assertIsArray($body['vectorQueries'][0]);
                $this->assertArrayHasKey('fields', $body['vectorQueries'][0]);
                $this->assertSame('custom_vector_field', $body['vectorQueries'][0]['fields']);

                return new JsonMockResponse([
                    'value' => [
                        [
                            'id' => Uuid::v4()->toRfc4122(),
                            'custom_vector_field' => [0.1, 0.2, 0.3],
                            '@search.score' => 0.95,
                        ],
                    ],
                ], [
                    'http_code' => 200,
                ]);
            },
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
            'custom_vector_field',
        );

        $results = $store->query(new Vector([0.1, 0.2, 0.3]));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(VectorDocument::class, $results[0]);
    }

    public function testQueryFailure()
    {
        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'error' => [
                    'code' => 'InvalidRequest',
                    'message' => 'Invalid query format',
                ],
            ], [
                'http_code' => 400,
            ]),
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
        );

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('HTTP 400 returned');
        $this->expectExceptionCode(400);

        $store->query(new Vector([0.1, 0.2, 0.3]));
    }

    public function testQueryWithNullVector()
    {
        $uuid = Uuid::v4();

        $httpClient = new MockHttpClient([
            new JsonMockResponse([
                'value' => [
                    [
                        'id' => $uuid->toRfc4122(),
                        'vector' => null,
                        '@search.score' => 0.95,
                        'title' => 'Document without vector',
                    ],
                ],
            ], [
                'http_code' => 200,
            ]),
        ]);

        $store = new SearchStore(
            $httpClient,
            'https://test.search.windows.net',
            'test-api-key',
            'test-index',
            '2023-11-01',
        );

        $results = $store->query(new Vector([0.1, 0.2, 0.3]));

        $this->assertCount(1, $results);
        $this->assertInstanceOf(VectorDocument::class, $results[0]);
        $this->assertInstanceOf(NullVector::class, $results[0]->vector);
    }
}
