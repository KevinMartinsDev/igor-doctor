<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * Finds the names of the request inputs an application reads, so that the probes send them: the
 * query parameters, cookies and headers named in its PHP code and Twig templates. Only names are
 * collected; each one gets a marker, or alternatives when the code reads it as an int or a bool.
 */
final class InputHarvester
{
    private const PHP_QUERY = [
        '/->(?:query|request)->(get|getInt|getBoolean|getString|getAlpha|getAlnum|getDigits|has)\(\s*[\'"]([\w.\[\]-]+)[\'"]/',
        '/\$_(?:GET|REQUEST)\[\s*[\'"]([\w.-]+)[\'"]/',
        '/#\[MapQueryParameter[^\]]*\]\s*(?:\??[\w\\\\|]+\s+)?\$(\w+)/',
    ];

    private const TWIG_QUERY = '/app\.request\.(?:query|request)\.(?:get|getInt|getBoolean|getString)\(\s*[\'"]([\w.-]+)[\'"]/';

    private const COOKIES = [
        '/->cookies->(?:get|getInt|getBoolean|getString|has)\(\s*[\'"]([\w.-]+)[\'"]/',
        '/\$_COOKIE\[\s*[\'"]([\w.-]+)[\'"]/',
        '/app\.request\.cookies\.get\(\s*[\'"]([\w.-]+)[\'"]/',
    ];

    private const HEADERS = '/->headers->(?:get|has)\(\s*[\'"]([\w-]+)[\'"]/';

    /**
     * @param list<string> $directories the application's code and templates, `src/` and `templates/`
     */
    public function __construct(private readonly array $directories)
    {
    }

    public function harvest(): HarvestedInputs
    {
        $query = [];
        $cookies = [];
        $headers = [];

        foreach ($this->files() as $file) {
            $code = (string) file_get_contents($file);

            if (str_ends_with($file, '.php')) {
                if (preg_match_all(self::PHP_QUERY[0], $code, $matches, \PREG_SET_ORDER)) {
                    foreach ($matches as [, $getter, $name]) {
                        $query[$name] = self::value($getter, $query[$name] ?? null, $name);
                    }
                }
                foreach ([self::PHP_QUERY[1], self::PHP_QUERY[2]] as $pattern) {
                    preg_match_all($pattern, $code, $matches);
                    foreach ($matches[1] as $name) {
                        $query[$name] ??= self::byName($name);
                    }
                }
                preg_match_all(self::HEADERS, $code, $matches);
                foreach ($matches[1] as $name) {
                    $headers[$name] ??= Probe::MARKER;
                }
            } else {
                preg_match_all(self::TWIG_QUERY, $code, $matches);
                foreach ($matches[1] as $name) {
                    $query[$name] ??= self::byName($name);
                }
            }

            foreach (self::COOKIES as $pattern) {
                preg_match_all($pattern, $code, $matches);
                foreach ($matches[1] as $name) {
                    $cookies[$name] ??= self::byName($name);
                }
            }
        }

        foreach (['Accept', 'Accept-Language', 'Content-Type', 'Host', 'User-Agent', 'X-Requested-With'] as $standard) {
            unset($headers[$standard], $headers[strtolower($standard)]);
        }
        ksort($query);
        ksort($cookies);
        ksort($headers);

        return new HarvestedInputs($query, $cookies, $headers);
    }

    /**
     * A value the code rejects never reaches the state: inputs whose name says what they hold get
     * two valid values in turn instead of a marker.
     */
    private const VALUES_BY_NAME = [
        '/^(?:tz|timezone|time_?zone)$/i' => '{Europe/Paris|Asia/Tokyo|Europe/Paris}',
        '/^(?:locale|lang|language|_locale)$/i' => '{en|fr|en}',
        '/^(?:per_?page|limit|page_?size|size|items_?per_?page)$/i' => '{10|30|10}',
        '/^(?:page|p|offset)$/i' => '{1|2|1}',
        '/^(?:sort|order_?by|sort_?by)$/i' => '{id|title|id}',
        '/^(?:dir|direction|order)$/i' => '{asc|desc|asc}',
        '/^(?:currency)$/i' => '{EUR|USD|EUR}',
        '/^(?:theme|mode)$/i' => '{light|dark|light}',
    ];

    private static function value(string $getter, ?string $known, string $name = ''): string
    {
        $byName = self::byName($name);
        if (Probe::MARKER !== $byName) {
            return $byName;
        }

        return match ($getter) {
            'getInt', 'getDigits' => '{1|2|1}',
            'getBoolean' => '{0|1|0}',
            default => $known ?? Probe::MARKER,
        };
    }

    public static function byName(string $name): string
    {
        foreach (self::VALUES_BY_NAME as $pattern => $values) {
            if (1 === preg_match($pattern, $name)) {
                return $values;
            }
        }

        return Probe::MARKER;
    }

    /**
     * @return iterable<string>
     */
    private function files(): iterable
    {
        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && 1 === preg_match('/\.(php|twig)$/', $file->getFilename())) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
