<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Logs a request in as a user of the application, without its login form: as KernelBrowser::loginUser()
 * does in tests, a token for the user is stored in a new session under the key of the request's firewall,
 * and the request carries that session's cookie. The application then runs its real path: the firewall
 * reads the session, refreshes the user from its provider and runs every listener of a logged-in request.
 *
 * The user is loaded by the provider of the request's firewall: an application with a shop and an admin
 * area logs a customer in on the shop pages only, and visits the admin pages anonymously.
 *
 * It needs SecurityBundle and sessions; the bundle makes the services below readable.
 */
final class SessionLogin
{
    public const FIREWALL_MAP = 'igor_runtime.firewall_map';
    public const USER_PROVIDERS = 'igor_runtime.user_providers';
    public const SESSION_FACTORY = 'igor_runtime.session_factory';
    public const USER_PROVIDER = 'igor_runtime.user_provider.';
    public const PROVIDER_PREFIX = 'security.user.provider.concrete.';

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * @throws \RuntimeException when the application cannot log users in this way, or the user is unknown
     */
    public function user(string $identifier): UserInterface
    {
        $container = $this->kernel->getContainer();
        foreach ([self::FIREWALL_MAP, self::USER_PROVIDERS, self::SESSION_FACTORY] as $id) {
            if (!$container->has($id)) {
                throw new \RuntimeException('Logging in needs SecurityBundle and sessions: the application has no '.substr($id, \strlen('igor_runtime.')).'.');
            }
        }

        try {
            return $container->get(self::USER_PROVIDERS)->loadUserByIdentifier($identifier);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(\sprintf('No user "%s" in the user providers of the application: %s', $identifier, $exception->getMessage()), previous: $exception);
        }
    }

    /**
     * @return bool false when the request's firewall keeps no session (a stateless API, a public area):
     *              the request is then sent as it is
     */
    public function logIn(Request $request, string $identifier): bool
    {
        $user = $this->user($identifier);
        $container = $this->kernel->getContainer();

        $config = $container->get(self::FIREWALL_MAP)->getFirewallConfig($request);
        if (null === $config || !$config->isSecurityEnabled() || $config->isStateless() || null === $config->getContext()) {
            return false;
        }

        $provider = null !== $config->getProvider() ? self::USER_PROVIDER.$config->getProvider() : null;
        if (null !== $provider && $container->has($provider)) {
            try {
                $user = $container->get($provider)->loadUserByIdentifier($identifier);
            } catch (\Throwable) {
                return false;
            }
        }

        $session = $container->get(self::SESSION_FACTORY)->createSession();
        $session->set('_security_'.$config->getContext(), serialize(new UsernamePasswordToken($user, $config->getName(), $user->getRoles())));
        $session->save();
        $request->cookies->set($session->getName(), $session->getId());

        return true;
    }
}
