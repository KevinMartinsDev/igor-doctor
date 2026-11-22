<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use IgorDoctor\Runtime\Snapshot\Snapshot;

/**
 * What one probe left behind: the state before any request (index 0) and after each sending
 * (1, 2, 3), the marker of each sending, and the sendings whose Request was still alive once
 * the services were reset and the garbage collected.
 */
final readonly class ProbeRun
{
    /**
     * @param array<int, string>   $markers   sending => marker
     * @param array<int, Snapshot> $snapshots one per sending, from 1; 0 holds only the state of the PHP process before the first
     * @param array<int, int>      $statuses  sending => HTTP status
     * @param list<int>            $zombies   sendings whose Request outlived it
     * @param array<int, string>   $answers   sending => body of the answer
     * @param array<int, ?string>  $identities sending => the user it was logged in as, null when anonymous
     * @param array<int, ?string>  $controllers sending => the controller it reached, null when none was called
     * @param array<int, ?string>  $reasons    sending => why it did not succeed (a redirect, a validation error)
     */
    public function __construct(
        public Probe $probe,
        public array $markers,
        public array $snapshots,
        public array $statuses,
        public array $zombies = [],
        public array $answers = [],
        public array $identities = [],
        public array $controllers = [],
        public array $reasons = [],
    ) {
    }

    public function sendings(): int
    {
        return \count($this->markers);
    }
}
