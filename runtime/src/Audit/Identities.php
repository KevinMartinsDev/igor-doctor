<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * Who sends each request of a probe. With two users the sendings are A, B, A again, then an anonymous
 * visitor: a value that follows the user (A, B, A) is the user's own, and one still A's for the visitor
 * was left by A's request. With one user: A, anonymous, A. Without users every request is anonymous.
 */
final readonly class Identities
{
    /**
     * @param list<string> $users identifiers for the application's user providers; only the first two are used
     */
    public function __construct(public array $users = [])
    {
    }

    public function sendings(): int
    {
        return \count($this->sequence());
    }

    public function at(int $sending): ?string
    {
        $sequence = $this->sequence();

        return $sequence[($sending - 1) % \count($sequence)];
    }

    /**
     * @return list<?string>
     */
    private function sequence(): array
    {
        return match (true) {
            [] === $this->users => [null, null, null],
            1 === \count($this->users) => [$this->users[0], null, $this->users[0]],
            default => [$this->users[0], $this->users[1], $this->users[0], null],
        };
    }
}
