<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\Identities;
use PHPUnit\Framework\TestCase;

final class IdentitiesTest extends TestCase
{
    public function testTwoUsersInTurnThenAnAnonymousVisitor(): void
    {
        $identities = new Identities(['alice', 'bob', 'carol']);

        self::assertSame(4, $identities->sendings());
        self::assertSame(['alice', 'bob', 'alice', null], [$identities->at(1), $identities->at(2), $identities->at(3), $identities->at(4)]);
    }

    public function testOneUserAlternatesWithAnAnonymousVisitor(): void
    {
        $identities = new Identities(['alice']);

        self::assertSame(['alice', null, 'alice'], [$identities->at(1), $identities->at(2), $identities->at(3)]);
    }

    public function testWithoutUsersEveryRequestIsAnonymous(): void
    {
        self::assertSame(3, (new Identities())->sendings());
        self::assertNull((new Identities())->at(1));
    }
}
