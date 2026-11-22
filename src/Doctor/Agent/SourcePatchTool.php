<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

use IgorDoctor\Doctor\Patch\PatchPolicy;
use IgorDoctor\Doctor\Patch\PatchRejected;

/**
 * The only path from the model to a file. The model runs with every built-in tool disabled and
 * answers with a JSON object of search/replace edits; this class applies them in memory to the
 * current file and checks the result against the write policy. It never writes: DoctorLoop
 * applies the proposal.
 *
 * Edits instead of a whole file: the answer stays small for a long file (a whole 600-line
 * controller takes the model minutes to write back), and the lines the model does not touch
 * cannot be damaged on the way.
 */
final class SourcePatchTool
{
    /**
     * @param bool $allowBehaviourChange keep the edits of an answer flagged as behaviour-changing
     */
    public function __construct(
        private readonly PatchPolicy $policy,
        private readonly bool $allowBehaviourChange = false,
    ) {
    }

    /**
     * @throws PatchRejected when the answer is not a valid patch or breaks the write policy
     */
    public function check(string $answer): Proposal
    {
        $data = self::decode($answer);

        $justification = \is_string($data['justification'] ?? null) ? trim($data['justification']) : '';
        if ('' === $justification) {
            throw new PatchRejected('The answer has no "justification".');
        }

        $behaviourChanging = true === ($data['behaviour_changing'] ?? false);
        if ($behaviourChanging && (!$this->allowBehaviourChange || [] === ($data['edits'] ?? []))) {
            return Proposal::behaviourChanging($justification);
        }

        if (!\is_string($data['file'] ?? null) || !\is_array($data['edits'] ?? null) || [] === $data['edits']) {
            throw new PatchRejected('The answer needs a string "file" and a non-empty list of "edits".');
        }

        $absolute = $this->policy->resolveWritable($data['file']);
        $original = is_file($absolute) ? (string) file_get_contents($absolute) : null;
        $patched = self::applyEdits($original, $data['edits']);
        $this->policy->assertAcceptable($data['file'], $original ?? '', $patched);

        return Proposal::patch(new Patch($data['file'], $patched, $justification), $behaviourChanging);
    }

    /**
     * @param array<mixed> $edits
     */
    private static function applyEdits(?string $content, array $edits): string
    {
        if (null === $content) {
            // A new file (a test): one edit with an empty "search" gives its whole content.
            if (1 !== \count($edits) || '' !== ($edits[0]['search'] ?? null) || !\is_string($edits[0]['replace'] ?? null)) {
                throw new PatchRejected('The file does not exist: create it with one edit whose "search" is empty.');
            }

            return $edits[0]['replace'];
        }

        foreach (array_values($edits) as $index => $edit) {
            $number = $index + 1;
            if (!\is_array($edit) || !\is_string($edit['search'] ?? null) || !\is_string($edit['replace'] ?? null)) {
                throw new PatchRejected(\sprintf('Edit %d needs a string "search" and a string "replace".', $number));
            }
            if ('' === $edit['search']) {
                throw new PatchRejected(\sprintf('Edit %d has an empty "search": quote the exact lines to change.', $number));
            }

            $occurrences = substr_count($content, $edit['search']);
            if (1 !== $occurrences) {
                throw new PatchRejected(\sprintf(
                    0 === $occurrences
                        ? 'The "search" of edit %d is not in the file: copy the lines exactly, with their indentation and without the line numbers.'
                        : 'The "search" of edit %d appears %d times in the file: include more surrounding lines so it is unique.',
                    $number,
                    $occurrences,
                ));
            }

            $position = strpos($content, $edit['search']);
            $content = substr_replace($content, $edit['replace'], (int) $position, \strlen($edit['search']));
        }

        return $content;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $answer): array
    {
        $json = trim($answer);
        // Models sometimes wrap the object in a Markdown fence despite the instructions.
        if (1 === preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $json, $matches)) {
            $json = $matches[1];
        }

        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PatchRejected('The answer is not a JSON object: '.$e->getMessage().'.');
        }
        if (!\is_array($data) || array_is_list($data)) {
            throw new PatchRejected('The answer is not a JSON object.');
        }

        return $data;
    }
}
