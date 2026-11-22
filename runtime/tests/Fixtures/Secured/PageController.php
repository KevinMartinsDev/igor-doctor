<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\Secured;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class PageController
{
    public function __invoke(): Response
    {
        return new Response('<p>'.date_default_timezone_get().'</p>');
    }
}
