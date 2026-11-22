<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\Probe;
use PHPUnit\Framework\TestCase;

final class ProbeTest extends TestCase
{
    public function testItFillsTheMarkerAndPicksOneAlternativePerSending(): void
    {
        $probe = new Probe('books', '/books?title={marker}&archived={false|true}', 'POST', ['X-User' => '{alice|bob|carol}'], '{"note":"{marker}"}');

        $second = $probe->request('m2', 2);

        self::assertSame('m2', $second->query->get('title'));
        self::assertSame('true', $second->query->get('archived'));
        self::assertSame('bob', $second->headers->get('X-User'));
        self::assertSame('{"note":"m2"}', $second->getContent());
        self::assertSame('POST', $second->getMethod());
        self::assertSame('false', $probe->request('m3', 3)->query->get('archived'));
        self::assertSame(['true', 'bob'], $probe->choices(2));
    }

    public function testAMarkerIsUrlEncodedInTheUri(): void
    {
        self::assertSame('a b&c', (new Probe('q', '/q?v={marker}'))->request('a b&c')->query->get('v'));
    }
}
