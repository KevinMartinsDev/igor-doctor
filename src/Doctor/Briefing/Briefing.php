<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Briefing;

use IgorDoctor\Doctor\Finding;
use IgorDoctor\Doctor\Patch\LineDiff;
use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\Patch\PatchRejected;

/**
 * What the agent needs to know about one finding beyond its file: Igor's reference of the rule, the
 * doctor's notes on it, and the files of the services the finding's line uses. Built in code, so the
 * agent keeps running without any tool, and only what concerns the finding reaches the prompt.
 *
 * A related file is shown only when the write policy would let the doctor patch it: never vendor/,
 * var/, configuration, hidden files or anything outside the target.
 */
final class Briefing
{
    private const MAX_RELATED_LINES = 300;

    public function __construct(
        private readonly RuleReference $reference,
        private readonly SkillLibrary $skills,
        private readonly ?ServiceContext $context = null,
        private readonly ?PatchPolicy $policy = null,
    ) {
    }

    public function for(Finding $finding, bool $allowBehaviourChange = false): string
    {
        $sections = [];

        if (null !== $reference = $this->reference->rule($finding->ruleId)) {
            $sections[] = \sprintf("Igor's reference of the rule %s:\n%s", $finding->ruleId, self::withoutTitle($reference));
        }
        if (null !== $skill = $this->skills->skill($finding->ruleId, $allowBehaviourChange)) {
            $sections[] = \sprintf("Doctor notes on %s (what a fix needs to pass the re-audit):\n%s", $finding->ruleId, $skill);
        }

        foreach ($this->relatedFiles($finding) as $path => $content) {
            $sections[] = \sprintf(
                "Related file %s, used by the line of the finding (line numbers are not part of the file). A fix that belongs\nin this file may patch it instead of %s, one file per answer:\n%s",
                $path,
                $finding->file,
                $content,
            );
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return array<string, string> numbered content of each related file, by relative path
     */
    private function relatedFiles(Finding $finding): array
    {
        if (null === $this->context || null === $this->policy) {
            return [];
        }

        $files = [];
        foreach ($this->context->relatedFiles($finding) as $path) {
            try {
                $absolute = $this->policy->resolveWritable($path);
            } catch (PatchRejected) {
                continue;
            }
            if ($path === $finding->file || !is_file($absolute)) {
                continue;
            }

            $lines = LineDiff::splitLines((string) file_get_contents($absolute));
            $numbered = [];
            foreach (\array_slice($lines, 0, self::MAX_RELATED_LINES) as $index => $line) {
                $numbered[] = \sprintf('%4d| %s', $index + 1, $line);
            }
            if (\count($lines) > self::MAX_RELATED_LINES) {
                $numbered[] = \sprintf('(%d more lines not shown)', \count($lines) - self::MAX_RELATED_LINES);
            }
            $files[$this->policy->relativize($absolute)] = implode("\n", $numbered);
        }

        return $files;
    }

    /**
     * The reference starts with the rule id as a title, which the section header already gives.
     */
    private static function withoutTitle(string $markdown): string
    {
        return trim((string) preg_replace('/\A#[^\n]*\n/', '', trim($markdown)));
    }
}
