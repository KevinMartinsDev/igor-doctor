<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Fixtures\App\Metadata;

final class Operation
{
    /**
     * @param array<string, Parameter> $parameters
     */
    public function __construct(public readonly string $name, private array $parameters)
    {
    }

    /**
     * @return array<string, Parameter>
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * @param array<string, Parameter> $parameters
     */
    public function withParameters(array $parameters): self
    {
        $operation = clone $this;
        $operation->parameters = $parameters;

        return $operation;
    }
}
