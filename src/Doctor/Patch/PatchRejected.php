<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Patch;

/**
 * A patch broke a rule of the write policy. It is reported and never applied.
 */
final class PatchRejected extends \RuntimeException
{
}
