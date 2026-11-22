<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * What the scan is doing, one JSON object per line, appended to a file that the dashboard reads while the
 * scan runs. The parent process and its children append to the same file, each line in one locked write.
 *
 * Types: `scan` (start), `routes` (the router's routes), `page` (a page visited to find links and forms),
 * `form` (a form found), `probes` (the probes to send), `probe` (a probe starts), `request` (one request sent),
 * `snapshot` (the state after a sending), `finding`, `crash` (a request ended the process), `error`, `done`.
 * Without a file, nothing is written.
 */
final class ScanEvents
{
    public function __construct(private readonly ?string $file = null)
    {
    }

    public function enabled(): bool
    {
        return null !== $this->file;
    }

    public function file(): ?string
    {
        return $this->file;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function emit(string $type, array $data = []): void
    {
        if (null === $this->file) {
            return;
        }

        $line = json_encode(['t' => $type, 'at' => round(microtime(true), 3)] + $data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        @file_put_contents($this->file, $line."\n", \FILE_APPEND | \LOCK_EX);
    }

    /**
     * The complete lines written from the given byte, and the byte to read from next: a line still being
     * written is left for the next read.
     *
     * @return array{events: list<array<string, mixed>>, next: int}
     */
    public static function read(string $file, int $from): array
    {
        $size = @filesize($file);
        if (false === $size || $from >= $size) {
            return ['events' => [], 'next' => min($from, false === $size ? 0 : $size)];
        }

        $handle = @fopen($file, 'r');
        if (false === $handle) {
            return ['events' => [], 'next' => $from];
        }
        fseek($handle, $from);
        $chunk = (string) fread($handle, min($size - $from, 1 << 20));
        fclose($handle);

        $end = strrpos($chunk, "\n");
        if (false === $end) {
            return ['events' => [], 'next' => $from];
        }

        $events = [];
        foreach (explode("\n", substr($chunk, 0, $end)) as $line) {
            $event = json_decode($line, true);
            if (\is_array($event)) {
                $events[] = $event;
            }
        }

        return ['events' => $events, 'next' => $from + $end + 1];
    }
}
