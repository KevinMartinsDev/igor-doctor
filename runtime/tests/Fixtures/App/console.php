<?php

declare(strict_types=1);

use IgorDoctor\Runtime\Command\WorkerScanCommand;
use IgorDoctor\Runtime\Tests\Fixtures\App\TestKernel;
use Symfony\Component\Console\Application;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$application = new Application();
$application->addCommand(new WorkerScanCommand(new TestKernel('runtime', false)));
$application->run();
