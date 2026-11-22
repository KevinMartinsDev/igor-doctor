<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * Proves that a value kept between requests is read again: the same route is requested once more without any
 * parameter, by the worker the probe used and by a fresh worker. A worker that keeps nothing answers both the
 * same way; when the answers differ, what the earlier requests left changed the next one. That is what makes a
 * kept value an exposure (GHSA-xmq6-3c6q-fr8m: a filter sent by one visitor applied to the next visitor's list).
 *
 * Values that change from one answer to the next on their own (tokens, identifiers, dates) are blanked first.
 */
final class StaleReadOracle
{
    private const VOLATILE = [
        '/igor\d+x[0-9a-f]{10}/' => '#marker',
        '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?/' => '#date',
        '/[A-Za-z0-9+\/_=-]{32,}/' => '#token',
        '/\b\d{9,}\b/' => '#number',
    ];

    /**
     * @param array{int, string} $used  status and body of the worker the probe used
     * @param array{int, string} $fresh status and body of a fresh worker
     *
     * @return string|null what differs, or null when the answers are the same
     */
    public static function difference(array $used, array $fresh): ?string
    {
        [$usedStatus, $usedBody] = $used;
        [$freshStatus, $freshBody] = $fresh;
        if ($usedStatus !== $freshStatus) {
            return \sprintf('a request without parameters answers %d on this worker and %d on a fresh one', $usedStatus, $freshStatus);
        }

        $a = self::normalize($usedBody);
        $b = self::normalize($freshBody);
        if ($a === $b) {
            return null;
        }

        $at = strspn($a ^ $b, "\0");
        $excerpt = static fn (string $body): string => trim((string) preg_replace('/\s+/', ' ', mb_strcut($body, max(0, $at - 30), 90)));

        return \sprintf('a request without parameters answers differently on this worker ("…%s…") than on a fresh one ("…%s…")', $excerpt($a), $excerpt($b));
    }

    private static function normalize(string $body): string
    {
        return (string) preg_replace(array_keys(self::VOLATILE), array_values(self::VOLATILE), $body);
    }
}
