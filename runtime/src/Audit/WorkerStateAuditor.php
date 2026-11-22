<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use IgorDoctor\Runtime\Snapshot\ContainerStateExtractor;
use IgorDoctor\Runtime\Snapshot\Snapshot;
use IgorDoctor\Runtime\Snapshot\StateSnapshotter;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Plays a worker: one booted kernel handles every request, as FrankenPHP's worker mode does.
 *
 * Each probe is sent three times with a different marker (four with two users). After each sending the kernel is
 * terminated, `services_resetter` runs and the garbage is collected, which is what the next
 * request of a real worker would find, then the shared state is snapshotted.
 *
 * With users, the sendings are logged in as the identities say (A, B, then anonymous). With $rollBack,
 * every sending runs in a transaction of each Doctrine connection, rolled back once the request is over:
 * a submitted form changes nothing in the database, and the next sending finds the same data.
 */
final class WorkerStateAuditor
{
    public const SENDINGS = 3;

    private readonly \Closure $markers;

    /**
     * @param (\Closure(int): string)|null $markers the marker of each sending, random by default
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly StateSnapshotter $snapshotter,
        private readonly ContainerStateExtractor $extractor,
        ?\Closure $markers = null,
        private readonly Identities $identities = new Identities(),
        private readonly bool $rollBack = false,
        private readonly ScanEvents $events = new ScanEvents(),
    ) {
        $this->markers = $markers ?? static fn (int $sending): string => 'igor'.$sending.'x'.bin2hex(random_bytes(5));
    }

    public function run(Probe $probe): ProbeRun
    {
        $this->kernel->boot();

        $markers = [];
        $snapshots = [$this->snapshotter->snapshot(ContainerStateExtractor::process())];
        $statuses = [];
        $zombies = [];
        $answers = [];
        $identities = [];
        $controllers = [];
        $reasons = [];
        $this->events->emit('probe', ['probe' => $probe->name, 'method' => $probe->method, 'uri' => $probe->uri, 'body' => $probe->body, 'form_page' => $probe->formPage, 'sendings' => $this->identities->sendings()]);

        for ($sending = 1; $sending <= $this->identities->sendings(); ++$sending) {
            $markers[$sending] = ($this->markers)($sending);
            $transaction = $this->rollBack ? self::begin($this->kernel) : [];
            $started = hrtime(true);
            [$request, $response, $identities[$sending], $controller] = self::exchange($this->kernel, $probe, $markers[$sending], $sending, $this->identities->at($sending));
            $statuses[$sending] = $response->getStatusCode();
            $controllers[$sending] = $controller;
            $reasons[$sending] = self::reason($response);
            $this->request($probe->name, $sending, $request, $response, $started, $this->identities->at($sending), [] !== $transaction, $controller);
            $answers[$sending] = (string) $response->getContent();
            if ($this->kernel instanceof TerminableInterface) {
                $this->kernel->terminate($request, $response);
            }

            $this->resetServices();
            self::rollBackAll($transaction);
            $request = \WeakReference::create($request);
            unset($response);
            gc_collect_cycles();

            if (null !== $request->get()) {
                $zombies[] = $sending;
            }
            unset($request);

            $snapshots[$sending] = $this->snapshot();
            $this->events->emit('snapshot', ['probe' => $probe->name, 'n' => $sending, 'nodes' => \count($snapshots[$sending]->nodes), 'memory_kb' => intdiv(memory_get_usage(), 1024)]);
        }

        return new ProbeRun($probe, $markers, $snapshots, $statuses, $zombies, $answers, $identities, $controllers, $reasons);
    }

    /**
     * The answer a worker that has served nothing yet gives to the given sending of the probe.
     */
    public static function freshAnswer(KernelInterface $kernel, Probe $probe, string $marker, int $sending, ?string $user = null, bool $rollBack = false): string
    {
        $kernel->boot();
        $transaction = $rollBack ? self::begin($kernel) : [];
        [$request, $response] = self::exchange($kernel, $probe, $marker, $sending, $user);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        self::rollBackAll($transaction);

        return (string) $response->getContent();
    }

    public const NEUTRAL_MARKER = 'igor0neutral';

    /**
     * The status and the body the kernel answers to a request, in its current state: right after a run, on the
     * worker the probe used, or on a fresh kernel.
     *
     * @return array{int, string}
     */
    public static function answer(KernelInterface $kernel, Probe $probe, ?string $user = null, bool $rollBack = false): array
    {
        $kernel->boot();
        $transaction = $rollBack ? self::begin($kernel) : [];
        [$request, $response] = self::exchange($kernel, $probe, self::NEUTRAL_MARKER, 1, $user);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        self::resetContainer($kernel);
        self::rollBackAll($transaction);

        return [$response->getStatusCode(), (string) $response->getContent()];
    }

    /**
     * Sends one sending of a probe, logged in as the user. A probe built from a form first requests the
     * form's page in the session the sending uses, and posts the hidden fields that page renders.
     *
     * @return array{Request, Response, ?string, ?string} the request, its answer, the user it was logged in as, and
     *                                                the controller it reached (null when none was called)
     */
    public static function exchange(KernelInterface $kernel, Probe $probe, string $marker, int $sending, ?string $user): array
    {
        if (null === $probe->formPage) {
            $request = $probe->request($marker, $sending);
            $loggedIn = self::logIn($kernel, $request, $user);
            $response = self::handle($kernel, $request, $controller);

            return [$request, $response, $loggedIn, $controller];
        }

        $page = Request::create($probe->formPage);
        $loggedIn = self::logIn($kernel, $page, $user);
        $answer = $kernel->handle($page);
        $hidden = FormExtractor::hiddenFields((string) $answer->getContent(), $probe->uri, $probe->formPage);
        $cookies = $page->cookies->all();
        foreach ($answer->headers->getCookies() as $cookie) {
            if (!$cookie->isCleared()) {
                $cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($page, $answer);
        }
        self::resetContainer($kernel);
        unset($page, $answer);

        $request = $probe->request($marker, $sending, $hidden);
        foreach ($cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }
        $response = self::handle($kernel, $request, $controller);

        return [$request, $response, $loggedIn, $controller];
    }

    /**
     * Handles the request and tells which controller it reached: the last listener of kernel.controller_arguments
     * runs once the security attributes (#[IsGranted]) and the argument resolvers have passed, just before the
     * controller is called. A request answered before (a redirect to the login page, a 404 of the router or of
     * an entity resolver, an access denied) did not test the route.
     */
    private static function handle(KernelInterface $kernel, Request $request, ?string &$controller): Response
    {
        $controller = null;
        $container = $kernel->getContainer();
        $dispatcher = $container->has('event_dispatcher') ? $container->get('event_dispatcher') : null;
        $listener = static function (ControllerArgumentsEvent $event) use ($request, &$controller): void {
            if ($event->getRequest() === $request) {
                $controller = self::describe($event->getController());
            }
        };
        $dispatcher?->addListener(KernelEvents::CONTROLLER_ARGUMENTS, $listener, -4096);
        try {
            return $kernel->handle($request);
        } finally {
            $dispatcher?->removeListener(KernelEvents::CONTROLLER_ARGUMENTS, $listener);
        }
    }

    private static function describe(callable $controller): string
    {
        return match (true) {
            \is_array($controller) => (\is_object($controller[0]) ? $controller[0]::class : (string) $controller[0]).'::'.$controller[1],
            \is_string($controller) => $controller,
            $controller instanceof \Closure => 'closure',
            default => $controller::class.'::__invoke',
        };
    }

    /**
     * Why a request did not succeed, in a few words: where it was redirected, the first validation error of a
     * form, or the detail of a JSON error. An HTML error page says nothing reliable beyond its status.
     */
    public static function reason(Response $response): ?string
    {
        if ($response->isRedirection()) {
            return 'redirected to '.((string) parse_url((string) $response->headers->get('Location'), \PHP_URL_PATH));
        }
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $content = (string) preg_replace('/<(script|style)\b.*?<\/\1>/is', '', substr((string) $response->getContent(), 0, 300_000));
        foreach (['/class="[^"]*(?:invalid-feedback|form-error)[^"]*"[^>]*>(.*?)<\/(?:div|span|li|ul)>/is', '/"(?:detail|message|title)"\s*:\s*"([^"]+)"/'] as $pattern) {
            if (1 === preg_match($pattern, $content, $match)) {
                $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[1]), \ENT_QUOTES | \ENT_HTML5)));
                if ('' !== $text) {
                    return mb_substr($text, 0, 160);
                }
            }
        }

        return null;
    }

    /**
     * @return list<object> the Doctrine connections a transaction was begun on
     */
    public static function begin(KernelInterface $kernel): array
    {
        $container = $kernel->getContainer();
        if (!$container->has('doctrine')) {
            return [];
        }

        $connections = [];
        foreach ($container->get('doctrine')->getConnections() as $connection) {
            try {
                $connection->beginTransaction();
                $connections[] = $connection;
            } catch (\Throwable) {
            }
        }

        return $connections;
    }

    /**
     * @param list<object> $connections
     */
    public static function rollBackAll(array $connections): void
    {
        foreach ($connections as $connection) {
            try {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Sends every probe in turn to the same kernel, three rounds, and snapshots after each round:
     * a value one route leaves behind is found even when another route reads it. The statuses are,
     * for each round, the number of probes answered without an error.
     *
     * @param list<Probe> $probes
     */
    public function runTour(array $probes): ProbeRun
    {
        $this->kernel->boot();

        $markers = [];
        $snapshots = [$this->snapshotter->snapshot(ContainerStateExtractor::process())];
        $statuses = [];
        $zombies = [];
        $identities = [];

        for ($sending = 1; $sending <= $this->identities->sendings(); ++$sending) {
            $markers[$sending] = ($this->markers)($sending);
            $statuses[$sending] = 0;
            $identities[$sending] = $this->identities->at($sending);
            $references = [];
            foreach ($probes as $probe) {
                $transaction = $this->rollBack ? self::begin($this->kernel) : [];
                $started = hrtime(true);
                [$request, $response, , $controller] = self::exchange($this->kernel, $probe, $markers[$sending], $sending, $identities[$sending]);
                $this->request($probe->name, $sending, $request, $response, $started, $identities[$sending], [] !== $transaction, $controller, true);
                if ($response->getStatusCode() < 400) {
                    ++$statuses[$sending];
                }
                if ($this->kernel instanceof TerminableInterface) {
                    $this->kernel->terminate($request, $response);
                }
                $this->resetServices();
                self::rollBackAll($transaction);
                $references[] = \WeakReference::create($request);
                unset($request, $response);
            }
            gc_collect_cycles();

            foreach ($references as $reference) {
                if (null !== $reference->get()) {
                    $zombies[] = $sending;
                    break;
                }
            }
            unset($references);

            $snapshots[$sending] = $this->snapshot();
            $this->events->emit('snapshot', ['probe' => 'tour', 'n' => $sending, 'nodes' => \count($snapshots[$sending]->nodes), 'memory_kb' => intdiv(memory_get_usage(), 1024)]);
        }

        $tour = new Probe('tour of '.\count($probes).' routes', implode(' ', array_map(static fn (Probe $probe): string => $probe->uri, $probes)), 'GET', array_merge(...array_map(static fn (Probe $probe): array => $probe->headers, $probes)));

        return new ProbeRun($tour, $markers, $snapshots, $statuses, array_values(array_unique($zombies)), [], $identities);
    }

    /**
     * @return string|null the user the request is logged in as: null for an anonymous request, and for a
     *                     route whose firewall keeps no session
     */
    public static function logIn(KernelInterface $kernel, Request $request, ?string $user): ?string
    {
        return null !== $user && (new SessionLogin($kernel))->logIn($request, $user) ? $user : null;
    }

    private function request(string $probe, int $sending, Request $request, Response $response, int $started, ?string $user, bool $rolledBack, ?string $controller, bool $tour = false): void
    {
        if (!$this->events->enabled()) {
            return;
        }
        $this->events->emit('request', [
            'probe' => $probe,
            'n' => $sending,
            'user' => $user,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'status' => $response->getStatusCode(),
            'controller' => $controller,
            'reason' => self::reason($response),
            'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'rolled_back' => $rolledBack,
            'tour' => $tour,
        ]);
    }

    private function resetServices(): void
    {
        self::resetContainer($this->kernel);
    }

    private static function resetContainer(KernelInterface $kernel): void
    {
        $container = $kernel->getContainer();
        if ($container->has('services_resetter')) {
            $container->get('services_resetter')->reset();
        }
    }

    private function snapshot(): Snapshot
    {
        $container = $this->kernel->getContainer();
        if (!$container instanceof Container) {
            throw new \LogicException(\sprintf('The kernel container is a "%s", not a compiled Symfony container.', get_debug_type($container)));
        }

        return $this->snapshotter->snapshot($this->extractor->roots($container));
    }
}
