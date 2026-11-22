<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * Turns the forms of an HTML page into probes, without a model: each field gets a value its type
 * accepts, with a marker where the type allows one, and the choices of a select or a radio group
 * alternate (`{a|b|a}`). Hidden fields keep their value: a CSRF token is read again from the page
 * before each sending, in the same session (see Probe::$formPage).
 */
final class FormExtractor
{
    private const SKIPPED_TYPES = ['submit', 'button', 'reset', 'image', 'file'];

    /**
     * @return list<array{action: string, method: string, body: string}> the action is a same-site path, the
     *                                                                   body an urlencoded template
     */
    public static function forms(string $html, string $pagePath): array
    {
        $forms = [];
        foreach (self::document($html)?->getElementsByTagName('form') ?? [] as $form) {
            $action = self::action($form->getAttribute('action'), $pagePath);
            if (null === $action) {
                continue;
            }

            $pairs = [];
            foreach (self::fields($form) as [$name, $value]) {
                $pairs[] = rawurlencode($name).'='.$value;
            }
            if ([] === $pairs) {
                continue;
            }

            $forms[] = [
                'action' => $action,
                'method' => 'post' === strtolower($form->getAttribute('method')) ? 'POST' : 'GET',
                'body' => implode('&', $pairs),
            ];
        }

        return $forms;
    }

    /**
     * @return array<string, string> the hidden fields of the page's form that posts to the given path
     */
    public static function hiddenFields(string $html, string $actionPath, string $pagePath): array
    {
        $actionPath = (string) parse_url($actionPath, \PHP_URL_PATH);
        foreach (self::document($html)?->getElementsByTagName('form') ?? [] as $form) {
            if ($actionPath !== (string) parse_url((string) self::action($form->getAttribute('action'), $pagePath), \PHP_URL_PATH)) {
                continue;
            }
            $hidden = [];
            foreach ($form->getElementsByTagName('input') as $input) {
                if ('hidden' === strtolower($input->getAttribute('type')) && '' !== $input->getAttribute('name') && !$input->hasAttribute('disabled')) {
                    $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
                }
            }

            return $hidden;
        }

        return [];
    }

    /**
     * @return iterable<array{string, string}> name and urlencoded value template of each submitted field
     */
    private static function fields(\DOMElement $form): iterable
    {
        $radios = [];
        foreach (self::controls($form) as $control) {
            $name = $control->getAttribute('name');
            if ('' === $name || $control->hasAttribute('disabled')) {
                continue;
            }

            if ('select' === $control->tagName) {
                $options = [];
                foreach ($control->getElementsByTagName('option') as $option) {
                    $value = $option->hasAttribute('value') ? $option->getAttribute('value') : trim($option->textContent);
                    if ('' !== $value && !$option->hasAttribute('disabled')) {
                        $options[] = $value;
                    }
                }
                if ([] !== $options) {
                    yield [$name, self::choice($options)];
                }
                continue;
            }

            if ('textarea' === $control->tagName) {
                yield [$name, Probe::MARKER];
                continue;
            }

            $type = strtolower($control->getAttribute('type') ?: 'text');
            if (\in_array($type, self::SKIPPED_TYPES, true)) {
                continue;
            }
            $value = $control->getAttribute('value');

            if ('radio' === $type) {
                $radios[$name][] = '' !== $value ? $value : 'on';
                continue;
            }
            if ('checkbox' === $type) {
                if ($control->hasAttribute('checked')) {
                    yield [$name, rawurlencode('' !== $value ? $value : 'on')];
                }
                continue;
            }

            yield [$name, self::value($type, $value)];
        }

        foreach ($radios as $name => $values) {
            yield [$name, self::choice($values)];
        }
    }

    /**
     * @return iterable<\DOMElement>
     */
    private static function controls(\DOMElement $form): iterable
    {
        $xpath = new \DOMXPath($form->ownerDocument);
        foreach ($xpath->query('.//input | .//select | .//textarea', $form) ?: [] as $control) {
            if ($control instanceof \DOMElement) {
                yield $control;
            }
        }
    }

    /**
     * A value the input type accepts, with a marker in it when the type allows one.
     */
    private static function value(string $type, string $default): string
    {
        return match ($type) {
            'hidden' => rawurlencode($default),
            'email' => Probe::MARKER.'%40example.test',
            'url' => 'https%3A%2F%2Fexample.test%2F'.Probe::MARKER,
            'password' => 'Igor-'.Probe::MARKER.'-1A%21',
            'number', 'range' => is_numeric($default) ? rawurlencode($default) : '1',
            'date' => '2026-01-01',
            'datetime-local' => '2026-01-01T10%3A00',
            'time' => '10%3A00',
            'month' => '2026-01',
            'week' => '2026-W01',
            'tel' => '0600000000',
            'color' => '%23000000',
            default => Probe::MARKER,
        };
    }

    /**
     * @param list<string> $values
     */
    private static function choice(array $values): string
    {
        $values = array_values(array_unique($values));
        if (1 === \count($values)) {
            return rawurlencode($values[0]);
        }

        return '{'.rawurlencode($values[0]).'|'.rawurlencode($values[1]).'|'.rawurlencode($values[0]).'}';
    }

    private static function action(string $action, string $pagePath): ?string
    {
        $page = (string) parse_url($pagePath, \PHP_URL_PATH);
        if ('' === $action) {
            return '' !== $page ? $page : '/';
        }
        if (null !== parse_url($action, \PHP_URL_HOST) || str_starts_with($action, '//')) {
            return null;
        }
        $action = html_entity_decode($action, \ENT_QUOTES | \ENT_HTML5);
        if (str_starts_with($action, '?')) {
            return $page.$action;
        }
        if (str_starts_with($action, '/')) {
            return $action;
        }
        if (str_starts_with($action, '#') || str_contains($action, ':')) {
            return null;
        }

        return rtrim(\dirname($page.'x'), '/').'/'.$action;
    }

    private static function document(string $html): ?\DOMDocument
    {
        if (!str_contains($html, '<form')) {
            return null;
        }
        $document = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NONET | \LIBXML_NOWARNING | \LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return $loaded ? $document : null;
    }
}
