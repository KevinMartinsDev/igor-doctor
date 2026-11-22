<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Audit;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Catches the leaks a state diff cannot see: a cache filled by the first request and reused
 * unchanged by the next ones (the first writer wins). The same request is sent to a kernel that
 * has already served other requests and to a fresh kernel; in a worker, both must answer the same.
 *
 * Both kernels live in one process, so static properties are shared between them: a leak kept in
 * a static is reported by the state diff, not here.
 */
final class ResponseDiffOracle
{
    /**
     * @param \Closure(): KernelInterface         $kernels   builds a new, not yet booted kernel
     * @param (\Closure(string): string)|null    $normalize removes what legitimately differs between two answers (dates, tokens)
     */
    public function __construct(
        private readonly \Closure $kernels,
        private readonly ?\Closure $normalize = null,
    ) {
    }

    /**
     * @param list<Probe> $before requests the used kernel serves first, typically as another user
     */
    public function compare(array $before, Probe $target, string $marker = 'igor-oracle'): ?LeakFinding
    {
        $used = ($this->kernels)();
        foreach ($before as $probe) {
            $this->send($used, $probe, $marker);
        }
        $afterOthers = $this->send($used, $target, $marker);
        $alone = $this->send(($this->kernels)(), $target, $marker);

        if ($afterOthers === $alone) {
            return null;
        }

        return new LeakFinding(
            LeakType::CrossRequestStateLeak,
            true,
            'response',
            $target->name,
            [],
            0,
            \sprintf(
                'after %s, the answer differs from a fresh worker: %s instead of %s',
                implode(', ', array_map(static fn (Probe $probe): string => $probe->name, $before)),
                self::excerpt($afterOthers),
                self::excerpt($alone),
            ),
        );
    }

    private function send(KernelInterface $kernel, Probe $probe, string $marker): string
    {
        $request = $probe->request($marker);
        $response = $kernel->handle($request);
        if ($kernel instanceof TerminableInterface) {
            $kernel->terminate($request, $response);
        }
        $container = $kernel->getContainer();
        if ($container->has('services_resetter')) {
            $container->get('services_resetter')->reset();
        }

        return $this->fingerprint($response);
    }

    private function fingerprint(Response $response): string
    {
        $body = (string) $response->getContent();
        if (null !== $this->normalize) {
            $body = ($this->normalize)($body);
        }

        return $response->getStatusCode().' '.$body;
    }

    private static function excerpt(string $fingerprint): string
    {
        return '"'.(mb_strlen($fingerprint) > 120 ? mb_substr($fingerprint, 0, 120).'…' : $fingerprint).'"';
    }
}
