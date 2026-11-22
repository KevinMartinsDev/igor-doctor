<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

/**
 * The tooling around the fix loop failed (Igor missing, unreadable output, invalid target).
 * The command maps it to exit code 2.
 */
final class ToolingException extends \RuntimeException
{
}
