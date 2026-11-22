<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

interface Auditor
{
    /**
     * Audits the whole target project as it is on disk now.
     *
     * @return list<Finding>
     *
     * @throws ToolingException
     */
    public function audit(): array;
}
