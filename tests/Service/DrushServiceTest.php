<?php

namespace Platformsh\Cli\Tests\Service;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Tests\HasTempDirTrait;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Yaml\Yaml;

/**
 * @group slow
 */
class DrushServiceTest extends \PHPUnit_Framework_TestCase
{
    use HasTempDirTrait;

    /** @var Drush */
    protected $drush;

    /** @var Project */
    protected $project;

    /** @var Environment[] */
    protected $environments = [];

    /**
     * @{inheritdoc}
     */
    public function setUp()
    {
        $config = (new Config())->withOverrides(['service.app_config_file' => '_platform.app.yaml']);
        $this->drush = new Drush($config);

        // Set up a dummy project with a remote environment.
        $this->project = new Project([
            'id' => 'test',
            'title' => 'Test project title',
        ], 'https://127.0.0.1:30011/projects/test', null, true);
        $this->environments[] = new Environment([
            'id' => 'main',
            'title' => 'main',
            '_links' => [
                'public-url' => ['href' => 'http://example.com'],
                'ssh' => ['href' => 'ssh://user@example.com'],
            ],
        ], 'https://127.0.0.1:30011/projects/test/environments/main', null, true);

        $this->tempDirSetUp();
    }

    public function testCreateAliases()
    {
        // Set up file structure.
        $testDir = $this->createTempSubDir();

        $projectRoot = $testDir . '/project';

        $fsHelper = new Filesystem();
        $fsHelper->copyAll(__DIR__ . '/../data/apps/drupal/project', $projectRoot);

        $homeDir = "$testDir/home";
        mkdir($homeDir);
        $this->drush->setHomeDir($homeDir);

        // Check that aliases are created.
        $result = $this->drush->createAliases($this->project, $projectRoot, $this->environments);

        $this->assertTrue($result);
        $this->assertFileExists("$homeDir/.drush/site-aliases/test.aliases.drushrc.php");

        // Check that aliases exist for the 'main' and local environments.
        $aliases = [];
        include_once "$homeDir/.drush/site-aliases/test.aliases.drushrc.php";
        $this->assertArrayHasKey('main', $aliases);
        $this->assertArrayHasKey('_local', $aliases);

        // Check that YAML aliases exist.
        $this->assertFileExists($homeDir . '/.drush/site-aliases/test.site.yml');
        $aliases = Yaml::parse(file_get_contents($homeDir . '/.drush/site-aliases/test.site.yml'));
        $this->assertArrayHasKey('main', $aliases);
        $this->assertArrayHasKey('_local', $aliases);
    }

    public function testCreateAliasesMultiApp()
    {
        // Set up file structure.
        $testDir = $this->createTempSubDir();

        $projectRoot = $testDir . '/project';

        $fsHelper = new Filesystem();
        $fsHelper->copyAll(__DIR__ . '/../data/repositories/multiple', $projectRoot);

        $homeDir = "$testDir/home";
        mkdir($homeDir);
        $this->drush->setHomeDir($homeDir);

        // Check that aliases are created.
        $result = $this->drush->createAliases($this->project, $projectRoot, $this->environments);
        $this->assertTrue($result);
        $this->assertFileExists("$homeDir/.drush/site-aliases/test.aliases.drushrc.php");

        // Check that aliases exist for the 'main' and local environments.
        $aliases = [];
        include_once "$homeDir/.drush/site-aliases/test.aliases.drushrc.php";

        // The aliases are the same as for single apps, because there's only one
        // Drupal application defined.
        $this->assertArrayHasKey('main', $aliases);
        $this->assertArrayHasKey('_local', $aliases);

        $apps = $this->drush->getDrupalApps($projectRoot);
        $this->assertEquals(1, count($apps));
    }

    public function testCreateAliasesMultiDrupal()
    {
        // Set up file structure.
        $testDir = $this->createTempSubDir();

        $projectRoot = $testDir . '/project';

        $fsHelper = new Filesystem();
        $fsHelper->copyAll(__DIR__ . '/../data/repositories/multi-drupal', $projectRoot);

        $homeDir = "$testDir/home";
        mkdir($homeDir);
        $this->drush->setHomeDir($homeDir);

        $apps = $this->drush->getDrupalApps($projectRoot);
        $this->assertEquals(2, count($apps));

        // Check that aliases are created.
        $result = $this->drush->createAliases($this->project, $projectRoot, $this->environments);
        $this->assertTrue($result);
        $this->assertFileExists("$homeDir/.drush/site-aliases/test.aliases.drushrc.php");

        // Check that aliases exist for the 'main' and local environments.
        $aliases = [];
        include_once "$homeDir/.drush/site-aliases/test.aliases.drushrc.php";

        $this->assertArrayHasKey('main--drupal1', $aliases);
        $this->assertArrayHasKey('_local--drupal1', $aliases);
        $this->assertArrayHasKey('main--drupal2', $aliases);
        $this->assertArrayHasKey('_local--drupal2', $aliases);

        // Check that YAML aliases exist.
        $this->assertFileExists($homeDir . '/.drush/site-aliases/test.site.yml');
        $aliases = Yaml::parse(file_get_contents($homeDir . '/.drush/site-aliases/test.site.yml'));
        $this->assertArrayHasKey('main--drupal1', $aliases);
        $this->assertArrayHasKey('_local--drupal1', $aliases);
        $this->assertArrayHasKey('main--drupal2', $aliases);
        $this->assertArrayHasKey('_local--drupal2', $aliases);
    }

    public function testGetSiteAliasDir()
    {
        // Set up file structure.
        $testDir = $this->createTempSubDir();
        $homeDir = "$testDir/home";
        mkdir($homeDir);
        $this->drush->setHomeDir($homeDir);

        // The default global alias directory is ~/.drush/site-aliases.
        $this->assertEquals($homeDir . '/.drush/site-aliases', $this->drush->getSiteAliasDir());

        // If ~/.drush/site-aliases doesn't exist, but aliases exist in
        // ~/.drush, then the latter should be the alias directory.
        mkdir($homeDir . '/.drush');
        touch($homeDir . '/.drush/test.aliases.drushrc.php');
        $this->assertEquals($homeDir . '/.drush', $this->drush->getSiteAliasDir());

        // If ~/.drush/site-aliases does exist, then it should be considered the
        // alias directory (whether or not any alias files exist).
        mkdir($homeDir . '/.drush/site-aliases');
        $this->assertEquals($homeDir . '/.drush/site-aliases', $this->drush->getSiteAliasDir());
    }
}
