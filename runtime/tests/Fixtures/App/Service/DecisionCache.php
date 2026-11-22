<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Service;

/**
 * Caches an access decision by resource only, although it depends on the user: the first user
 * decides for everyone that the worker serves next.
 */
final class DecisionCache
{
    /** @var array<string, bool> */
    private array $decisions = [];

    public function canRead(string $resource, string $user): bool
    {
        return $this->decisions[$resource] ??= 'admin' === $user;
    }
}
