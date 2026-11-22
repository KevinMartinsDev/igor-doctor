<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use Symfony\Component\HttpFoundation\Request;

/**
 * A request the auditor sends several times. Each `{marker}` in the URI, the headers or the body
 * becomes a value unique to one sending, so that finding it later in the shared state proves
 * that it came from an earlier request. `{a|b}` picks one value per sending, in turn: a boolean
 * or an enum parameter cannot carry a marker, but it can change between two sendings.
 *
 * A probe built from a form has the page of the form: before each sending the page is requested in the
 * same session, and the hidden fields it renders (a CSRF token) replace those of the body. A body sent
 * with the urlencoded content type is decoded into the request parameters, as PHP does.
 */
final readonly class Probe
{
    public const MARKER = '{marker}';
    public const FORM_TYPE = 'application/x-www-form-urlencoded';

    private const CHOICE = '/\{([^{}|]*(?:\|[^{}|]*)+)\}/';

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(
        public string $name,
        public string $uri,
        public string $method = 'GET',
        public array $headers = [],
        public ?string $body = null,
        public array $cookies = [],
        public ?string $formPage = null,
    ) {
    }

    /**
     * @return array{name: string, uri: string, method: string, headers: array<string, string>, body: ?string, cookies: array<string, string>, form_page: ?string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'uri' => $this->uri, 'method' => $this->method, 'headers' => $this->headers, 'body' => $this->body, 'cookies' => $this->cookies, 'form_page' => $this->formPage];
    }

    /**
     * @param array{name: string, uri: string, method?: string, headers?: array<string, string>, body?: ?string, cookies?: array<string, string>, form_page?: ?string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['name'], $data['uri'], $data['method'] ?? 'GET', $data['headers'] ?? [], $data['body'] ?? null, $data['cookies'] ?? [], $data['form_page'] ?? null);
    }

    /**
     * The same route without any parameter, header or cookie of the probe, to see whether the state the probe left
     * changes the answer to a plain request (see StaleReadOracle). Only a GET probe has one.
     */
    public function neutral(): ?self
    {
        if ('GET' !== $this->method) {
            return null;
        }
        $path = explode('?', $this->uri, 2)[0];
        $headers = array_intersect_key($this->headers, ['Accept' => true]);

        return new self($this->name.' (neutral)', '' === $path ? '/' : $path, 'GET', $headers);
    }

    /**
     * @param array<string, string> $hidden fields that replace those of the body (the hidden fields of the form's page)
     */
    public function request(string $marker, int $sending = 1, array $hidden = []): Request
    {
        $body = null === $this->body ? null : self::fill($this->body, $marker, $sending);
        $parameters = [];
        if (null !== $body && self::FORM_TYPE === ($this->headers['Content-Type'] ?? null)) {
            parse_str($body, $parameters);
            if ([] !== $hidden) {
                parse_str(http_build_query($hidden), $fresh);
                $parameters = array_replace_recursive($parameters, $fresh);
            }
            $body = null;
        }

        $request = Request::create(
            self::fill($this->uri, rawurlencode($marker), $sending),
            $this->method,
            $parameters,
            cookies: array_map(static fn (string $value): string => self::fill($value, $marker, $sending), $this->cookies),
            content: $body,
        );

        foreach ($this->headers as $name => $value) {
            $request->headers->set($name, self::fill($value, $marker, $sending));
        }

        return $request;
    }

    /**
     * @return list<string> the value each `{a|b}` group picks for this sending
     */
    public function choices(int $sending): array
    {
        preg_match_all(self::CHOICE, $this->uri.' '.implode(' ', $this->headers).' '.implode(' ', $this->cookies).' '.$this->body, $groups);

        return array_map(static function (string $group) use ($sending): string {
            $choices = explode('|', $group);

            return $choices[($sending - 1) % \count($choices)];
        }, $groups[1]);
    }

    private static function fill(string $template, string $marker, int $sending): string
    {
        $template = (string) preg_replace_callback(
            self::CHOICE,
            static function (array $match) use ($sending): string {
                $choices = explode('|', $match[1]);

                return $choices[($sending - 1) % \count($choices)];
            },
            $template,
        );

        return str_replace(self::MARKER, $marker, $template);
    }
}
