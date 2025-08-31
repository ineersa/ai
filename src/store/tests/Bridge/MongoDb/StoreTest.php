<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Bridge\MongoDb;

use MongoDB\BSON\Binary;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Driver\CursorInterface;
use MongoDB\Driver\Exception\CommandException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\MongoDb\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Store::class)]
#[UsesClass(VectorDocument::class)]
#[UsesClass(Vector::class)]
final class StoreTest extends TestCase
{
    public function testAddSingleDocument()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $uuid = Uuid::v4();
        $expectedBinary = new Binary($uuid->toBinary(), Binary::TYPE_UUID);

        $collection->expects($this->once())
            ->method('replaceOne')
            ->with(
                ['_id' => $expectedBinary],
                [
                    'metadata' => ['title' => 'Test Document'],
                    'vector' => [0.1, 0.2, 0.3],
                ],
                ['upsert' => true],
            );

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $document = new VectorDocument($uuid, new Vector([0.1, 0.2, 0.3]), new Metadata(['title' => 'Test Document']));
        $store->add($document);
    }

    public function testAddMultipleDocuments()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->exactly(2))
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $uuid1 = Uuid::v4();
        $uuid2 = Uuid::v4();

        $collection->expects($this->exactly(2))
            ->method('replaceOne');

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $document1 = new VectorDocument($uuid1, new Vector([0.1, 0.2, 0.3]));
        $document2 = new VectorDocument($uuid2, new Vector([0.4, 0.5, 0.6]), new Metadata(['title' => 'Test']));

        $store->add($document1, $document2);
    }

    public function testAddWithBulkWrite()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $uuid1 = Uuid::v4();
        $uuid2 = Uuid::v4();

        $collection->expects($this->once())
            ->method('bulkWrite')
            ->with([
                [
                    'replaceOne' => [
                        ['_id' => new Binary($uuid1->toBinary(), Binary::TYPE_UUID)],
                        [
                            'vector' => [0.1, 0.2, 0.3],
                        ],
                        ['upsert' => true],
                    ],
                ],
                [
                    'replaceOne' => [
                        ['_id' => new Binary($uuid2->toBinary(), Binary::TYPE_UUID)],
                        [
                            'metadata' => ['title' => 'Test'],
                            'vector' => [0.4, 0.5, 0.6],
                        ],
                        ['upsert' => true],
                    ],
                ],
            ]);

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
            'vector',
            bulkWrite: true,
        );

        $document1 = new VectorDocument($uuid1, new Vector([0.1, 0.2, 0.3]));
        $document2 = new VectorDocument($uuid2, new Vector([0.4, 0.5, 0.6]), new Metadata(['title' => 'Test']));

        $store->add($document1, $document2);
    }

    public function testQueryReturnsDocuments()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $uuid1 = Uuid::v4();
        $uuid2 = Uuid::v4();

        $results = [
            [
                '_id' => new Binary($uuid1->toBinary(), Binary::TYPE_UUID),
                'vector' => [0.1, 0.2, 0.3],
                'metadata' => ['title' => 'First Document'],
                'score' => 0.95,
            ],
            [
                '_id' => new Binary($uuid2->toBinary(), Binary::TYPE_UUID),
                'vector' => [0.4, 0.5, 0.6],
                'metadata' => ['title' => 'Second Document'],
                'score' => 0.85,
            ],
        ];

        $cursor = $this->createMock(CursorInterface::class);
        $cursor->method('rewind'); // void return type
        $cursor->method('valid')->willReturnOnConsecutiveCalls(true, true, false);
        $cursor->method('current')->willReturnOnConsecutiveCalls($results[0], $results[1]);
        $cursor->method('next'); // void return type
        $cursor->method('key')->willReturnOnConsecutiveCalls(0, 1);

        $collection->expects($this->once())
            ->method('aggregate')
            ->with(
                [
                    [
                        '$vectorSearch' => [
                            'index' => 'test-index',
                            'path' => 'vector',
                            'queryVector' => [0.1, 0.2, 0.3],
                            'numCandidates' => 200,
                            'limit' => 5,
                        ],
                    ],
                    [
                        '$addFields' => [
                            'score' => ['$meta' => 'vectorSearchScore'],
                        ],
                    ],
                ],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            )
            ->willReturn($cursor);

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $documents = $store->query(new Vector([0.1, 0.2, 0.3]));

        $this->assertCount(2, $documents);
        $this->assertInstanceOf(VectorDocument::class, $documents[0]);
        $this->assertInstanceOf(VectorDocument::class, $documents[1]);
        $this->assertEquals($uuid1, $documents[0]->id);
        $this->assertEquals($uuid2, $documents[1]->id);
        $this->assertSame(0.95, $documents[0]->score);
        $this->assertSame(0.85, $documents[1]->score);
        $this->assertSame('First Document', $documents[0]->metadata['title']);
        $this->assertSame('Second Document', $documents[1]->metadata['title']);
    }

    public function testQueryWithMinScore()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('aggregate')
            ->with(
                [
                    [
                        '$vectorSearch' => [
                            'index' => 'test-index',
                            'path' => 'vector',
                            'queryVector' => [0.1, 0.2, 0.3],
                            'numCandidates' => 200,
                            'limit' => 5,
                        ],
                    ],
                    [
                        '$addFields' => [
                            'score' => ['$meta' => 'vectorSearchScore'],
                        ],
                    ],
                    [
                        '$match' => [
                            'score' => ['$gte' => 0.8],
                        ],
                    ],
                ],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            )
            ->willReturnCallback(function () {
                $cursor = $this->createMock(CursorInterface::class);
                $cursor->method('rewind'); // void return type
                $cursor->method('valid')->willReturn(false);

                return $cursor;
            });

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $documents = $store->query(new Vector([0.1, 0.2, 0.3]), ['minScore' => 0.8]);

        $this->assertCount(0, $documents);
    }

    public function testQueryWithOptions()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('aggregate')
            ->with(
                [
                    [
                        '$vectorSearch' => [
                            'index' => 'test-index',
                            'path' => 'vector',
                            'queryVector' => [0.1, 0.2, 0.3],
                            'numCandidates' => 500,
                            'limit' => 10,
                            'filter' => ['category' => 'test'],
                        ],
                    ],
                    [
                        '$addFields' => [
                            'score' => ['$meta' => 'vectorSearchScore'],
                        ],
                    ],
                ],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            )
            ->willReturnCallback(function () {
                $cursor = $this->createMock(CursorInterface::class);
                $cursor->method('rewind'); // void return type
                $cursor->method('valid')->willReturn(false);

                return $cursor;
            });

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $documents = $store->query(new Vector([0.1, 0.2, 0.3]), [
            'limit' => 10,
            'numCandidates' => 500,
            'filter' => ['category' => 'test'],
        ]);

        $this->assertCount(0, $documents);
    }

    public function testInitializeCreatesIndex()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('createSearchIndex')
            ->with(
                [
                    'fields' => [
                        [
                            'numDimensions' => 1536,
                            'path' => 'vector',
                            'similarity' => 'euclidean',
                            'type' => 'vector',
                        ],
                    ],
                ],
                [
                    'name' => 'test-index',
                    'type' => 'vectorSearch',
                ],
            );

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $store->setup();
    }

    public function testInitializeWithOptions()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('createSearchIndex')
            ->with(
                [
                    'fields' => [
                        [
                            'numDimensions' => 1536,
                            'path' => 'vector',
                            'similarity' => 'euclidean',
                            'type' => 'vector',
                        ],
                        [
                            'path' => 'title',
                            'type' => 'string',
                        ],
                    ],
                ],
                [
                    'name' => 'test-index',
                    'type' => 'vectorSearch',
                ],
            );

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $store->setup([
            'fields' => [
                [
                    'path' => 'title',
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public function testInitializeWithInvalidOptions()
    {
        $client = $this->createMock(Client::class);

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The only supported option is "fields"');

        $store->setup(['invalid' => 'option']);
    }

    public function testInitializeHandlesCommandException()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(NullLogger::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $exception = new CommandException('Index already exists');
        $collection->expects($this->once())
            ->method('createSearchIndex')
            ->willThrowException($exception);

        $logger->expects($this->once())
            ->method('warning')
            ->with('Index already exists');

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
            'vector',
            false,
            $logger,
        );

        $store->setup();
    }

    public function testItCanDropCollection()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('drop');

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
        );

        $store->drop();
    }

    public function testQueryWithCustomVectorFieldName()
    {
        $collection = $this->createMock(Collection::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getCollection')
            ->with('test-db', 'test-collection')
            ->willReturn($collection);

        $uuid = Uuid::v4();

        $results = [
            [
                '_id' => new Binary($uuid->toBinary(), Binary::TYPE_UUID),
                'custom_embeddings' => [0.1, 0.2, 0.3],
                'metadata' => ['title' => 'Document'],
                'score' => 0.95,
            ],
        ];

        $collection->expects($this->once())
            ->method('aggregate')
            ->with(
                [
                    [
                        '$vectorSearch' => [
                            'index' => 'test-index',
                            'path' => 'custom_embeddings',
                            'queryVector' => [0.1, 0.2, 0.3],
                            'numCandidates' => 200,
                            'limit' => 5,
                        ],
                    ],
                    [
                        '$addFields' => [
                            'score' => ['$meta' => 'vectorSearchScore'],
                        ],
                    ],
                ],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
            )
            ->willReturnCallback(function () use ($results) {
                $cursor = $this->createMock(CursorInterface::class);
                $cursor->method('rewind'); // void return type
                $cursor->method('valid')->willReturnOnConsecutiveCalls(true, false);
                $cursor->method('current')->willReturn($results[0]);
                $cursor->method('next'); // void return type
                $cursor->method('key')->willReturn(0);

                return $cursor;
            });

        $store = new Store(
            $client,
            'test-db',
            'test-collection',
            'test-index',
            'custom_embeddings',
        );

        $documents = $store->query(new Vector([0.1, 0.2, 0.3]));

        $this->assertCount(1, $documents);
        $this->assertSame([0.1, 0.2, 0.3], $documents[0]->vector->getData());
    }
}
