<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Briefing;

use IgorDoctor\Doctor\Finding;

interface ServiceContext
{
    /**
     * Files of the services the finding's code line uses (a dependency the closure or the call goes
     * to), relative to the target. Best-effort: an empty list when the analyser cannot tell.
     *
     * @return list<string>
     */
    public function relatedFiles(Finding $finding): array;
}
