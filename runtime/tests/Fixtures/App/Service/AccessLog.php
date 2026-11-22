<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Service;

final class AccessLog
{
    /** @var list<string> */
    private array $entries = [];

    public function add(string $method): void
    {
        $this->entries[] = $method;
    }
}
