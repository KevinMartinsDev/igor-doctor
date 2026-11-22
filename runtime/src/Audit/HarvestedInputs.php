<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

final readonly class HarvestedInputs
{
    /**
     * @param array<string, string> $query   name => value template
     * @param array<string, string> $cookies name => value template
     * @param array<string, string> $headers name => value template
     */
    public function __construct(
        public array $query = [],
        public array $cookies = [],
        public array $headers = [],
    ) {
    }

    public function applyTo(Probe $probe): Probe
    {
        $uri = $probe->uri;
        $query = [];
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
        foreach ($this->query as $name => $value) {
            if (!\array_key_exists($name, $query)) {
                $uri .= (str_contains($uri, '?') ? '&' : '?').rawurlencode($name).'='.$value;
            }
        }

        return new Probe($probe->name, $uri, $probe->method, [...$this->headers, ...$probe->headers], $probe->body, [...$this->cookies, ...$probe->cookies], $probe->formPage);
    }

    /**
     * @return array{query: list<string>, cookies: list<string>, headers: list<string>}
     */
    public function names(): array
    {
        return ['query' => array_keys($this->query), 'cookies' => array_keys($this->cookies), 'headers' => array_keys($this->headers)];
    }
}
