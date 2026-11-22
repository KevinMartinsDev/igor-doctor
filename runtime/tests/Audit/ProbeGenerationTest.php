<?php

declare(strict_types=1);

namespace IgorDoctor\Runtime\Tests\Audit;

use IgorDoctor\Runtime\Audit\InputHarvester;
use IgorDoctor\Runtime\Audit\LinkDiscovery;
use IgorDoctor\Runtime\Audit\Probe;
use IgorDoctor\Runtime\Audit\ProbeFactory;
use IgorDoctor\Runtime\Tests\Fixtures\App\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProbeGenerationTest extends TestCase
{
    public function testItBuildsOneProbePerGetRouteFromItsRequirements(): void
    {
        $probes = $this->probes();

        self::assertSame('/books?igor={marker}', $probes['books']->uri);
        self::assertSame('/{en|fr|en}/posts/{marker}?igor={marker}', $probes['post']->uri);
        self::assertSame('/items/1?igor={marker}', $probes['item']->uri);
        self::assertSame('/{en|ar|en}/about?igor={marker}', $probes['about']->uri, 'The route default comes first among the alternatives.');
        self::assertSame(['X-Igor' => Probe::MARKER, 'Accept-Language' => '{en|fr|en}'], $probes['books']->headers);
    }

    public function testItAlternatesTwoUrlsFoundInTheApplicationPages(): void
    {
        $probes = (new LinkDiscovery(TestKernel::create()))->discover(array_values($this->probes('/^(links|item)$/')));
        $byName = array_combine(array_map(static fn (Probe $probe): string => $probe->name, $probes), $probes);

        self::assertSame('{/items/7|/items/8|/items/7}?igor={marker}', $byName['item']->uri);
        self::assertSame('/items/8', $byName['item']->request('m', 2)->getPathInfo());
    }

    public function testItHarvestsTheInputsTheCodeAndTheTemplatesRead(): void
    {
        $directory = sys_get_temp_dir().'/igor-harvest-'.bin2hex(random_bytes(4));
        (new Filesystem())->dumpFile($directory.'/src/Controller.php', <<<'PHP'
            <?php
            $request->query->get('q');
            $request->query->getInt('per_page');
            $request->query->getInt('page_number');
            $request->cookies->get('theme');
            $request->headers->get('X-Tenant');
            $request->headers->get('Accept-Language');
            $_GET['legacy'];
            PHP);
        (new Filesystem())->dumpFile($directory.'/templates/base.html.twig', "{{ app.request.query.get('tz') }}");

        $inputs = (new InputHarvester([$directory.'/src', $directory.'/templates', $directory.'/missing']))->harvest();
        (new Filesystem())->remove($directory);

        self::assertSame(['legacy' => Probe::MARKER, 'page_number' => '{1|2|1}', 'per_page' => '{10|30|10}', 'q' => Probe::MARKER, 'tz' => '{Europe/Paris|Asia/Tokyo|Europe/Paris}'], $inputs->query);
        self::assertSame(['theme' => '{light|dark|light}'], $inputs->cookies);
        self::assertSame(['X-Tenant' => Probe::MARKER], $inputs->headers);

        $probe = $inputs->applyTo(new Probe('books', '/books?q=kept'));
        self::assertSame('kept', $probe->request('m')->query->get('q'));
        self::assertSame('m', $probe->request('m')->headers->get('X-Tenant'));
        self::assertSame('dark', $probe->request('m', 2)->cookies->get('theme'));
    }

    /**
     * @return array<string, Probe>
     */
    private function probes(?string $route = null): array
    {
        $kernel = TestKernel::create();
        $kernel->boot();
        $probes = (new ProbeFactory($kernel->getContainer()->get('router')))->probes($route);
        $kernel->shutdown();

        return array_combine(array_map(static fn (Probe $probe): string => $probe->name, $probes), $probes);
    }
}
