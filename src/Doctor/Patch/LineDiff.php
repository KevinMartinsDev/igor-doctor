<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Patch;

/**
 * Line-based comparison of two versions of a file.
 */
final class LineDiff
{
    /**
     * Number of lines of $old that are not kept in $new (longest common subsequence of lines).
     */
    public static function removedLines(string $old, string $new): int
    {
        $a = self::splitLines($old);
        $b = self::splitLines($new);

        return \count($a) - self::lcsLength($a, $b);
    }

    /**
     * Splits on CRLF, LF and CR only. PCRE's \R would also break on the byte 0x85 (NEL), which
     * ends many UTF-8 characters such as "⬅" (e2 ac 85) and "✅" (e2 9c 85), and cut them in two.
     *
     * @return list<string>
     */
    public static function splitLines(string $text): array
    {
        return '' === $text ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim($text, "\r\n")));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function lcsLength(array $a, array $b): int
    {
        $previous = array_fill(0, \count($b) + 1, 0);
        foreach ($a as $lineA) {
            $current = [0];
            foreach ($b as $j => $lineB) {
                $current[$j + 1] = $lineA === $lineB ? $previous[$j] + 1 : max($previous[$j + 1], $current[$j]);
            }
            $previous = $current;
        }

        return $previous[\count($b)];
    }
}
