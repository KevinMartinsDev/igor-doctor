<?php

declare(strict_types=1);

// Router of the PHP built-in server started by igor:worker-scan --dashboard: the page, and the events the
// scan appended to the file named by IGOR_SCAN_EVENTS, read from a byte offset.

require_once __DIR__.'/../../src/Audit/ScanEvents.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);

if ('/events' === $path) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(IgorDoctor\Runtime\Audit\ScanEvents::read((string) getenv('IGOR_SCAN_EVENTS'), max(0, (int) ($_GET['from'] ?? 0))), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

    return true;
}

// "Unblock with AI": runs the scan command on one route, in the background, writing to the same events. The
// route must be one the scan listed, one run at a time, and the custom header keeps other sites from posting
// here (a cross-origin request with it needs a preflight that this server never grants).
if ('/unblock' === $path) {
    header('Content-Type: application/json');
    $events = (string) getenv('IGOR_SCAN_EVENTS');
    $project = (string) getenv('IGOR_PROJECT_DIR');
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '') || '1' !== ($_SERVER['HTTP_X_IGOR_DASHBOARD'] ?? '')) {
        http_response_code(403);
        echo '{"error":"forbidden"}';

        return true;
    }
    if ('' === $project || !is_file($project.'/bin/console')) {
        http_response_code(500);
        echo '{"error":"IGOR_PROJECT_DIR does not point to the application"}';

        return true;
    }
    $ask = (string) getenv('IGOR_AI_COMMAND');
    if ('' === $ask || !is_executable($ask)) {
        http_response_code(503);
        echo '{"error":"set IGOR_AI_COMMAND to the doctor\'s bin/ask, then start the scan again: the model is asked through Symfony AI"}';

        return true;
    }

    $route = (string) ($_GET['route'] ?? '');
    $known = [];
    $users = [];
    $from = 0;
    do {
        $chunk = IgorDoctor\Runtime\Audit\ScanEvents::read($events, $from);
        $from = $chunk['next'];
        foreach ($chunk['events'] as $event) {
            if ('routes' === $event['t']) {
                foreach ($event['routes'] as $definition) {
                    $known[$definition['name']] = true;
                }
            } elseif ('scan' === $event['t']) {
                $users = array_values(array_filter($event['users'] ?? [], 'is_string'));
            }
        }
    } while ([] !== $chunk['events']);
    if (!isset($known[$route])) {
        http_response_code(404);
        echo '{"error":"unknown route"}';

        return true;
    }

    $lock = sys_get_temp_dir().'/igor-unblock-'.md5($events).'.lock';
    if (is_file($lock) && time() - (int) filemtime($lock) < 900) {
        http_response_code(409);
        echo '{"error":"another route is being unblocked"}';

        return true;
    }
    touch($lock);

    $command = [\PHP_BINARY, '-d', 'display_errors=stderr', 'bin/console', 'igor:worker-scan', '--unblock='.$route, '--events='.$events, '--no-interaction'];
    foreach ($users as $user) {
        $command[] = '--user='.$user;
    }
    // The whole group goes to the background with its output detached, so that exec() returns at once.
    exec(sprintf('(cd %s && %s; rm -f %s) > /dev/null 2>&1 < /dev/null &', escapeshellarg($project), implode(' ', array_map('escapeshellarg', $command)), escapeshellarg($lock)));
    echo json_encode(['started' => $route]);

    return true;
}

// "Search upstream": is a leak found in a dependency already fixed? Only public metadata is fetched (the
// package name, its versions, the class's file in the package's repository and the issues that name it); no code
// or data of the application leaves the machine.
if ('/upstream' === $path) {
    header('Content-Type: application/json');
    if ('1' !== ($_SERVER['HTTP_X_IGOR_DASHBOARD'] ?? '')) {
        http_response_code(403);
        echo '{"error":"forbidden"}';

        return true;
    }
    $package = (string) ($_GET['package'] ?? '');
    $source = (string) ($_GET['source'] ?? '');
    if (1 !== preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $package) || ('' !== $source && 1 !== preg_match('#^vendor/[\w./-]+\.php$#', $source))) {
        http_response_code(400);
        echo '{"error":"invalid package or file"}';

        return true;
    }
    echo json_encode(igor_upstream($package, (string) ($_GET['version'] ?? ''), $source, (string) ($_GET['class'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return true;
}

if (\in_array($path, ['/igor-php.png', '/igor-face.png'], true)) {
    header('Content-Type: image/png');
    header('Cache-Control: max-age=3600');
    readfile(__DIR__.$path);

    return true;
}

if ('/' === $path) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/index.html');

    return true;
}

http_response_code(404);

return true;

function igor_fetch(string $url, bool $json = true): mixed
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['User-Agent: igor-doctor-dashboard', 'Accept: application/vnd.github+json'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if (!is_string($body) || $status >= 400 || 0 === $status) {
        return null;
    }

    return $json ? json_decode($body, true) : $body;
}

/**
 * @return array<string, mixed>
 */
function igor_upstream(string $package, string $installed, string $source, string $class): array
{
    $result = ['package' => $package, 'installed' => $installed, 'latest' => null, 'repository' => null, 'file' => null, 'issues' => [], 'verdict' => null];

    $meta = igor_fetch('https://repo.packagist.org/p2/'.$package.'.json');
    $versions = is_array($meta['packages'][$package] ?? null) ? $meta['packages'][$package] : [];
    $stable = array_values(array_filter($versions, static fn (array $v): bool => !str_contains((string) ($v['version_normalized'] ?? '-'), '-')));
    $latest = $stable[0] ?? $versions[0] ?? null;
    $result['latest'] = $latest['version'] ?? null;
    $repositoryUrl = (string) ($latest['source']['url'] ?? '');
    if (1 !== preg_match('#github\.com[/:]([^/]+)/([^/.]+)#', $repositoryUrl, $repo)) {
        $result['verdict'] = null === $latest ? 'The package is not on Packagist: check its repository by hand.' : 'The package is not hosted on GitHub: compare the versions by hand.';

        return $result;
    }
    [$owner, $name] = [$repo[1], $repo[2]];
    $result['repository'] = "https://github.com/$owner/$name";

    // Split repositories are read-only: their issues live in the main repository.
    $issuesRepo = match (true) {
        'symfony' === $owner && 'symfony' !== $name => 'symfony/symfony',
        'api-platform' === $owner && 'core' !== $name => 'api-platform/core',
        'illuminate' === $owner => 'laravel/framework',
        default => "$owner/$name",
    };

    if ('' !== $source) {
        $path = preg_replace('#^vendor/[^/]+/[^/]+/#', '', $source);
        $at = static fn (?string $ref): ?string => null === $ref ? null : igor_fetch("https://raw.githubusercontent.com/$owner/$name/".rawurlencode($ref)."/$path", false);
        $mine = $at('' !== $installed ? $installed : null);
        $newest = $at($result['latest']);
        $head = $at('HEAD');
        $compare = static function (?string $from, ?string $to): ?array {
            if (null === $from || null === $to) {
                return null;
            }
            $a = explode("\n", $from);
            $b = explode("\n", $to);
            $added = array_values(array_diff($b, $a));
            $signals = array_values(array_filter(['ResetInterface', 'function reset(', 'WeakMap', 'WeakReference', 'kernel.reset', 'unset('], static fn (string $s): bool => str_contains(implode("\n", $added), $s)));

            return ['changed' => $from !== $to, 'added' => count($added), 'removed' => count(array_diff($a, $b)), 'signals' => $signals];
        };
        $result['file'] = [
            'path' => $path,
            'found' => null !== $mine,
            'latest' => $compare($mine, $newest),
            'head' => $compare($mine, $head),
            'url' => "https://github.com/$owner/$name/blob/HEAD/$path",
            'compare' => '' !== $installed && null !== $result['latest'] && $installed !== $result['latest'] ? "https://github.com/$owner/$name/compare/$installed...{$result['latest']}" : null,
        ];
    }

    if ('' !== $class) {
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        // Best match on the class and the words of a state kept between requests; those titles come first.
        $search = igor_fetch('https://api.github.com/search/issues?per_page=8&q='.rawurlencode("repo:$issuesRepo $short stateful OR leak OR reset OR worker OR reentrant"));
        foreach ($search['items'] ?? [] as $item) {
            $result['issues'][] = ['number' => $item['number'], 'title' => $item['title'], 'state' => $item['state'], 'pull' => isset($item['pull_request']), 'url' => $item['html_url'], 'updated' => substr((string) $item['updated_at'], 0, 10)];
        }
        usort($result['issues'], static fn (array $a, array $b): int => (int) preg_match('/stateful|stateless|leak|reset|worker|reentrant|memory/i', $b['title']) <=> (int) preg_match('/stateful|stateless|leak|reset|worker|reentrant|memory/i', $a['title']));
        $result['issues_search'] = "https://github.com/$issuesRepo/issues?q=".rawurlencode($short);
    }

    $latestChanged = $result['file']['latest']['changed'] ?? null;
    $headChanged = $result['file']['head']['changed'] ?? null;
    $result['verdict'] = match (true) {
        null === $result['file'] || !$result['file']['found'] => 'The file could not be compared: read the issues below, or the changelog.',
        $installed === $result['latest'] && true === $headChanged => 'You run the latest release; the file changed on the development branch since: a fix may be on its way.',
        $installed === $result['latest'] => 'You run the latest release and the file is unchanged upstream: probably not fixed yet. Check the issues, or report it.',
        true === $latestChanged => sprintf('The file changed in %s: upgrade and scan again to see whether the leak is gone.', $result['latest']),
        true === $headChanged => sprintf('Unchanged in %s, but changed on the development branch: a fix may be on its way.', $result['latest']),
        default => sprintf('Unchanged in %s and on the development branch: probably not fixed upstream. Check the issues, or report it.', $result['latest']),
    };

    return $result;
}
