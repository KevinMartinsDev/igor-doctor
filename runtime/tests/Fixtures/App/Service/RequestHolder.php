<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Service;

use Symfony\Component\HttpFoundation\Request;

final class RequestHolder
{
    private ?Request $last = null;

    public function remember(Request $request): void
    {
        $this->last = $request;
    }
}
