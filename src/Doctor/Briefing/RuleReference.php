<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Briefing;

interface RuleReference
{
    /**
     * Reference of one rule, in Markdown, as the analyser documents it: why it leaks, how to fix it,
     * its known false positives. Null when the reference is not available: the briefing is best-effort.
     */
    public function rule(string $ruleId): ?string;
}
