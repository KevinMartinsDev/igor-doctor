<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Controller;

use IgorDoctor\Runtime\Tests\Fixtures\App\Metadata\MetadataFactory;
use IgorDoctor\Runtime\Tests\Fixtures\App\Metadata\Parameter;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\AccessLog;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\DecisionCache;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\RequestHolder;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\ResettableBuffer;
use IgorDoctor\Runtime\Tests\Fixtures\App\Service\RouteTable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class FixtureController
{
    public function __construct(
        private readonly MetadataFactory $metadata,
        private readonly RouteTable $routes,
        private readonly AccessLog $log,
        private readonly RequestHolder $holder,
        private readonly ResettableBuffer $buffer,
        private readonly DecisionCache $decisions,
    ) {
    }

    #[Route('/books', name: 'books')]
    public function books(Request $request): Response
    {
        $operation = $this->metadata->create('Book')['get_collection'];
        foreach ($operation->getParameters() as $key => $parameter) {
            $parameter->setValue($request->query->get($key));
        }

        return new JsonResponse(array_map(static fn (Parameter $parameter): mixed => $parameter->getValue(), $operation->getParameters()));
    }

    /**
     * As GHSA-xmq6-3c6q-fr8m: a parameter is written only when the request sends it, so a request without it
     * reads the value the previous visitor sent.
     */
    #[Route('/catalog', name: 'catalog')]
    public function catalog(Request $request): Response
    {
        $operation = $this->metadata->create('Book')['get_collection'];
        foreach ($operation->getParameters() as $key => $parameter) {
            if ($request->query->has($key)) {
                $parameter->setValue($request->query->get($key));
            }
        }

        return new JsonResponse(array_map(static fn (Parameter $parameter): mixed => $parameter->getValue(), $operation->getParameters()));
    }

    #[Route('/books-fixed', name: 'books_fixed')]
    public function booksFixed(Request $request): Response
    {
        $operation = $this->metadata->create('Book')['get_collection'];
        $parameters = [];
        foreach ($operation->getParameters() as $key => $parameter) {
            $parameters[$key] = $parameter->withValue($request->query->get($key));
        }
        $operation = $operation->withParameters($parameters);

        return new JsonResponse(array_map(static fn (Parameter $parameter): mixed => $parameter->getValue(), $operation->getParameters()));
    }

    #[Route('/routes', name: 'routes')]
    public function routes(): Response
    {
        return new JsonResponse($this->routes->table());
    }

    #[Route('/log', name: 'log')]
    public function log(Request $request): Response
    {
        $this->log->add($request->getMethod());

        return new Response('logged');
    }

    #[Route('/hold', name: 'hold')]
    public function hold(Request $request): Response
    {
        $this->holder->remember($request);

        return new Response('held');
    }

    #[Route('/buffer', name: 'buffer')]
    public function buffer(Request $request): Response
    {
        $this->buffer->add((string) $request->query->get('line'));

        return new Response('buffered');
    }

    #[Route('/{_locale}/posts/{slug}', name: 'post', requirements: ['_locale' => 'en|fr'])]
    public function post(string $slug): Response
    {
        return new Response($slug);
    }

    #[Route('/{_locale}/about', name: 'about', requirements: ['_locale' => 'ar|en|fr'], defaults: ['_locale' => 'en'])]
    public function about(): Response
    {
        return new Response('about');
    }

    #[Route('/items/{id}', name: 'item', requirements: ['id' => '\d+'])]
    public function item(int $id): Response
    {
        return new Response((string) $id);
    }

    #[Route('/links', name: 'links')]
    public function links(): Response
    {
        return new Response('<html><body><a href="/items/7">7</a> <a href="/items/8">8</a> <a href="https://example.com/x">out</a></body></html>', headers: ['Content-Type' => 'text/html']);
    }

    #[Route('/timezone', name: 'timezone')]
    public function timezone(): Response
    {
        date_default_timezone_set('America/New_York');

        return new Response('changed');
    }

    #[Route('/exit', name: 'exit')]
    public function exit(): Response
    {
        exit(0);
    }

    #[Route('/secret', name: 'secret')]
    public function secret(Request $request): Response
    {
        if (!$this->decisions->canRead('report', (string) $request->headers->get('X-User'))) {
            return new Response('denied', Response::HTTP_FORBIDDEN);
        }

        return new Response('the secret report');
    }
}
