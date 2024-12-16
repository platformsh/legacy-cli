<?php

namespace Platformsh\Cli\Tests\Local;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;

class ApplicationFinderTest extends TestCase
{
    private $finder;

    public function setUp(): void
    {
        $config = (new Config())->withOverrides([
            'service.app_config_file' => '_platform.app.yaml',
            'service.applications_config_file' =>  '_platform/applications.yaml',
        ]);
        $this->finder = new ApplicationFinder($config);
    }

    public function testFindNestedApps()
    {
        $fakeAppRoot = 'tests/data/repositories/multiple/nest';

        $apps = $this->finder->findApplications($fakeAppRoot);
        $this->assertCount(3, $apps);
    }

    public function testFindAppsUnderGroupedConfig()
    {
        $fakeAppRoot = 'tests/data/repositories/multi-grouped-config';

        $apps = $this->finder->findApplications($fakeAppRoot);
        $this->assertCount(3, $apps);
    }

    public function testDetectMultiple()
    {
        $fakeRepositoryRoot = 'tests/data/repositories/multiple';

        $apps = $this->finder->findApplications($fakeRepositoryRoot);
        $this->assertCount(6, $apps);
    }
}
