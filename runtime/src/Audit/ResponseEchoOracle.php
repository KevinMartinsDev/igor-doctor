<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * Catches what the state diff cannot tell from a legitimate cache: an answer that shows another
 * request's input. The answer to the second sending, from the worker that has served the first, is
 * compared with the answer a fresh worker gives to the same request. A value only the first
 * sending sent (its marker, or an `{a|b}` value it picked) that appears in the first answer but not
 * in the second has leaked from one request to the next.
 */
final class ResponseEchoOracle
{
    private const MIN_LENGTH = 6;

    public function check(ProbeRun $run, string $freshAnswer): ?LeakFinding
    {
        $usedAnswer = $run->answers[2] ?? null;
        if (null === $usedAnswer) {
            return null;
        }

        $secondChoices = $run->probe->choices(2);
        $values = [$run->markers[1] ?? ''];
        foreach ($run->probe->choices(1) as $group => $value) {
            if ($value !== ($secondChoices[$group] ?? null)) {
                $values[] = $value;
            }
        }

        foreach ($values as $value) {
            if (\strlen($value) < self::MIN_LENGTH) {
                continue;
            }
            foreach (array_unique([$value, htmlspecialchars($value, \ENT_QUOTES), rawurlencode($value), json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: $value]) as $form) {
                $form = trim($form, '"');
                if (str_contains($usedAnswer, $form) && !str_contains($freshAnswer, $form)) {
                    return new LeakFinding(
                        LeakType::CrossRequestStateLeak,
                        true,
                        'response',
                        $run->probe->name,
                        [],
                        0,
                        \sprintf('the answer to request 2 shows "%s", sent only by request 1; a fresh worker does not show it', self::excerpt($value)),
                    );
                }
            }
        }

        return null;
    }

    private static function excerpt(string $value): string
    {
        return mb_strlen($value) > 80 ? mb_substr($value, 0, 80).'…' : $value;
    }
}
