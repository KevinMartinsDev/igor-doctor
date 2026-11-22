<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use IgorDoctor\Runtime\Snapshot\Snapshot;
use IgorDoctor\Runtime\Snapshot\StateNode;
use IgorDoctor\Runtime\Snapshot\StateSnapshotter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns the snapshots of a probe into findings. The first sending is the warmup: what it
 * creates and then keeps unchanged (compiled routes, metadata built once) is ignored.
 *
 * - A marker found in the state after its request ended, or a value that follows the `{a|b}`
 *   values sent (false → true → false): certain cross-request leak.
 * - An array that grows after each sending: memory growth.
 * - A value that follows the logged-in user (A → B) and is still B's for the anonymous request that
 *   follows: certain cross-request leak, from one user to the next visitor.
 * - A value that changes after every sending: suspect cross-request leak. A cache that fills
 *   for the second variant of a probe and then stops changing is a warmup too.
 * - A Request alive after its sending: zombie request.
 */
final class LeakClassifier
{
    /**
     * @return list<LeakFinding>
     */
    public function classify(ProbeRun $run): array
    {
        $groups = [];
        $reported = [];

        foreach ($this->markerLeaks($run) as [$path, $node, $evidence]) {
            $normalized = $this->normalize($path, $run);
            $reported[$normalized] = true;
            $this->add($groups, LeakType::CrossRequestStateLeak, true, $node, $normalized, $evidence);
        }

        foreach ($this->handovers($run) as [$path, $node, $evidence]) {
            if (!isset($reported[$path])) {
                $reported[$path] = true;
                $this->add($groups, LeakType::CrossRequestStateLeak, true, $node, $path, $evidence);
            }
        }

        foreach ($this->processChanges($run) as [$path, $node, $evidence]) {
            if (!isset($reported[$path])) {
                $reported[$path] = true;
                $this->add($groups, LeakType::CrossRequestStateLeak, true, $node, $path, $evidence);
            }
        }

        foreach ($this->echoes($run) as [$path, $node, $evidence]) {
            if (!isset($reported[$path])) {
                $reported[$path] = true;
                $this->add($groups, LeakType::CrossRequestStateLeak, true, $node, $path, $evidence);
            }
        }

        foreach ($this->growth($run) as [$path, $node, $evidence]) {
            $this->add($groups, LeakType::MonotonicMemoryGrowth, true, $node, $this->normalize($path, $run), $evidence);
            $reported[$path] = true;
        }

        $certainOwners = [];
        foreach ($groups as $group) {
            $certainOwners[$group['owner']] = true;
        }

        foreach ($this->mutations($run) as [$path, $node, $evidence]) {
            if (!isset($reported[$path]) && !isset($certainOwners[$node->owner ?? 'unknown']) && !$this->isUnder($path, $reported)) {
                $this->add($groups, LeakType::CrossRequestStateLeak, false, $node, $path, $evidence);
            }
        }

        foreach ($run->zombies as $sending) {
            $holders = $this->requestHolders($run->snapshots[$sending]);
            if ([] === $holders) {
                $this->add($groups, LeakType::ZombieRequestRetention, false, new StateNode(Request::class, owner: StateSnapshotter::OUTSIDE), '?', \sprintf('the Request of sending %d is still alive, held by nothing the walk reached', $sending));
            }
            foreach ($holders as $path => $node) {
                $outside = StateSnapshotter::OUTSIDE === $node->owner;
                $holder = new StateNode($node->type, null, null, $node->value ?? self::root($path));
                $this->add($groups, LeakType::ZombieRequestRetention, !$outside, $holder, $this->normalize($path, $run), \sprintf('the Request of sending %d is still alive%s', $sending, $outside ? ', held outside the audited namespaces' : ''));
            }
        }

        return array_map(
            static fn (array $group): LeakFinding => new LeakFinding(
                $group['type'],
                $group['certain'],
                $group['owner'],
                $run->probe->name,
                \array_slice(array_keys($group['paths']), 0, LeakFinding::MAX_PATHS),
                \count($group['paths']),
                $group['evidence'],
            ),
            array_values($groups),
        );
    }

    /**
     * @return iterable<array{string, StateNode, string}>
     */
    private function markerLeaks(ProbeRun $run): iterable
    {
        $seen = [];
        foreach ($run->snapshots as $after => $snapshot) {
            foreach ($snapshot->nodes as $path => $node) {
                foreach ($run->markers as $sending => $marker) {
                    if ($sending > $after || isset($seen[$path])) {
                        continue;
                    }
                    if (str_contains($path, $marker) || (null !== $node->value && str_contains($node->value, $marker))) {
                        $seen[$path] = true;
                        yield [$path, $node, \sprintf('a value sent by request %d is still in the shared state after request %d', $sending, $after)];
                    }
                }
            }
        }
    }

    /**
     * @return iterable<array{string, StateNode, string}>
     */
    private function growth(ProbeRun $run): iterable
    {
        $last = $run->snapshots[$run->sendings()];
        foreach ($last->nodes as $path => $node) {
            if (!$node->isArray()) {
                continue;
            }

            $counts = [];
            for ($sending = 1; $sending <= $run->sendings(); ++$sending) {
                $counts[] = $run->snapshots[$sending]->get($path)?->count ?? -1;
            }

            if ($counts[0] >= 0 && $counts === array_unique($counts) && $counts === $this->sorted($counts)) {
                yield [$path, $node, \sprintf('grows on every request: %s entries', implode(' → ', $counts))];
            }
        }
    }

    /**
     * @return iterable<array{string, StateNode, string}>
     */
    private function mutations(ProbeRun $run): iterable
    {
        foreach ($run->snapshots[1]->nodes as $path => $node) {
            $nodes = $this->history($run, $path);
            if (null === $nodes) {
                continue;
            }

            for ($sending = 1; $sending < \count($nodes); ++$sending) {
                if ($nodes[$sending - 1]->sameAs($nodes[$sending])) {
                    continue 2;
                }
            }

            yield [$path, $nodes[1], \sprintf('changes after every request: %s', implode(' → ', array_map(static fn (StateNode $node): string => $node->describe(), $nodes)))];
        }
    }

    /**
     * The PHP process has no warmup: a timezone, a locale or an environment variable that a request
     * changes stays changed for every later request of the worker, even when it is set once.
     * $_SESSION is left to the marker rule: starting a session creates it.
     *
     * @return iterable<array{string, StateNode, string}>
     */
    private function processChanges(ProbeRun $run): iterable
    {
        $before = $run->snapshots[0];
        $after = $run->snapshots[1];
        foreach ($before->nodes as $path => $node) {
            if (str_starts_with($path, '{process}->$_SESSION')) {
                continue;
            }
            $changed = $after->get($path);
            if (null !== $changed && !$node->sameAs($changed)) {
                yield [$path, $changed, \sprintf('the request changed the PHP process: %s → %s', $node->describe(), $changed->describe())];
            }
        }
        foreach ($after->nodes as $path => $node) {
            if (str_starts_with($path, '{process}') && !str_starts_with($path, '{process}->$_SESSION') && null === $before->get($path)) {
                yield [$path, $node, \sprintf('the request added to the PHP process: %s', $node->describe())];
            }
        }
    }

    /**
     * @return iterable<array{string, StateNode, string}>
     */
    private function echoes(ProbeRun $run): iterable
    {
        $choices = [];
        for ($sending = 1; $sending <= $run->sendings(); ++$sending) {
            $choices[$sending] = $run->probe->choices($sending);
        }
        if ([] === $choices[1]) {
            return;
        }

        foreach ($run->snapshots[1]->nodes as $path => $node) {
            $nodes = $this->history($run, $path);
            if (null === $nodes || null === $node->value) {
                continue;
            }

            foreach (array_keys($choices[1]) as $group) {
                $sent = array_column($choices, $group);
                if (\count(array_unique($sent)) < 2) {
                    continue;
                }
                foreach ($nodes as $index => $kept) {
                    if (!self::equivalent($kept, $sent[$index])) {
                        continue 2;
                    }
                }

                yield [$path, $nodes[0], \sprintf('follows the value each request sent: %s', implode(' → ', array_map(static fn (StateNode $kept): string => $kept->describe(), $nodes)))];
                continue 2;
            }
        }
    }

    /**
     * A value written by a logged-in request is kept for the anonymous one that follows when it follows the
     * users (A, B, then A's again) and is still A's after the visitor. Following the users rules out what a
     * request builds once (a lazy initialization) and what changes on every request (a session id); a value
     * each request writes again (the locale of the request) takes the visitor's value, and is not reported.
     *
     * @return iterable<array{string, StateNode, string}>
     */
    private function handovers(ProbeRun $run): iterable
    {
        for ($sending = 4; $sending <= $run->sendings(); ++$sending) {
            $user = $run->identities[$sending - 1] ?? null;
            $other = $run->identities[$sending - 2] ?? null;
            if (null !== ($run->identities[$sending] ?? null) || null === $user || null === $other || $user === $other || $user !== ($run->identities[$sending - 3] ?? null)) {
                continue;
            }

            foreach ($run->snapshots[$sending - 1]->nodes as $path => $node) {
                if (null === $node->value || $node->isArray()) {
                    continue;
                }
                $first = $run->snapshots[$sending - 3]->get($path);
                $between = $run->snapshots[$sending - 2]->get($path);
                $kept = $run->snapshots[$sending]->get($path);
                if (null === $first || null === $between || null === $kept || !$first->sameAs($node) || $between->sameAs($node) || !$kept->sameAs($node)) {
                    continue;
                }

                // When the probe also sends other values in turn, the value may follow them rather than the user.
                $values = $run->probe->choices($sending - 1) === $run->probe->choices($sending - 3) && $run->probe->choices($sending - 1) !== $run->probe->choices($sending - 2) && [] !== $run->probe->choices($sending - 1);
                yield [$path, $node, $values
                    ? \sprintf('follows what each request sent, the user and the values of the request together (%s, then %s, then %s again), and is still there for the anonymous request that follows', $node->describe(), $between->describe(), $node->describe())
                    : \sprintf('follows the logged-in user (%s, then %s for the other user, then %s again) and is still the last user\'s for the anonymous request that follows', $node->describe(), $between->describe(), $node->describe())];
            }
        }
    }

    /**
     * @return list<StateNode>|null the node after each sending, or null when one is missing
     */
    private function history(ProbeRun $run, string $path): ?array
    {
        $nodes = [];
        for ($sending = 1; $sending <= $run->sendings(); ++$sending) {
            $node = $run->snapshots[$sending]->get($path);
            if (null === $node) {
                return null;
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    private static function equivalent(StateNode $node, string $sent): bool
    {
        if ('bool' === $node->type) {
            $flag = filter_var($sent, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE);

            return null !== $flag && ($flag ? 'true' : 'false') === $node->value;
        }

        return $node->value === $sent || $node->value === rawurldecode($sent);
    }

    /**
     * @return array<string, StateNode>
     */
    private function requestHolders(Snapshot $snapshot): array
    {
        $holders = [];
        foreach ($snapshot->nodes as $path => $node) {
            $class = str_starts_with($node->type, 'ref ') ? substr($node->type, 4) : $node->type;
            if (class_exists($class) && is_a($class, Request::class, true)) {
                $holders[$path] = $node;
            }
        }

        return $holders;
    }

    /**
     * A retained Request is reached through many paths (a property, a captured closure, the trace of
     * an exception): they are one leak, owned by the service or static property they start from.
     */
    private static function root(string $path): string
    {
        return (string) preg_split('/->|\{|\[/', $path, 2)[0];
    }

    /**
     * @param array<string, true> $reported
     */
    private function isUnder(string $path, array $reported): bool
    {
        foreach ($reported as $parent => $_) {
            if (str_starts_with($path, $parent.'->') || str_starts_with($path, $parent.'[')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $path, ProbeRun $run): string
    {
        return str_replace(array_values($run->markers), Probe::MARKER, $path);
    }

    /**
     * @param list<int> $counts
     *
     * @return list<int>
     */
    private function sorted(array $counts): array
    {
        sort($counts);

        return $counts;
    }

    /**
     * @param array<string, array{type: LeakType, certain: bool, owner: string, paths: array<string, true>, evidence: string}> $groups
     */
    private function add(array &$groups, LeakType $type, bool $certain, StateNode $node, string $path, string $evidence): void
    {
        $owner = $node->owner ?? 'unknown';
        $key = $type->value.'|'.($certain ? 'certain' : 'suspect').'|'.$owner;
        $groups[$key] ??= ['type' => $type, 'certain' => $certain, 'owner' => $owner, 'paths' => [], 'evidence' => $evidence];
        $groups[$key]['paths'][$path] = true;
    }
}
