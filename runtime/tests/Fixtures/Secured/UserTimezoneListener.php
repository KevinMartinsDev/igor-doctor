<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\Secured;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[AsEventListener]
final class UserTimezoneListener
{
    private const TIMEZONES = ['alice' => 'Europe/Paris', 'bob' => 'Asia/Tokyo'];

    public ?string $lastUser = null;

    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (null !== $user) {
            $this->lastUser = $user->getUserIdentifier();
            date_default_timezone_set(self::TIMEZONES[$this->lastUser]);
        }
    }
}
