<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

/**
 * Several sensors behind one audit: Igor reads the code, the runtime probe runs it. A patch is kept
 * only when every sensor agrees that its finding is gone and nothing new appeared.
 */
final class CompositeAuditor implements Auditor
{
    /** @var list<Auditor> */
    private readonly array $auditors;

    public function __construct(Auditor ...$auditors)
    {
        $this->auditors = array_values($auditors);
    }

    public function audit(): array
    {
        $findings = [];
        foreach ($this->auditors as $auditor) {
            array_push($findings, ...$auditor->audit());
        }

        return $findings;
    }
}
