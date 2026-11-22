<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

/**
 * What happened to one finding the doctor worked on.
 */
final readonly class Outcome
{
    public const FIXED = 'fixed';
    public const BEHAVIOUR_CHANGING = 'behaviour-changing';
    public const NOT_APPLIED = 'not-applied';

    public function __construct(
        public Finding $finding,
        public string $status,
        public string $reason,
        public ?string $patchedFile = null,
        public bool $behaviourChanged = false,
    ) {
    }
}
