<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Gets a route the scan could not test through, with a model's help: the model reads the route, its
 * controller, the entities it loads (with a few real rows), the users that can log in and the answers the
 * scan got, and writes a request recipe (JSON). The recipe is checked, turned into a probe and replayed
 * like any other, rolled back; when the controller is still not reached, the answers go back to the model,
 * up to three attempts. The model never runs anything: it only writes the recipe.
 */
final class RouteUnblocker
{
    public const ATTEMPTS = 3;

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param \Closure(Probe, Identities): ProbeRun $replay sends a probe as the identities say, and returns its run
     * @param \Closure(string): string             $ask    the model: a prompt in, its text answer out
     * @param list<string>                        $users  the users the scan logs in as
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly \Closure $replay,
        private readonly \Closure $ask,
        private readonly ScanEvents $events,
        private readonly array $users = [],
    ) {
    }

    /**
     * @return array{ok: bool, attempts: int, recipe: ?array<string, mixed>, file: ?string, error: ?string}
     */
    public function unblock(string $routeName): array
    {
        $this->kernel->boot();
        $router = $this->kernel->getContainer()->get('router');
        $route = $router instanceof RouterInterface ? $router->getRouteCollection()->get($routeName) : null;
        if (null === $route) {
            return $this->fail($routeName, 0, \sprintf('No route "%s".', $routeName));
        }

        $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'start']);
        $context = $this->context($routeName, $route);
        $history = [];
        $recipe = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; ++$attempt) {
            $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'thinking', 'attempt' => $attempt]);
            try {
                $recipe = $this->validate(self::decode(($this->ask)($this->prompt($context, $history))), $route);
            } catch (ModelUnavailable $exception) {
                $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'rejected', 'attempt' => $attempt, 'reason' => $exception->getMessage()]);

                return $this->fail($routeName, $attempt, $exception->getMessage());
            } catch (\RuntimeException $exception) {
                $history[] = ['recipe' => $recipe, 'problem' => $exception->getMessage()];
                $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'rejected', 'attempt' => $attempt, 'reason' => $exception->getMessage()]);
                continue;
            }

            $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'recipe', 'attempt' => $attempt, 'explanation' => $recipe['explanation'], 'request' => self::summary($recipe), 'user' => $recipe['user']]);
            $run = ($this->replay)(self::probe($routeName, $recipe), new Identities(self::identities($recipe['user'], $this->users)));
            [$ok, $answers] = self::judge($run, $recipe['user']);
            $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'result', 'attempt' => $attempt, 'ok' => $ok, 'answers' => $answers]);

            if ($ok) {
                $file = $this->save($routeName, $recipe);
                $this->events->emit('unblock', ['route' => $routeName, 'phase' => 'done', 'ok' => true, 'attempts' => $attempt, 'file' => $file]);

                return ['ok' => true, 'attempts' => $attempt, 'recipe' => $recipe, 'file' => $file, 'error' => null];
            }
            $history[] = ['recipe' => $recipe, 'answers' => $answers];
        }

        return $this->fail($routeName, self::ATTEMPTS, 'The controller was still not reached after '.self::ATTEMPTS.' recipes.', $recipe);
    }

    /**
     * Everything the model needs, read from the application: no file outside the route's controller, no
     * configuration, no environment variable.
     *
     * @return array<string, mixed>
     */
    private function context(string $name, Route $route): array
    {
        $defaults = $route->getDefaults();
        $controller = \is_string($defaults['_controller'] ?? null) ? $defaults['_controller'] : null;
        unset($defaults['_controller']);

        $context = [
            'route' => ['name' => $name, 'path' => $route->getPath(), 'methods' => $route->getMethods(), 'requirements' => $route->getRequirements(), 'defaults' => array_filter($defaults, 'is_scalar'), 'condition' => $route->getCondition() ?: null],
            'controller' => null,
            'entities' => [],
            'users' => [],
            'previous_answers' => $this->previousAnswers($name),
        ];

        $method = self::controllerMethod($controller);
        if (null !== $method) {
            $file = (string) $method->getFileName();
            $lines = file($file) ?: [];
            $start = max(1, (int) $method->getStartLine() - 12);
            $source = implode('', \array_slice($lines, $start - 1, min(140, (int) $method->getEndLine() - $start + 1)));
            $context['controller'] = [
                'name' => $controller,
                'file' => str_replace($this->kernel->getProjectDir().'/', '', $file),
                'class_attributes' => self::attributes($method->getDeclaringClass()->getAttributes()),
                'source' => $source,
            ];
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && class_exists($type->getName())) {
                    $entity = $this->entity($type->getName());
                    if (null !== $entity) {
                        $context['entities'][$parameter->getName()] = $entity;
                    }
                }
            }
        }

        foreach ($this->users as $user) {
            try {
                $loaded = (new SessionLogin($this->kernel))->user($user);
                $context['users'][] = ['identifier' => $user, 'roles' => $loaded->getRoles()];
            } catch (\RuntimeException) {
            }
        }

        return $context;
    }

    /**
     * @return array{class: string, fields: array<string, string>, rows: list<array<string, scalar|null>>}|null
     */
    private function entity(string $class): ?array
    {
        $container = $this->kernel->getContainer();
        if (!$container->has('doctrine')) {
            return null;
        }
        $manager = $container->get('doctrine')->getManagerForClass($class);
        if (null === $manager) {
            return null;
        }

        $metadata = $manager->getClassMetadata($class);
        $fields = [];
        foreach ($metadata->getFieldNames() as $field) {
            $fields[$field] = (string) $metadata->getTypeOfField($field);
        }
        $scalar = \array_slice(array_keys(array_filter($fields, static fn (string $type): bool => \in_array($type, ['integer', 'smallint', 'bigint', 'string', 'text', 'boolean', 'guid', 'ascii_string'], true))), 0, 8);

        $rows = [];
        try {
            $query = $manager->createQueryBuilder()->select(array_map(static fn (string $field): string => 'e.'.$field, $scalar))->from($class, 'e')->setMaxResults(3)->getQuery();
            foreach ($query->getArrayResult() as $row) {
                $rows[] = array_map(static fn (mixed $value): mixed => \is_string($value) ? mb_substr($value, 0, 60) : (\is_scalar($value) || null === $value ? $value : get_debug_type($value)), $row);
            }
        } catch (\Throwable) {
        }

        return ['class' => $class, 'fields' => $fields, 'rows' => $rows];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function previousAnswers(string $route): array
    {
        $file = $this->events->file();
        if (null === $file) {
            return [];
        }
        $answers = [];
        $from = 0;
        do {
            $chunk = ScanEvents::read($file, $from);
            $from = $chunk['next'];
            foreach ($chunk['events'] as $event) {
                $probe = (string) ($event['probe'] ?? '');
                if ('request' === ($event['t'] ?? null) && empty($event['tour']) && ($probe === $route || 1 === preg_match('/^(?:form|unblock) '.preg_quote($route, '/').'(?: \(|$)/', $probe))) {
                    $answers[] = array_intersect_key($event, array_flip(['probe', 'user', 'method', 'uri', 'status', 'controller', 'reason']));
                }
            }
        } while ([] !== $chunk['events']);

        return \array_slice($answers, -10);
    }

    /**
     * @param array<string, mixed>                                 $context
     * @param list<array{recipe: mixed, answers?: mixed, problem?: string}> $history
     */
    private function prompt(array $context, array $history): string
    {
        $json = static fn (mixed $value): string => json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $prompt = <<<'PROMPT'
            You help a test tool reach a route of a Symfony application. The tool sends the request itself, in a
            database transaction that is rolled back, logged in as one of the users listed below (or anonymously).
            Its previous requests did not reach the controller, or the controller refused them. Read the route, its
            controller, the entities it loads (with real rows from the database) and the previous answers, and write
            the request that reaches the controller and succeeds (a 2xx, or a 3xx redirect after a successful form).

            Answer with ONE JSON object and nothing else:
            {
              "explanation": "one or two sentences: what blocked the previous requests and what this request changes",
              "user": "identifier of a listed user, or null for an anonymous request",
              "method": "GET|POST|PUT|PATCH|DELETE",
              "path": "/the/real/path, with real values in place of the route variables",
              "query": {"name": "value"},
              "form": {"field[name]": "value"} or null,
              "json": {...} or null,
              "headers": {"Name": "value"},
              "csrf_from": "/path/of/the/page/that/renders/the/form" or null
            }

            Rules:
            - Use real values from the entity rows for identifiers and slugs. Use a role the route needs.
            - For a form, give every field the controller's form needs, with values its validation accepts. Put
              "{marker}" inside at least one free-text value (it is replaced by a unique value for each sending).
            - When the form is protected by a CSRF token, set "csrf_from" to the page that renders the form: the
              tool requests it in the same session first and sends the hidden fields it renders, so leave the
              token field out.
            - The path is a path of this application, starting with "/". No other host, no shell, no code.

            PROMPT;
        $prompt .= "Application context:\n".$json($context)."\n";
        foreach ($history as $index => $entry) {
            $prompt .= \sprintf("\nAttempt %d, which failed:\n%s\n", $index + 1, $json($entry));
        }

        return $prompt."\nThe JSON object:";
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $recipe = false !== $start && false !== $end ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;
        if (!\is_array($recipe)) {
            throw new \RuntimeException('The answer is not a JSON object.');
        }

        return $recipe;
    }

    /**
     * @param array<string, mixed> $recipe
     *
     * @return array{explanation: string, user: ?string, method: string, path: string, query: array<string, string>, form: ?array<string, mixed>, json: mixed, headers: array<string, string>, csrf_from: ?string}
     */
    private function validate(array $recipe, Route $route): array
    {
        $method = strtoupper((string) ($recipe['method'] ?? 'GET'));
        $path = (string) ($recipe['path'] ?? '');
        $user = $recipe['user'] ?? null;
        $csrf = $recipe['csrf_from'] ?? null;

        if (!\in_array($method, self::METHODS, true)) {
            throw new \RuntimeException(\sprintf('Method "%s" is not one of %s.', $method, implode(', ', self::METHODS)));
        }
        foreach (['path' => $path, 'csrf_from' => $csrf] as $key => $value) {
            if (null !== $value && (!\is_string($value) || !str_starts_with($value, '/') || str_starts_with($value, '//') || 1 === preg_match('/[\s\\\\]/', $value))) {
                throw new \RuntimeException(\sprintf('"%s" must be a path of the application, starting with "/".', $key));
            }
        }
        if (null !== $user && !\in_array($user, $this->users, true)) {
            throw new \RuntimeException(\sprintf('User "%s" is not one of the listed users.', \is_scalar($user) ? $user : get_debug_type($user)));
        }
        if ([] !== $route->getMethods() && !\in_array($method, $route->getMethods(), true) && !('GET' === $method && \in_array('HEAD', $route->getMethods(), true))) {
            throw new \RuntimeException(\sprintf('The route accepts %s, not %s.', implode(', ', $route->getMethods()), $method));
        }

        $strings = static fn (mixed $values): array => \is_array($values) ? array_map(static fn (mixed $value): string => \is_scalar($value) ? (string) $value : (string) json_encode($value), $values) : [];

        return [
            'explanation' => mb_substr((string) ($recipe['explanation'] ?? ''), 0, 400),
            'user' => $user,
            'method' => $method,
            'path' => $path,
            'query' => $strings($recipe['query'] ?? []),
            'form' => \is_array($recipe['form'] ?? null) ? $recipe['form'] : null,
            'json' => $recipe['json'] ?? null,
            'headers' => array_filter($strings($recipe['headers'] ?? []), static fn (string $value, string $name): bool => 1 === preg_match('/^[A-Za-z0-9-]+$/', $name) && !\in_array(strtolower($name), ['host', 'cookie', 'content-length'], true), \ARRAY_FILTER_USE_BOTH),
            'csrf_from' => $csrf,
        ];
    }

    /**
     * The probes of the recipes saved in a project (var/igor-recipes), so that a scan sends again the requests that
     * got its blocked routes tested.
     *
     * @return list<Probe>
     */
    public static function savedProbes(string $projectDir): array
    {
        $probes = [];
        foreach (glob($projectDir.'/var/igor-recipes/*.json') ?: [] as $file) {
            $recipe = json_decode((string) file_get_contents($file), true);
            if (\is_array($recipe) && \is_string($recipe['route'] ?? null) && \is_string($recipe['path'] ?? null) && \is_string($recipe['method'] ?? null)) {
                $probes[] = self::probe($recipe['route'], $recipe + ['query' => [], 'form' => null, 'json' => null, 'headers' => [], 'csrf_from' => null]);
            }
        }

        return $probes;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function probe(string $route, array $recipe): Probe
    {
        $encode = static fn (string $value): string => str_replace('%7Bmarker%7D', Probe::MARKER, rawurlencode($value));
        $pairs = [];
        foreach ($recipe['query'] as $name => $value) {
            $pairs[] = rawurlencode((string) $name).'='.$encode($value);
        }
        $uri = $recipe['path'].([] === $pairs ? '' : (str_contains($recipe['path'], '?') ? '&' : '?').implode('&', $pairs));

        $headers = $recipe['headers'];
        $body = null;
        if (null !== $recipe['form']) {
            $body = implode('&', array_map(
                static fn (string $pair): string => implode('=', array_map(static fn (string $part): string => $encode(urldecode($part)), explode('=', $pair, 2))),
                explode('&', http_build_query($recipe['form'])),
            ));
            $headers['Content-Type'] = Probe::FORM_TYPE;
        } elseif (null !== $recipe['json']) {
            $body = (string) json_encode($recipe['json'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json';
        }

        return new Probe('unblock '.$route, $uri, $recipe['method'], $headers, $body, [], $recipe['csrf_from']);
    }

    /**
     * The recipe works when every sending as the recipe's user reached the controller and was not refused. The
     * other user and the anonymous visitor may be refused: a post only its author can delete is tested when the
     * author deletes it.
     *
     * @return array{bool, list<array<string, mixed>>}
     */
    private static function judge(ProbeRun $run, ?string $recipeUser): array
    {
        $answers = [];
        $counted = 0;
        $good = 0;
        foreach ($run->statuses as $sending => $status) {
            $user = $run->identities[$sending] ?? null;
            $reached = $run->controllers[$sending] ?? null;
            $answers[] = ['user' => $user, 'status' => $status, 'controller' => $reached, 'reason' => $run->reasons[$sending] ?? null];
            if ($user !== $recipeUser) {
                continue;
            }
            ++$counted;
            if (null !== $reached && $status < 400) {
                ++$good;
            }
        }

        return [$counted > 0 && $good === $counted, $answers];
    }

    /**
     * @param list<string> $users
     *
     * @return list<string>
     */
    private static function identities(?string $user, array $users): array
    {
        if (null === $user) {
            return [];
        }
        $other = array_values(array_filter($users, static fn (string $candidate): bool => $candidate !== $user));

        return [$user, ...\array_slice($other, 0, 1)];
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function save(string $route, array $recipe): string
    {
        $directory = $this->kernel->getProjectDir().'/var/igor-recipes';
        @mkdir($directory, 0o777, true);
        $file = $directory.'/'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $route).'.json';
        file_put_contents($file, json_encode(['route' => $route] + $recipe, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");

        return str_replace($this->kernel->getProjectDir().'/', '', $file);
    }

    /**
     * @param array<string, mixed>|null $recipe
     *
     * @return array{ok: bool, attempts: int, recipe: ?array<string, mixed>, file: ?string, error: ?string}
     */
    private function fail(string $route, int $attempts, string $error, ?array $recipe = null): array
    {
        $this->events->emit('unblock', ['route' => $route, 'phase' => 'done', 'ok' => false, 'attempts' => $attempts, 'error' => $error]);

        return ['ok' => false, 'attempts' => $attempts, 'recipe' => $recipe, 'file' => null, 'error' => $error];
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private static function summary(array $recipe): string
    {
        $summary = $recipe['method'].' '.$recipe['path'];
        if ([] !== $recipe['query']) {
            $summary .= '?'.http_build_query($recipe['query']);
        }
        if (null !== $recipe['form']) {
            $summary .= '  form: '.implode(', ', array_keys(self::flatten($recipe['form'])));
        }
        if (null !== $recipe['csrf_from']) {
            $summary .= '  (token from '.$recipe['csrf_from'].')';
        }

        return $summary;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $name = '' === $prefix ? (string) $key : $prefix.'['.$key.']';
            $flat += \is_array($value) ? self::flatten($value, $name) : [$name => $value];
        }

        return $flat;
    }

    private static function controllerMethod(?string $controller): ?\ReflectionMethod
    {
        if (null === $controller) {
            return null;
        }
        [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return new \ReflectionMethod($class, $method);
    }

    /**
     * @param list<\ReflectionAttribute<object>> $attributes
     *
     * @return list<string>
     */
    private static function attributes(array $attributes): array
    {
        return array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->getName().'('.json_encode($attribute->getArguments(), \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR).')', $attributes);
    }
}
