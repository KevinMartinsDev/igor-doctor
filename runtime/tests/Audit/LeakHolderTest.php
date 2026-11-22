<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\LeakHolder;
use IgorDoctor\Runtime\Audit\ScanEvents;
use IgorDoctor\Runtime\Snapshot\StateNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LeakHolderTest extends TestCase
{
    private const PROJECT = __DIR__.'/../../..';

    public function testTheHolderIsTheFirstPropertyDeclaredInThePackageOfTheValue(): void
    {
        $nodes = [
            '@app.service' => new StateNode(ScanEvents::class),
            '@app.service->$file' => new StateNode(RequestStack::class),
            '@app.service->$file->$requests[0]' => new StateNode(Request::class),
            '@app.service->$file->$requests[0]->$attributes' => new StateNode(ParameterBag::class),
        ];

        $holder = LeakHolder::find('@app.service->$file->$requests[0]->$attributes->$parameters', ParameterBag::class, $nodes, (string) realpath(self::PROJECT));

        self::assertSame(['class' => RequestStack::class, 'property' => 'requests', 'source' => 'vendor/symfony/http-foundation/RequestStack.php'], $holder);
    }

    public function testAServiceIsNamedByTheOwnerOfItsProperty(): void
    {
        $nodes = [
            '@request_stack->$requests' => new StateNode('array', owner: RequestStack::class),
            '@request_stack->$requests[0]' => new StateNode(Request::class, owner: RequestStack::class),
            '@request_stack->$requests[0]->$attributes' => new StateNode(ParameterBag::class, owner: Request::class),
        ];

        $holder = LeakHolder::find('@request_stack->$requests[0]->$attributes->$parameters', ParameterBag::class, $nodes, (string) realpath(self::PROJECT));

        self::assertSame(RequestStack::class, $holder['class'] ?? null);
    }

    public function testAValueOfTheApplicationHasNoHolderToSearchUpstream(): void
    {
        self::assertNull(LeakHolder::find('@app.service->$file', ScanEvents::class, ['@app.service' => new StateNode(ScanEvents::class)], (string) realpath(self::PROJECT)));
    }

    public function testAStaticPropertyIsAHop(): void
    {
        $holder = LeakHolder::find(RequestStack::class.'::$requests[0]->$attributes->$parameters', ParameterBag::class, [], (string) realpath(self::PROJECT));

        self::assertSame(RequestStack::class, $holder['class'] ?? null);
    }
}
