<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

/**
 * What the agent answered for one finding: a patch to apply, or the reason why it applied none.
 */
final readonly class Proposal
{
    private function __construct(
        public ?Patch $patch,
        public bool $behaviourChanging,
        public string $reason,
    ) {
    }

    /**
     * @param bool $behaviourChanging the patch changes what the code does: applied only when the run allows it
     */
    public static function patch(Patch $patch, bool $behaviourChanging = false): self
    {
        return new self($patch, $behaviourChanging, $patch->justification);
    }

    /**
     * The only fix the agent sees would change what the code does, and it proposes none: reported, never applied.
     */
    public static function behaviourChanging(string $reason): self
    {
        return new self(null, true, $reason);
    }

    public static function none(string $reason): self
    {
        return new self(null, false, $reason);
    }
}
