<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Metadata;

/**
 * Builds the metadata of a resource once and keeps it for the life of the worker, like a cached
 * metadata factory: the cache is legitimate, writing request values into it is not.
 */
final class MetadataFactory
{
    /** @var array<string, \ArrayObject<string, Operation>> */
    private array $localCache = [];

    /**
     * @return \ArrayObject<string, Operation>
     */
    public function create(string $resource): \ArrayObject
    {
        return $this->localCache[$resource] ??= new \ArrayObject([
            'get_collection' => new Operation('get_collection', ['title' => new Parameter('title'), 'archived' => new Parameter('archived')]),
        ]);
    }
}
