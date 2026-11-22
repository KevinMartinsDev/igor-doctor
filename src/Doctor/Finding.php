<?php

declare(strict_types=1);

namespace IgorDoctor\Doctor;

/**
 * One Igor finding, as returned by the audit_project and audit_file MCP tools.
 */
final readonly class Finding
{
    public const SEVERITY_ERROR = 'ERROR';
    public const SEVERITY_WARNING = 'WARNING';

    public function __construct(
        public string $file,
        public int $line,
        public string $ruleId,
        public string $severity,
        public string $message,
        public ?string $class = null,
        public ?string $method = null,
        public ?string $code = null,
        public ?string $remediation = null,
    ) {
    }

    /**
     * @param array<mixed> $data one entry of the "findings" list returned by Igor
     */
    public static function fromIgor(array $data): self
    {
        foreach (['file', 'line', 'rule_id', 'severity', 'message'] as $key) {
            if (!isset($data[$key])) {
                throw new ToolingException(\sprintf('Igor returned a finding without "%s".', $key));
            }
        }

        return new self(
            file: (string) $data['file'],
            line: (int) $data['line'],
            ruleId: (string) $data['rule_id'],
            severity: strtoupper((string) $data['severity']),
            message: (string) $data['message'],
            class: isset($data['class']) ? (string) $data['class'] : null,
            method: isset($data['method']) ? (string) $data['method'] : null,
            code: isset($data['code']) ? (string) $data['code'] : null,
            remediation: isset($data['remediation']) ? (string) $data['remediation'] : null,
        );
    }

    /**
     * Only errors block the run: warnings are fixed when possible but never fail it.
     */
    public function isBlocking(): bool
    {
        return self::SEVERITY_ERROR === $this->severity;
    }

    /**
     * Identity that survives line shifts: a patch above a finding moves it without fixing it.
     */
    public function id(): string
    {
        return hash('xxh128', implode("\0", [$this->file, $this->ruleId, $this->class, $this->method, $this->message]));
    }

    public function location(): string
    {
        return $this->file.':'.$this->line;
    }
}
