<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Service;

final class RouteTable
{
    /** @var array<string, string> */
    private array $table = [];

    /**
     * @return array<string, string>
     */
    public function table(): array
    {
        if ([] === $this->table) {
            $this->table = ['books' => '/books', 'authors' => '/authors'];
        }

        return $this->table;
    }
}
