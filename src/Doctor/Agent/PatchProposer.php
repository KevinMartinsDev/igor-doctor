<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor\Agent;

use IgorDoctor\Doctor\Finding;

interface PatchProposer
{
    /**
     * @param string $fileContent current content of the file the finding is in
     */
    public function propose(Finding $finding, string $fileContent): Proposal;
}
