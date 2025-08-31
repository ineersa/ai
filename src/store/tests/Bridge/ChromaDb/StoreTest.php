<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Bridge\ChromaDb;

use Codewithkyrian\ChromaDB\Client;
use Codewithkyrian\ChromaDB\Generated\Responses\QueryItemsResponse;
use Codewithkyrian\ChromaDB\Resources\CollectionResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Bridge\ChromaDb\Store;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Store::class)]
#[UsesClass(VectorDocument::class)]
#[UsesClass(Vector::class)]
final class StoreTest extends TestCase
{
    /**
     * @param array<VectorDocument>       $documents
     * @param array<string>               $expectedIds
     * @param array<array<float>>         $expectedVectors
     * @param array<array<string, mixed>> $expectedMetadata
     * @param array<string>               $expectedOriginalDocuments
     */
    #[DataProvider('addDocumentsProvider')]
    public function testAddDocumentsSuccessfully(
        array $documents,
        array $expectedIds,
        array $expectedVectors,
        array $expectedMetadata,
        array $expectedOriginalDocuments,
    ): void {
        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('add')
            ->with($expectedIds, $expectedVectors, $expectedMetadata, $expectedOriginalDocuments);

        $store = new Store($client, 'test-collection');

        $store->add(...$documents);
    }

    /**
     * @return \Iterator<string, array{
     *     documents: array<VectorDocument>,
     *     expectedIds: array<string>,
     *     expectedVectors: array<array<float>>,
     *     expectedMetadata: array<array<string, mixed>>,
     *     expectedOriginalDocuments: array<string>
     * }>
     */
    public static function addDocumentsProvider(): \Iterator
    {
        yield 'multiple documents with and without metadata' => [
            'documents' => [
                new VectorDocument(
                    Uuid::fromString('01234567-89ab-cdef-0123-456789abcdef'),
                    new Vector([0.1, 0.2, 0.3]),
                ),
                new VectorDocument(
                    Uuid::fromString('fedcba98-7654-3210-fedc-ba9876543210'),
                    new Vector([0.4, 0.5, 0.6]),
                    new Metadata(['title' => 'Test Document']),
                ),
            ],
            'expectedIds' => ['01234567-89ab-cdef-0123-456789abcdef', 'fedcba98-7654-3210-fedc-ba9876543210'],
            'expectedVectors' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
            'expectedMetadata' => [[], ['title' => 'Test Document']],
            'expectedOriginalDocuments' => ['', ''],
        ];

        yield 'single document with metadata' => [
            'documents' => [
                new VectorDocument(
                    Uuid::fromString('01234567-89ab-cdef-0123-456789abcdef'),
                    new Vector([0.1, 0.2, 0.3]),
                    new Metadata(['title' => 'Test Document', 'category' => 'test']),
                ),
            ],
            'expectedIds' => ['01234567-89ab-cdef-0123-456789abcdef'],
            'expectedVectors' => [[0.1, 0.2, 0.3]],
            'expectedMetadata' => [['title' => 'Test Document', 'category' => 'test']],
            'expectedOriginalDocuments' => [''],
        ];

        yield 'documents with text content' => [
            'documents' => [
                new VectorDocument(
                    Uuid::fromString('01234567-89ab-cdef-0123-456789abcdef'),
                    new Vector([0.1, 0.2, 0.3]),
                    new Metadata(['_text' => 'This is the content of document 1', 'title' => 'Document 1'])
                ),
                new VectorDocument(
                    Uuid::fromString('fedcba98-7654-3210-fedc-ba9876543210'),
                    new Vector([0.4, 0.5, 0.6]),
                    new Metadata(['_text' => 'This is the content of document 2', 'title' => 'Document 2', 'category' => 'test']),
                ),
            ],
            'expectedIds' => ['01234567-89ab-cdef-0123-456789abcdef', 'fedcba98-7654-3210-fedc-ba9876543210'],
            'expectedVectors' => [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
            'expectedMetadata' => [['title' => 'Document 1'], ['title' => 'Document 2', 'category' => 'test']],
            'expectedOriginalDocuments' => ['This is the content of document 1', 'This is the content of document 2'],
        ];

        yield 'document with null text' => [
            'documents' => [
                new VectorDocument(
                    Uuid::fromString('01234567-89ab-cdef-0123-456789abcdef'),
                    new Vector([0.1, 0.2, 0.3]),
                    new Metadata(['_text' => null, 'title' => 'Test Document']),
                ),
            ],
            'expectedIds' => ['01234567-89ab-cdef-0123-456789abcdef'],
            'expectedVectors' => [[0.1, 0.2, 0.3]],
            'expectedMetadata' => [['title' => 'Test Document']],
            'expectedOriginalDocuments' => [''],
        ];
    }

    public function testQueryWithoutFilters()
    {
        $queryVector = new Vector([0.15, 0.25, 0.35]);
        $queryResponse = new QueryItemsResponse(
            ids: [['01234567-89ab-cdef-0123-456789abcdef', 'fedcba98-7654-3210-fedc-ba9876543210']],
            embeddings: [[[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]],
            metadatas: [[['title' => 'Doc 1'], ['title' => 'Doc 2']]],
            documents: null,
            data: null,
            uris: null,
            distances: null
        );

        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('query')
            ->with(
                [[0.15, 0.25, 0.35]],  // queryEmbeddings
                null,                  // queryTexts
                null,                  // queryImages
                4,                     // nResults
                null,                  // where
                null,                  // whereDocument
                null                   // include
            )
            ->willReturn($queryResponse);

        $store = new Store($client, 'test-collection');
        $documents = $store->query($queryVector);

        $this->assertCount(2, $documents);
        $this->assertSame('01234567-89ab-cdef-0123-456789abcdef', (string) $documents[0]->id);
        $this->assertSame([0.1, 0.2, 0.3], $documents[0]->vector->getData());
        $this->assertSame(['title' => 'Doc 1'], $documents[0]->metadata->getArrayCopy());
    }

    public function testQueryWithWhereFilter()
    {
        $queryVector = new Vector([0.15, 0.25, 0.35]);
        $whereFilter = ['category' => 'technology'];

        $queryResponse = new QueryItemsResponse(
            ids: [['01234567-89ab-cdef-0123-456789abcdef']],
            embeddings: [[[0.1, 0.2, 0.3]]],
            metadatas: [[['title' => 'Tech Doc', 'category' => 'technology']]],
            documents: null,
            data: null,
            uris: null,
            distances: null
        );

        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('query')
            ->with(
                [[0.15, 0.25, 0.35]],  // queryEmbeddings
                null,                  // queryTexts
                null,                  // queryImages
                4,                     // nResults
                ['category' => 'technology'],  // where
                null,                  // whereDocument
                null                   // include
            )
            ->willReturn($queryResponse);

        $store = new Store($client, 'test-collection');
        $documents = $store->query($queryVector, ['where' => $whereFilter]);

        $this->assertCount(1, $documents);
        $this->assertSame('01234567-89ab-cdef-0123-456789abcdef', (string) $documents[0]->id);
        $this->assertSame(['title' => 'Tech Doc', 'category' => 'technology'], $documents[0]->metadata->getArrayCopy());
    }

    public function testQueryWithWhereDocumentFilter()
    {
        $queryVector = new Vector([0.15, 0.25, 0.35]);
        $whereDocumentFilter = ['$contains' => 'machine learning'];

        $queryResponse = new QueryItemsResponse(
            ids: [['01234567-89ab-cdef-0123-456789abcdef', 'fedcba98-7654-3210-fedc-ba9876543210']],
            embeddings: [[[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]]],
            metadatas: [[['title' => 'ML Doc 1'], ['title' => 'ML Doc 2']]],
            documents: null,
            data: null,
            uris: null,
            distances: null
        );

        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('query')
            ->with(
                [[0.15, 0.25, 0.35]],  // queryEmbeddings
                null,                  // queryTexts
                null,                  // queryImages
                4,                     // nResults
                null,                  // where
                ['$contains' => 'machine learning'],  // whereDocument
                null                   // include
            )
            ->willReturn($queryResponse);

        $store = new Store($client, 'test-collection');
        $documents = $store->query($queryVector, ['whereDocument' => $whereDocumentFilter]);

        $this->assertCount(2, $documents);
        $this->assertSame('01234567-89ab-cdef-0123-456789abcdef', (string) $documents[0]->id);
        $this->assertSame('fedcba98-7654-3210-fedc-ba9876543210', (string) $documents[1]->id);
    }

    public function testQueryWithBothFilters()
    {
        $queryVector = new Vector([0.15, 0.25, 0.35]);
        $whereFilter = ['category' => 'AI', 'status' => 'published'];
        $whereDocumentFilter = ['$contains' => 'neural networks'];

        $queryResponse = new QueryItemsResponse(
            ids: [['01234567-89ab-cdef-0123-456789abcdef']],
            embeddings: [[[0.1, 0.2, 0.3]]],
            metadatas: [[['title' => 'AI Neural Networks', 'category' => 'AI', 'status' => 'published']]],
            documents: null,
            data: null,
            uris: null,
            distances: null
        );

        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('query')
            ->with(
                [[0.15, 0.25, 0.35]],  // queryEmbeddings
                null,                  // queryTexts
                null,                  // queryImages
                4,                     // nResults
                ['category' => 'AI', 'status' => 'published'],  // where
                ['$contains' => 'neural networks'],  // whereDocument
                null                   // include
            )
            ->willReturn($queryResponse);

        $store = new Store($client, 'test-collection');
        $documents = $store->query($queryVector, [
            'where' => $whereFilter,
            'whereDocument' => $whereDocumentFilter,
        ]);

        $this->assertCount(1, $documents);
        $this->assertSame('01234567-89ab-cdef-0123-456789abcdef', (string) $documents[0]->id);
        $this->assertSame(['title' => 'AI Neural Networks', 'category' => 'AI', 'status' => 'published'], $documents[0]->metadata->getArrayCopy());
    }

    public function testQueryWithEmptyResults()
    {
        $queryVector = new Vector([0.15, 0.25, 0.35]);
        $whereFilter = ['category' => 'nonexistent'];

        $queryResponse = new QueryItemsResponse(
            ids: [[]],
            embeddings: [[]],
            metadatas: [[]],
            documents: null,
            data: null,
            uris: null,
            distances: null
        );

        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('query')
            ->with(
                [[0.15, 0.25, 0.35]],  // queryEmbeddings
                null,                  // queryTexts
                null,                  // queryImages
                4,                     // nResults
                ['category' => 'nonexistent'],  // where
                null,                  // whereDocument
                null                   // include
            )
            ->willReturn($queryResponse);

        $store = new Store($client, 'test-collection');
        $documents = $store->query($queryVector, ['where' => $whereFilter]);

        $this->assertCount(0, $documents);
    }

    /**
     * @param array{where?: array<string, string>, whereDocument?: array<string, mixed>} $options
     * @param array<string, mixed>|null                                                  $expectedWhere
     * @param array<string, mixed>|null                                                  $expectedWhereDocument
     */
    #[DataProvider('queryFilterProvider')]
    public function testQueryWithVariousFilterCombinations(
        array $options,
        ?array $expectedWhere,
        ?array $expectedWhereDocument,
    ): void {
        $queryVector = new Vector([0.1, 0.2, 0.3]);

        $queryResponse = new QueryItemsResponse(
            ids: [['01234567-89ab-cdef-0123-456789abcdef']],
            embeddings: [[[0.1, 0.2, 0.3]]],
            metadatas: [[['title' => 'Test Doc']]],
            documents: null,
            data: null,
            uris: null,
            distances: null
        );

        $collection = $this->createMock(CollectionResource::class);
        $client = $this->createMock(Client::class);

        $client->expects($this->once())
            ->method('getOrCreateCollection')
            ->with('test-collection')
            ->willReturn($collection);

        $collection->expects($this->once())
            ->method('query')
            ->with(
                [[0.1, 0.2, 0.3]],     // queryEmbeddings
                null,                  // queryTexts
                null,                  // queryImages
                4,                     // nResults
                $expectedWhere,        // where
                $expectedWhereDocument,// whereDocument
                null                   // include
            )
            ->willReturn($queryResponse);

        $store = new Store($client, 'test-collection');
        $documents = $store->query($queryVector, $options);

        $this->assertCount(1, $documents);
    }

    /**
     * @return \Iterator<string, array{
     *     options: array{where?: array<string, string>, whereDocument?: array<string, mixed>},
     *     expectedWhere: array<string, mixed>|null,
     *     expectedWhereDocument: array<string, mixed>|null
     * }>
     */
    public static function queryFilterProvider(): \Iterator
    {
        yield 'empty options' => [
            'options' => [],
            'expectedWhere' => null,
            'expectedWhereDocument' => null,
        ];

        yield 'only where filter' => [
            'options' => ['where' => ['type' => 'article']],
            'expectedWhere' => ['type' => 'article'],
            'expectedWhereDocument' => null,
        ];

        yield 'only whereDocument filter' => [
            'options' => ['whereDocument' => ['$contains' => 'search term']],
            'expectedWhere' => null,
            'expectedWhereDocument' => ['$contains' => 'search term'],
        ];

        yield 'both filters' => [
            'options' => [
                'where' => ['status' => 'active'],
                'whereDocument' => ['$not_contains' => 'draft'],
            ],
            'expectedWhere' => ['status' => 'active'],
            'expectedWhereDocument' => ['$not_contains' => 'draft'],
        ];

        yield 'complex where filter' => [
            'options' => [
                'where' => [
                    'category' => 'technology',
                    'author' => 'john.doe',
                    'status' => 'published',
                ],
            ],
            'expectedWhere' => [
                'category' => 'technology',
                'author' => 'john.doe',
                'status' => 'published',
            ],
            'expectedWhereDocument' => null,
        ];

        yield 'complex whereDocument filter' => [
            'options' => [
                'whereDocument' => [
                    '$and' => [
                        ['$contains' => 'AI'],
                        ['$contains' => 'machine learning'],
                    ],
                ],
            ],
            'expectedWhere' => null,
            'expectedWhereDocument' => [
                '$and' => [
                    ['$contains' => 'AI'],
                    ['$contains' => 'machine learning'],
                ],
            ],
        ];
    }
}
