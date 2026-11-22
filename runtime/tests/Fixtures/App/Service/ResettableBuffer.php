<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Service;

use Symfony\Contracts\Service\ResetInterface;

final class ResettableBuffer implements ResetInterface
{
    /** @var list<string> */
    private array $lines = [];

    public function add(string $line): void
    {
        $this->lines[] = $line;
    }

    public function reset(): void
    {
        $this->lines = [];
    }
}
