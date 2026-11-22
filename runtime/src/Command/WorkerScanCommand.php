<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Command;

use IgorDoctor\Runtime\Audit\HarvestedInputs;
use IgorDoctor\Runtime\Audit\Identities;
use IgorDoctor\Runtime\Audit\InputHarvester;
use IgorDoctor\Runtime\Audit\LeakClassifier;
use IgorDoctor\Runtime\Audit\LeakFinding;
use IgorDoctor\Runtime\Audit\LeakHolder;
use IgorDoctor\Runtime\Audit\LeakType;
use IgorDoctor\Runtime\Audit\LinkDiscovery;
use IgorDoctor\Runtime\Audit\ModelCommand;
use IgorDoctor\Runtime\Audit\Probe;
use IgorDoctor\Runtime\Audit\ProbeFactory;
use IgorDoctor\Runtime\Audit\ProbeRun;
use IgorDoctor\Runtime\Audit\ResponseEchoOracle;
use IgorDoctor\Runtime\Audit\RouteUnblocker;
use IgorDoctor\Runtime\Audit\ScanEvents;
use IgorDoctor\Runtime\Audit\SessionLogin;
use IgorDoctor\Runtime\Audit\StaleReadOracle;
use IgorDoctor\Runtime\Audit\SourceLocator;
use IgorDoctor\Runtime\Audit\WorkerStateAuditor;
use IgorDoctor\Runtime\Snapshot\ContainerStateExtractor;
use IgorDoctor\Runtime\Snapshot\NamespaceFilter;
use IgorDoctor\Runtime\Snapshot\StateSnapshotter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(name: 'igor:worker-scan', description: 'Sends requests to one kernel, as a worker does, and reports the state they leave behind')]
final class WorkerScanCommand extends Command
{
    public const EXIT_CLEAN = 0;
    public const EXIT_LEAKS = 1;
    public const EXIT_ERROR = 2;

    private const CHILD_AUDIT = 'audit';
    private const CHILD_DISCOVER = 'discover';
    private const CHILD_TOUR = 'tour';
    private const SENDING = 'igor-sending: ';
    private const MAX_DISCOVERY_ATTEMPTS = 5;

    private bool $forms = false;

    private ScanEvents $events;

    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('probe', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A request to send three times: "[METHOD ]URI", with {marker} and {a|b|c} placeholders. Without it, one probe per GET route')
            ->addOption('route', null, InputOption::VALUE_REQUIRED, 'Only the generated probes of the routes whose name matches this regular expression')
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A header sent with every probe: "Name: value"')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Report the state owned by classes of this namespace', ['App\\'])
            ->addOption('include-vendors', null, InputOption::VALUE_NONE, 'Report the state owned by any class, framework included')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Log the requests in as this user (its identifier for the user providers): with two users, each probe is sent as the first, the second, then anonymously')
            ->addOption('forms', null, InputOption::VALUE_NONE, 'Also submit the forms found in the pages (GET and POST), each request in a database transaction rolled back once it is over')
            ->addOption('tour', null, InputOption::VALUE_NONE, 'Also send every probe in turn to one kernel, to find what a route leaves for another')
            ->addOption('unblock', null, InputOption::VALUE_REQUIRED, 'Ask the model, through Igor Doctor and Symfony AI (IGOR_AI_COMMAND), for a request that gets this route tested, and replay it (up to three recipes)')
            ->addOption('events', null, InputOption::VALUE_REQUIRED, 'Append what the scan does to this file, one JSON object per line')
            ->addOption('dashboard', null, InputOption::VALUE_OPTIONAL, 'Watch the scan live in a browser, on this port', false)
            ->addOption('in-process', null, InputOption::VALUE_NONE, 'Run every probe in this process: faster, but a probe that calls exit() ends the scan')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON on stdout')
            ->addOption('child', null, InputOption::VALUE_REQUIRED, 'Internal: run one step for a parent scan, reading its probes from stdin')
            ->setHelp(<<<'HELP'
                Without <info>--probe</info>, the probes are built from the application: one per GET route, the query
                parameters API Platform declares, the inputs the code and the templates read (query, cookies,
                headers), and real URLs found by following the links of the HTML pages.

                Each probe runs on a new kernel, in its own process: it is sent three times, and after each sending
                the kernel is terminated, <info>services_resetter</info> runs and the garbage is collected, as in a
                FrankenPHP worker. Then the state of every instantiated service, of the static properties and
                variables and of the PHP process is compared. A probe whose process dies (exit(), die()) is reported.

                  <info>php %command.full_name%</info>
                  <info>php %command.full_name% --include-vendors --tour --json</info>
                  <info>php %command.full_name% --user=alice --user=bob</info>
                  <info>php %command.full_name% --user=alice --user=bob --forms</info>

                With <info>--user</info>, a request is logged in without the login form: a token for the user is put in a
                new session, as KernelBrowser::loginUser() does in tests. With two users, each probe is sent as the
                first, as the second, then anonymously: a value still the second user's for the anonymous request
                is reported.

                With <info>--forms</info>, the forms of the pages are submitted too: each field gets a value its type
                accepts (a marker in the text fields, the choices of a select in turn), and a POST form is sent
                after its page is requested again in the same session, with the CSRF token that page renders.
                Every request runs in a transaction of each Doctrine connection, rolled back once it is over, and
                mails go to the null transport. Other side effects (a message broker, an HTTP API) are not
                blocked: run the scan against a disposable environment.
                  <info>php %command.full_name% -p "/api/books?title={marker}" -p "/api/books/1?archived={true|false}"</info>

                Exit codes: 0 no leak, 1 at least one certain leak, 2 invalid input.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (-1 !== (int) ini_get('memory_limit')) {
            ini_set('memory_limit', '2G');
        }

        $this->forms = (bool) $input->getOption('forms');
        self::runAsWorker($this->forms);
        $this->events = new ScanEvents($input->getOption('events'));

        $child = $input->getOption('child');
        if (\is_string($child)) {
            return $this->child($child, $input, $output);
        }

        $io = new SymfonyStyle($input, $output);
        $unblock = $input->getOption('unblock');
        if (\is_string($unblock)) {
            return $this->unblock($unblock, $input, $io);
        }
        $dashboard = null;
        if (false !== $input->getOption('dashboard')) {
            $file = $this->events->file() ?? $this->kernel->getProjectDir().'/var/igor-scan-'.date('Ymd-His').'.ndjson';
            @mkdir(\dirname($file), 0o777, true);
            touch($file);
            $this->events = new ScanEvents($file);
            $input->setOption('events', $file);
            $dashboard = self::serveDashboard($file, (int) ($input->getOption('dashboard') ?: 8765), $io, $this->kernel->getProjectDir());
        }
        $this->events->emit('scan', ['app' => basename($this->kernel->getProjectDir()), 'kernel' => $this->kernel::class, 'users' => array_values($input->getOption('user')), 'forms' => $this->forms, 'tour' => (bool) $input->getOption('tour'), 'namespaces' => $this->filter($input)->prefixes()]);
        $isolated = !$input->getOption('in-process');

        try {
            $headers = self::headers($input->getOption('header'));
            $probes = array_map(static fn (string $spec): Probe => self::probe($spec, $headers), $input->getOption('probe'));
        } catch (\InvalidArgumentException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return self::EXIT_ERROR;
        }

        $identities = new Identities(array_values($input->getOption('user')));
        if ([] !== $identities->users) {
            $kernel = $this->freshKernel();
            $kernel->boot();
            try {
                foreach ($identities->users as $user) {
                    (new SessionLogin($kernel))->user($user);
                }
            } catch (\RuntimeException $exception) {
                $io->getErrorStyle()->error($exception->getMessage());

                return self::EXIT_ERROR;
            } finally {
                $kernel->shutdown();
            }
        }

        $report = ['namespaces' => $this->filter($input)->prefixes(), 'users' => \count($identities->users), 'inputs' => null, 'probes' => [], 'findings' => [], 'errors' => []];
        $terminated = [];

        if ([] === $probes) {
            $route = $input->getOption('route');
            if (null !== $route && false === @preg_match($route, '')) {
                $io->getErrorStyle()->error(\sprintf('Invalid --route pattern "%s".', $route));

                return self::EXIT_ERROR;
            }

            $probes = $this->generatedProbes($route, $headers);
            $inputs = $this->harvest();
            $report['inputs'] = $inputs->names();
            $probes = array_map($inputs->applyTo(...), $this->discover($probes, $input, $isolated, $terminated));
            if ($this->forms) {
                // Recipes submit forms and create, edit or delete data: they are replayed only in the scans
                // whose requests run in a rolled-back transaction.
                array_push($probes, ...RouteUnblocker::savedProbes($this->kernel->getProjectDir()));
            }
        }
        $this->events->emit('probes', ['probes' => array_map(static fn (Probe $probe): array => ['name' => $probe->name, 'method' => $probe->method, 'form' => null !== $probe->formPage], $probes)]);

        if ([] === $probes) {
            $io->getErrorStyle()->error('No probe: give --probe, or check that the application has GET routes.');

            return self::EXIT_ERROR;
        }

        foreach ($probes as $probe) {
            if (isset($terminated[$probe->name])) {
                continue;
            }
            $stderr = '';
            $result = $isolated ? $this->spawn(self::CHILD_AUDIT, [$probe], $input, $stderr) : $this->audit([$probe], $this->filter($input), $identities);
            if (null === $result) {
                $error = self::scanError($stderr);
                if (null !== $error) {
                    $report['errors'][] = ['probe' => $probe->name, 'error' => $error];
                    $this->events->emit('error', ['probe' => $probe->name, 'error' => $error]);
                } else {
                    $terminated[$probe->name] = $probe;
                    $this->events->emit('crash', ['probe' => $probe->name]);
                }
                continue;
            }
            array_push($report['probes'], ...$result['probes']);
            array_push($report['findings'], ...$result['findings']);
            $this->findingEvents($result['findings']);
        }

        if ($input->getOption('tour')) {
            $remaining = array_values(array_filter($probes, static fn (Probe $probe): bool => !isset($terminated[$probe->name])));
            $result = $isolated ? $this->spawn(self::CHILD_TOUR, $remaining, $input) : $this->tour($remaining, $this->filter($input), $identities);
            if (null !== $result) {
                array_push($report['probes'], ...$result['probes']);
                array_push($report['findings'], ...$result['findings']);
                $this->findingEvents($result['findings']);
            }
        }

        foreach ($terminated as $name => $probe) {
            $this->events->emit('crash', ['probe' => $name]);
            $report['findings'][] = (new LeakFinding(
                LeakType::ExecutionTerminated,
                true,
                $name,
                $name,
                [$probe->uri],
                1,
                'the request ended the PHP process (exit, die or a fatal error): a worker restarts and loses everything it kept',
            ))->toArray();
        }

        $report['findings'] = $this->located($report['findings']);
        $certain = array_filter($report['findings'], static fn (array $finding): bool => $finding['certain']);
        if ($this->events->enabled()) {
            $this->events->emit('done', ['findings' => array_map(fn (array $finding): array => array_intersect_key($finding, array_flip(['type', 'certain', 'impact', 'owner', 'probe', 'evidence', 'file', 'line', 'paths'])) + $this->origin($finding) + $this->holder($finding), $report['findings']), 'errors' => \count($report['errors'])]);
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        } else {
            $this->render($io, $report);
        }

        if (null !== $dashboard) {
            if ($input->isInteractive()) {
                $io->text('The dashboard stays open: press Enter to close it.');
                fgets(\STDIN);
            }
            proc_terminate($dashboard);
        }

        return [] === $certain ? self::EXIT_CLEAN : self::EXIT_LEAKS;
    }

    private function unblock(string $route, InputInterface $input, SymfonyStyle $io): int
    {
        $users = array_values($input->getOption('user'));
        $filter = $this->filter($input);
        $replay = function (Probe $probe, Identities $identities) use ($filter): ProbeRun {
            $kernel = $this->freshKernel();
            $started = hrtime(true);
            $run = (new WorkerStateAuditor($kernel, new StateSnapshotter($filter), new ContainerStateExtractor($filter), identities: $identities, rollBack: true, events: $this->events))->run($probe);
            $kernel->shutdown();
            $this->findingEvents($this->merge(['probes' => [], 'findings' => []], $probe->name, $run, $started)['findings']);

            return $run;
        };
        $ai = new ModelCommand();
        $kernel = $this->freshKernel();
        $result = (new RouteUnblocker($kernel, $replay, $ai->ask(...), $this->events, $users))->unblock($route);
        $kernel->shutdown();

        if ($result['ok']) {
            $io->success(\sprintf('%s is tested, after %d recipe(s): %s', $route, $result['attempts'], $result['file']));

            return self::EXIT_CLEAN;
        }
        $io->getErrorStyle()->error(\sprintf('%s is still not tested: %s', $route, $result['error']));

        return self::EXIT_LEAKS;
    }

    /**
     * @return resource the PHP server process that serves the dashboard
     */
    private static function serveDashboard(string $events, int $port, SymfonyStyle $io, string $projectDir)
    {
        $router = \dirname(__DIR__, 2).'/resources/dashboard/server.php';
        $process = proc_open([\PHP_BINARY, '-S', '127.0.0.1:'.$port, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, [...getenv(), 'IGOR_SCAN_EVENTS' => $events, 'IGOR_PROJECT_DIR' => $projectDir]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the dashboard server.');
        }
        $io->writeln(\sprintf('Dashboard: <info>http://127.0.0.1:%d</info> (events in %s)', $port, $events));
        usleep(300_000);

        return $process;
    }

    /**
     * Where a leak lives: the application's code, or a Composer package (with the version installed), so that a
     * leak in a dependency is reported upstream instead of patched.
     *
     * @param array<string, mixed> $finding
     *
     * @return array{origin: string, class: ?string, source: ?string, package: ?string, version: ?string}
     */
    private function origin(array $finding): array
    {
        $projectDir = $this->kernel->getProjectDir();
        $class = null;
        if (1 === preg_match('/([A-Za-z_][\w]*(?:\\\\[A-Za-z_][\w]*)+)/', (string) ($finding['owner'] ?? ''), $match) && (class_exists($match[1]) || trait_exists($match[1]))) {
            $class = $match[1];
        }
        $file = \is_string($finding['file'] ?? null) ? $finding['file'] : null;
        if (null !== $class) {
            $declared = (new \ReflectionClass($class))->getFileName();
            if (\is_string($declared) && str_starts_with($declared, $projectDir.'/')) {
                $file ??= substr($declared, \strlen($projectDir) + 1);
            }
        }

        if (null !== $file && 1 === preg_match('#^vendor/([^/]+/[^/]+)/#', $file, $package)) {
            $version = null;
            if (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($package[1])) {
                $version = \Composer\InstalledVersions::getPrettyVersion($package[1]);
            }

            return ['origin' => 'dependency', 'class' => $class, 'source' => $file, 'package' => $package[1], 'version' => $version];
        }

        return ['origin' => null !== $file ? 'application' : 'unknown', 'class' => $class, 'source' => $file, 'package' => null, 'version' => null];
    }

    /**
     * The class that keeps the value, when it is another class of the same dependency, with its package and version.
     *
     * @param array<string, mixed> $finding
     *
     * @return array{holder?: array{class: string, property: string, source: string, package: string, version: ?string}}
     */
    private function holder(array $finding): array
    {
        $holder = $finding['holder'] ?? null;
        if (!\is_array($holder) || 1 !== preg_match('#^vendor/([^/]+/[^/]+)/#', (string) ($holder['source'] ?? ''), $package)) {
            return [];
        }
        $version = class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($package[1]) ? \Composer\InstalledVersions::getPrettyVersion($package[1]) : null;

        return ['holder' => $holder + ['package' => $package[1], 'version' => $version]];
    }

    /**
     * The values this probe's requests left are read again: the plain request answers differently. They are
     * exposures, not only values kept in memory. When the walk found no kept value, the difference is reported
     * as a suspect: the state is outside the audited namespaces, or the answer changes on its own.
     *
     * @param list<array<string, mixed>> $findings
     *
     * @return list<array<string, mixed>>
     */
    private static function exposed(array $findings, Probe $probe, string $difference): array
    {
        $promoted = false;
        foreach ($findings as $index => $finding) {
            if ($finding['probe'] === $probe->name && $finding['certain'] && 'retention' === ($finding['impact'] ?? null) && LeakType::CrossRequestStateLeak->value === $finding['type']) {
                $findings[$index]['impact'] = 'exposure';
                $findings[$index]['evidence'] .= '; and it is read again: '.$difference;
                $promoted = true;
            }
        }
        if (!$promoted) {
            $findings[] = (new LeakFinding(LeakType::CrossRequestStateLeak, false, 'response', $probe->name, [$probe->uri], 1, 'the state the earlier requests left changes the answer: '.$difference))->toArray();
        }

        return $findings;
    }

    /**
     * @param list<array<string, mixed>> $findings
     */
    private function findingEvents(array $findings): void
    {
        foreach ($findings as $finding) {
            $this->events->emit('finding', array_intersect_key($finding, array_flip(['type', 'certain', 'impact', 'owner', 'probe', 'evidence', 'paths'])));
        }
    }

    /**
     * The kernels of the scan run as a FrankenPHP worker does, not as a console command: Symfony picks the
     * error renderer and other services from the runtime mode, and the console one dumps exceptions with
     * VarDumper, whose static caches would be reported as the application's.
     */
    private static function runAsWorker(bool $forms): void
    {
        $variables = ['APP_RUNTIME_MODE' => 'web=1&worker=1'];
        if ($forms) {
            // A submitted form must not send a mail to a real address.
            $variables['MAILER_DSN'] = 'null://null';
        }
        foreach ($variables as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    private function child(string $mode, InputInterface $input, OutputInterface $output): int
    {
        $probes = array_map(Probe::fromArray(...), json_decode((string) stream_get_contents(\STDIN), true, flags: \JSON_THROW_ON_ERROR));
        $identities = new Identities(array_values($input->getOption('user')));
        $result = match ($mode) {
            self::CHILD_AUDIT => $this->audit($probes, $this->filter($input), $identities),
            self::CHILD_TOUR => $this->tour($probes, $this->filter($input), $identities),
            self::CHILD_DISCOVER => ['probes' => array_map(
                static fn (Probe $probe): array => $probe->toArray(),
                (new LinkDiscovery($this->freshKernel(), static function (Probe $probe): void {
                    fwrite(\STDERR, self::SENDING.$probe->name."\n");
                }, $identities->at(1), $this->forms, $this->events))->discover($probes),
            )],
            default => throw new \InvalidArgumentException(\sprintf('Unknown child step "%s".', $mode)),
        };
        $output->writeln(json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

        return self::EXIT_CLEAN;
    }

    /**
     * @param list<Probe>               $probes
     * @param array<string, Probe>      $terminated filled with the probes that ended the process
     *
     * @return list<Probe>
     */
    private function discover(array $probes, InputInterface $input, bool $isolated, array &$terminated): array
    {
        if (!$isolated) {
            return (new LinkDiscovery($this->freshKernel(), null, (new Identities(array_values($input->getOption('user'))))->at(1), $this->forms, $this->events))->discover($probes);
        }

        for ($attempt = 0; $attempt < self::MAX_DISCOVERY_ATTEMPTS; ++$attempt) {
            $candidates = array_values(array_filter($probes, static fn (Probe $probe): bool => !isset($terminated[$probe->name])));
            $result = $this->spawn(self::CHILD_DISCOVER, $candidates, $input, $stderr);
            if (null !== $result) {
                $discovered = [];
                foreach ($result['probes'] as $data) {
                    $discovered[$data['name']] = Probe::fromArray($data);
                }

                $known = [];
                foreach ($probes as $probe) {
                    $known[$probe->name] = true;
                }

                return [
                    ...array_map(static fn (Probe $probe): Probe => $discovered[$probe->name] ?? $probe, $probes),
                    ...array_values(array_filter($discovered, static fn (Probe $probe): bool => !isset($known[$probe->name]))),
                ];
            }

            $last = null;
            foreach (explode("\n", $stderr) as $line) {
                if (str_starts_with($line, self::SENDING)) {
                    $last = substr($line, \strlen(self::SENDING));
                }
            }
            if (null === $last) {
                break;
            }
            $terminated[$last] = array_values(array_filter($probes, static fn (Probe $probe): bool => $probe->name === $last))[0] ?? new Probe($last, '?');
        }

        return $probes;
    }

    /**
     * A child that died on an exception of the scan itself (the console prints "In File.php line N:") did
     * not end the process the way an exit() in the application does: it is a scan error, not a finding.
     */
    private static function scanError(string $stderr): ?string
    {
        if (1 !== preg_match('/^\s+In \S+ line \d+:\s*$/m', $stderr)) {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr)), static fn (string $line): bool => '' !== $line && !str_starts_with($line, 'PHP ') && !str_starts_with($line, 'In ') && !str_starts_with($line, 'igor:worker-scan')));

        return substr(implode(' ', \array_slice($lines, -4)), 0, 300);
    }

    /**
     * @param list<Probe> $probes
     *
     * @return array{probes: list<array<string, mixed>>, findings: list<array<string, mixed>>}|null null when the child died without a report
     */
    private function spawn(string $mode, array $probes, InputInterface $input, ?string &$stderr = null): ?array
    {
        // Warnings go to stderr, as on a server: printed on stdout, they would count as sent headers and break sessions.
        $command = [\PHP_BINARY, '-d', 'display_errors=stderr', (string) ($_SERVER['argv'][0] ?? 'bin/console'), 'igor:worker-scan', '--child='.$mode];
        if ($input->getOption('include-vendors')) {
            $command[] = '--include-vendors';
        }
        foreach ($input->getOption('namespace') as $namespace) {
            $command[] = '--namespace='.$namespace;
        }
        foreach ($input->getOption('user') as $user) {
            $command[] = '--user='.$user;
        }
        if ($this->forms) {
            $command[] = '--forms';
        }
        if (null !== $this->events->file()) {
            $command[] = '--events='.$this->events->file();
        }

        $errors = tmpfile();
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $errors], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start a child scan process.');
        }
        fwrite($pipes[0], json_encode(array_map(static fn (Probe $probe): array => $probe->toArray(), $probes), \JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        rewind($errors);
        $stderr = (string) stream_get_contents($errors);
        fclose($errors);

        $result = null;
        foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
            if (str_starts_with($line, '{')) {
                $result = json_decode($line, true);
                break;
            }
        }

        return 0 === $status && \is_array($result) ? $result + ['probes' => [], 'findings' => []] : null;
    }

    /**
     * @param list<Probe> $probes
     *
     * @return array{probes: list<array<string, mixed>>, findings: list<array<string, mixed>>}
     */
    private function audit(array $probes, NamespaceFilter $filter, Identities $identities): array
    {
        $result = ['probes' => [], 'findings' => []];
        foreach ($probes as $probe) {
            $kernel = $this->freshKernel();
            $started = hrtime(true);
            $run = (new WorkerStateAuditor($kernel, new StateSnapshotter($filter), new ContainerStateExtractor($filter), identities: $identities, rollBack: $this->forms, events: $this->events))->run($probe);
            $neutral = $probe->neutral();
            $user = $run->identities[1] ?? null;
            $usedAnswer = null !== $neutral ? WorkerStateAuditor::answer($kernel, $neutral, $user, $this->forms) : null;
            $kernel->shutdown();
            $result = $this->merge($result, $probe->name, $run, $started);

            if (null !== $neutral && null !== $usedAnswer) {
                $fresh = $this->freshKernel();
                $difference = StaleReadOracle::difference($usedAnswer, WorkerStateAuditor::answer($fresh, $neutral, $user, $this->forms));
                $fresh->shutdown();
                if (null !== $difference) {
                    $result['findings'] = self::exposed($result['findings'], $probe, $difference);
                }
            }

            $fresh = $this->freshKernel();
            $echo = (new ResponseEchoOracle())->check($run, WorkerStateAuditor::freshAnswer($fresh, $probe, $run->markers[2], 2, $run->identities[2] ?? null, $this->forms));
            $fresh->shutdown();
            if (null !== $echo) {
                $result['findings'][] = $echo->toArray();
            }
        }

        return $result;
    }

    /**
     * @param list<Probe> $probes
     *
     * @return array{probes: list<array<string, mixed>>, findings: list<array<string, mixed>>}
     */
    private function tour(array $probes, NamespaceFilter $filter, Identities $identities): array
    {
        $kernel = $this->freshKernel();
        $started = hrtime(true);
        $run = (new WorkerStateAuditor($kernel, new StateSnapshotter($filter), new ContainerStateExtractor($filter), identities: $identities, rollBack: $this->forms, events: $this->events))->runTour($probes);
        $kernel->shutdown();

        return $this->merge(['probes' => [], 'findings' => []], $run->probe->name, $run, $started);
    }

    /**
     * @param array{probes: list<array<string, mixed>>, findings: list<array<string, mixed>>} $result
     *
     * @return array{probes: list<array<string, mixed>>, findings: list<array<string, mixed>>}
     */
    private function merge(array $result, string $name, ProbeRun $run, int $started): array
    {
        $result['probes'][] = [
            'name' => $name,
            'statuses' => array_values($run->statuses),
            'nodes' => \count($run->snapshots[\count($run->snapshots) - 1]->nodes),
            'truncated' => array_reduce($run->snapshots, static fn (bool $carry, $snapshot): bool => $carry || $snapshot->truncated, false),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
        $nodes = $run->snapshots[\count($run->snapshots) - 1]->nodes;
        $projectDir = $this->kernel->getProjectDir();
        foreach ((new LeakClassifier())->classify($run) as $finding) {
            $finding = $finding->toArray();
            $holder = isset($finding['paths'][0]) ? LeakHolder::find($finding['paths'][0], $finding['owner'], $nodes, $projectDir) : null;
            $result['findings'][] = $finding + (null !== $holder ? ['holder' => $holder] : []);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $findings
     *
     * @return list<array<string, mixed>> each with the file, relative to the project, and the line a fix starts from, or null
     */
    private function located(array $findings): array
    {
        if ([] === $findings) {
            return $findings;
        }

        $kernel = $this->freshKernel();
        $kernel->boot();
        $router = $kernel->getContainer()->get('router');
        $locator = new SourceLocator($this->kernel->getProjectDir(), $router instanceof RouterInterface ? $router : null);
        foreach ($findings as $index => $finding) {
            $location = $locator->locate($finding);
            $findings[$index]['file'] = $location['file'] ?? null;
            $findings[$index]['line'] = $location['line'] ?? null;
        }
        $kernel->shutdown();

        return $findings;
    }

    private function filter(InputInterface $input): NamespaceFilter
    {
        return $input->getOption('include-vendors') ? NamespaceFilter::everything() : new NamespaceFilter(array_values($input->getOption('namespace')));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<Probe>
     */
    private function generatedProbes(?string $route, array $headers): array
    {
        $kernel = $this->freshKernel();
        $kernel->boot();
        $container = $kernel->getContainer();
        $resourceMetadata = $container->has(ProbeFactory::RESOURCE_METADATA) ? $container->get(ProbeFactory::RESOURCE_METADATA) : null;
        $router = $container->get('router');
        if ($router instanceof RouterInterface) {
            $routes = [];
            foreach ($router->getRouteCollection()->all() as $name => $definition) {
                $controller = $definition->getDefault('_controller');
                $routes[] = ['name' => $name, 'path' => $definition->getPath(), 'methods' => $definition->getMethods(), 'controller' => \is_string($controller) ? $controller : null];
            }
            $this->events->emit('routes', ['routes' => $routes]);
        }
        $probes = array_map(
            static fn (Probe $probe): Probe => new Probe($probe->name, $probe->uri, $probe->method, [...$probe->headers, ...$headers], $probe->body, $probe->cookies),
            (new ProbeFactory($container->get('router'), $resourceMetadata))->probes($route),
        );
        $kernel->shutdown();

        return $probes;
    }

    private function harvest(): HarvestedInputs
    {
        $projectDir = $this->kernel->getProjectDir();

        return (new InputHarvester([$projectDir.'/src', $projectDir.'/templates']))->harvest();
    }

    private function freshKernel(): KernelInterface
    {
        $class = $this->kernel::class;

        return new $class($this->kernel->getEnvironment(), $this->kernel->isDebug());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function probe(string $spec, array $headers): Probe
    {
        $spec = trim($spec);
        if (1 === preg_match('/^([A-Z]+)\s+(\S+)$/', $spec, $match)) {
            return new Probe($spec, $match[2], $match[1], $headers);
        }
        if (str_starts_with($spec, '/')) {
            return new Probe($spec, $spec, 'GET', $headers);
        }

        throw new \InvalidArgumentException(\sprintf('Invalid probe "%s": expected "[METHOD ]/path?query".', $spec));
    }

    /**
     * @param list<string> $specs
     *
     * @return array<string, string>
     */
    private static function headers(array $specs): array
    {
        $headers = [];
        foreach ($specs as $spec) {
            if (!str_contains($spec, ':')) {
                throw new \InvalidArgumentException(\sprintf('Invalid header "%s": expected "Name: value".', $spec));
            }
            [$name, $value] = explode(':', $spec, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }

    /**
     * @param array{probes: list<array<string, mixed>>, findings: list<array<string, mixed>>} $report
     */
    private function render(SymfonyStyle $io, array $report): void
    {
        foreach ($report['probes'] as $probe) {
            $io->writeln(\sprintf('<info>%s</info>: HTTP %s, %d nodes, %d ms%s', $probe['name'], implode(' / ', $probe['statuses']), $probe['nodes'], $probe['duration_ms'], $probe['truncated'] ? ' <comment>(truncated)</comment>' : ''));
        }

        if ([] === $report['findings']) {
            $io->success('No state survives between the requests.');

            return;
        }

        foreach ($report['findings'] as $finding) {
            $io->section(\sprintf('%s (%s) in %s', $finding['type'], $finding['certain'] ? 'certain' : 'suspect', $finding['owner']));
            $io->writeln(\sprintf('Probe <info>%s</info>: %s', $finding['probe'], $finding['evidence']));
            if (null !== ($finding['file'] ?? null)) {
                $io->writeln(\sprintf('Fix from <info>%s:%d</info>', $finding['file'], $finding['line']));
            }
            $io->listing($finding['paths']);
            if ($finding['path_count'] > \count($finding['paths'])) {
                $io->writeln(\sprintf('… and %d more', $finding['path_count'] - \count($finding['paths'])));
            }
        }
    }
}
