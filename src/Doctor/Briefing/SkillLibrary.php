<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Briefing;

/**
 * The doctor's own notes on each rule, one Markdown file per rule id in resources/skills/.
 *
 * Igor's reference says how to fix a leak; these notes say what a fix needs to pass the doctor's
 * checks (the re-audit, the write policy) and when a finding is a feature to report, not to fix.
 *
 * A note may end with a "## When behaviour changes are allowed" section: it reaches the agent only
 * when the run allows behaviour changes (--allow-behaviour-change).
 */
final class SkillLibrary
{
    public const BEHAVIOUR_SECTION = '## When behaviour changes are allowed';

    public function __construct(
        private readonly string $directory,
    ) {
    }

    public static function bundled(): self
    {
        return new self(\dirname(__DIR__, 3).'/resources/skills');
    }

    public function skill(string $ruleId, bool $allowBehaviourChange = false): ?string
    {
        // The rule id names a file: only a plain identifier can.
        if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $ruleId)) {
            return null;
        }

        $file = $this->directory.'/'.$ruleId.'.md';
        if (!is_file($file)) {
            return null;
        }

        $content = trim((string) file_get_contents($file));
        $section = strpos($content, self::BEHAVIOUR_SECTION);
        if (false !== $section && !$allowBehaviourChange) {
            $content = trim(substr($content, 0, $section));
        }

        return '' === $content ? null : $content;
    }
}
