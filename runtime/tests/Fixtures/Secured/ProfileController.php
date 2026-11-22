<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\Secured;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A form protected by a token kept in the session: it is accepted only when the page was requested in
 * the same session just before. The last nickname saved stays in a shared service.
 */
#[AsController]
final class ProfileController
{
    public function __construct(private readonly NicknameStore $store)
    {
    }

    #[Route('/profile', name: 'profile_edit', methods: ['GET'])]
    public function edit(Request $request): Response
    {
        $token = bin2hex(random_bytes(8));
        $request->getSession()->set('profile_token', $token);

        return new Response(<<<HTML
            <form method="post" action="/profile/save">
                <input type="hidden" name="profile[_token]" value="{$token}">
                <input type="text" name="profile[nickname]" value="">
                <select name="profile[theme]"><option value="dark">Dark</option><option value="light">Light</option></select>
                <button type="submit">Save</button>
            </form>
            HTML);
    }

    #[Route('/profile/save', name: 'profile_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $profile = $request->request->all('profile');
        if (!$request->hasSession() || ($profile['_token'] ?? null) !== $request->getSession()->get('profile_token')) {
            return new Response('Invalid token', 403);
        }

        $this->store->last = $profile['nickname'] ?? null;

        return new Response('Saved');
    }
}
