<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Metadata;

final class Parameter
{
    /** @var array<string, mixed> */
    private array $extraProperties = [];

    public function __construct(public readonly string $key)
    {
    }

    public function setValue(mixed $value): self
    {
        $this->extraProperties['_values'] = $value;

        return $this;
    }

    public function withValue(mixed $value): self
    {
        return (clone $this)->setValue($value);
    }

    public function getValue(): mixed
    {
        return $this->extraProperties['_values'] ?? null;
    }
}
