<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

/**
 * The model could not be asked at all: another attempt would fail the same way.
 */
final class ModelUnavailable extends \RuntimeException
{
}
