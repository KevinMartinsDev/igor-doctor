<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds real URLs of the routes in the application's own pages: the links of each HTML answer are
 * matched against the router, for two rounds. A route with two URLs alternates them (a page that
 * remembers what was viewed only changes when two different pages are viewed); a route whose
 * generated probe failed (a made-up slug answers 404) takes the URL found.
 *
 * With $forms, the forms of these pages become probes too, one per action and method: a GET form adds
 * the query parameters it sends, a POST form is posted after its page is requested again (CSRF token).
 */
final class LinkDiscovery
{
    private const MARKER = 'igor0discovery';

    private const ROUNDS = 2;

    /**
     * @param (\Closure(Probe): void)|null $sending told about each probe before it is sent, so that a
     *                                          parent process knows which one ended the process
     * @param string|null                  $user    the user the pages are visited as, to find the links behind a login
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ?\Closure $sending = null,
        private readonly ?string $user = null,
        private readonly bool $forms = false,
        private readonly ScanEvents $events = new ScanEvents(),
    ) {
    }

    /**
     * @param list<Probe> $probes
     *
     * @return list<Probe>
     */
    public function discover(array $probes): array
    {
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        if (!$router instanceof RouterInterface) {
            return $probes;
        }

        $links = [];
        $forms = [];
        $failed = [];
        $sent = [];
        $queue = $probes;
        for ($round = 0; $round < self::ROUNDS && [] !== $queue; ++$round) {
            $next = [];
            foreach ($queue as $probe) {
                $key = $probe->uri;
                if (isset($sent[$key])) {
                    continue;
                }
                $sent[$key] = true;

                [$status, $body, $type, $page] = $this->send($probe);
                if ($status >= 300 && 0 === $round) {
                    $failed[$probe->name] = true;
                }
                if ($status >= 300 || !str_contains($type, 'html')) {
                    $this->events->emit('page', ['route' => $probe->name, 'uri' => $page, 'status' => $status, 'links' => 0, 'forms' => 0]);
                    continue;
                }
                $found = \count($forms);
                if ($this->forms) {
                    foreach (FormExtractor::forms($body, $page) as $form) {
                        $route = $this->route($router, $form['action'], $form['method']);
                        $key = $form['method'].' '.$route.' '.preg_replace('/=[^&]*/', '', $form['body']);
                        if (null === $route || isset($forms[$key])) {
                            continue;
                        }
                        $this->events->emit('form', ['route' => $route, 'method' => $form['method'], 'page' => $page, 'fields' => substr_count($form['body'], '=')]);
                        $forms[$key] = 'GET' === $form['method']
                            ? new Probe('form '.$route.' (GET)', $form['action'].(str_contains($form['action'], '?') ? '&' : '?').$form['body'], 'GET', $probe->headers, null, $probe->cookies)
                            : new Probe('form '.$route.' (POST)', $form['action'], 'POST', [...$probe->headers, 'Content-Type' => Probe::FORM_TYPE], $form['body'], $probe->cookies, $page);
                    }
                }
                $this->events->emit('page', ['route' => $probe->name, 'uri' => $page, 'status' => $status, 'links' => \count($this->links($body)), 'forms' => \count($forms) - $found]);
                foreach ($this->links($body) as $path) {
                    $route = $this->route($router, $path);
                    if (null === $route || \count($links[$route] ?? []) >= 2 || \in_array($path, $links[$route] ?? [], true)) {
                        continue;
                    }
                    $links[$route][] = $path;
                    $next[] = new Probe($route, $path, 'GET', $probe->headers, null, $probe->cookies);
                }
            }
            $queue = $next;
        }
        $this->kernel->shutdown();

        foreach ($probes as $index => $probe) {
            $found = $links[$probe->name] ?? [];
            if (2 === \count($found) && !str_contains($found[0].$found[1], '|') && !str_contains($found[0].$found[1], '{')) {
                $probes[$index] = $this->withPath($probe, '{'.$found[0].'|'.$found[1].'|'.$found[0].'}');
            } elseif (1 === \count($found) && isset($failed[$probe->name])) {
                $probes[$index] = $this->withPath($probe, $found[0]);
            }
        }

        return [...$probes, ...array_values($forms)];
    }

    private function withPath(Probe $probe, string $path): Probe
    {
        return new Probe($probe->name, $path.(str_contains($path, '?') ? '&' : '?').'igor='.Probe::MARKER, $probe->method, $probe->headers, $probe->body, $probe->cookies, $probe->formPage);
    }

    /**
     * @return array{int, string, string, string} status, body, content type, and the URI that was requested
     */
    private function send(Probe $probe): array
    {
        if (null !== $this->sending) {
            ($this->sending)($probe);
        }
        $request = $probe->request(self::MARKER);
        try {
            WorkerStateAuditor::logIn($this->kernel, $request, $this->user);
            $response = $this->kernel->handle($request);
        } catch (\Throwable) {
            return [500, '', '', ''];
        }
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
        $container = $this->kernel->getContainer();
        if ($container->has('services_resetter')) {
            $container->get('services_resetter')->reset();
        }

        return [$response->getStatusCode(), (string) $response->getContent(), (string) $response->headers->get('Content-Type'), $request->getRequestUri()];
    }

    /**
     * @return list<string> same-site paths, with their query string, without the fragment
     */
    private function links(string $html): array
    {
        preg_match_all('/\shref="(\/(?!\/)[^"#]*)/i', $html, $matches);

        return array_values(array_unique(array_map(static fn (string $href): string => html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5), $matches[1])));
    }

    private function route(RouterInterface $router, string $path, string $method = 'GET'): ?string
    {
        $context = $router->getContext();
        $previous = $context->getMethod();
        $context->setMethod($method);
        try {
            $route = $router->match((string) parse_url($path, \PHP_URL_PATH))['_route'] ?? null;
        } catch (\Throwable) {
            return null;
        } finally {
            $context->setMethod($previous);
        }

        return \is_string($route) ? $route : null;
    }
}
