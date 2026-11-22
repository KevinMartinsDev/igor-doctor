<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

final readonly class DoctorReport
{
    /**
     * @param list<Finding>         $initial    findings of the first audit
     * @param list<Finding>         $remaining  findings of the last audit
     * @param array<string, Outcome> $outcomes  last outcome of each finding worked on, by finding id
     */
    public function __construct(
        public array $initial,
        public array $remaining,
        public array $outcomes,
        public int $iterations,
    ) {
    }

    /**
     * Findings of the first audit that the last audit no longer reports.
     *
     * @return list<Finding>
     */
    public function fixed(): array
    {
        $remaining = array_count_values(array_map(static fn (Finding $f): string => $f->id(), $this->remaining));
        $fixed = [];
        foreach ($this->initial as $finding) {
            if (($remaining[$finding->id()] ?? 0) > 0) {
                --$remaining[$finding->id()];
            } else {
                $fixed[] = $finding;
            }
        }

        return $fixed;
    }

    /**
     * Findings still reported whose only fix would change behaviour.
     *
     * @return list<Outcome>
     */
    public function flagged(): array
    {
        return $this->stillReported(Outcome::BEHAVIOUR_CHANGING);
    }

    /**
     * Findings still reported whose patch was rejected, rolled back or never proposed.
     *
     * @return list<Outcome>
     */
    public function notApplied(): array
    {
        return $this->stillReported(Outcome::NOT_APPLIED);
    }

    public function outcomeOf(Finding $finding): ?Outcome
    {
        return $this->outcomes[$finding->id()] ?? null;
    }

    /**
     * @return list<Finding>
     */
    public function remainingBlocking(): array
    {
        return array_values(array_filter($this->remaining, static fn (Finding $f): bool => $f->isBlocking()));
    }

    public function isClean(): bool
    {
        return [] === $this->remainingBlocking();
    }

    /**
     * A finding fixed by another patch keeps the outcome of its own attempt: it is not listed.
     *
     * @return list<Outcome>
     */
    private function stillReported(string $status): array
    {
        $remaining = array_map(static fn (Finding $f): string => $f->id(), $this->remaining);

        return array_values(array_filter(
            $this->outcomes,
            static fn (Outcome $o): bool => $status === $o->status && \in_array($o->finding->id(), $remaining, true),
        ));
    }
}
