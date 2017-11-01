<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

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
        $this->drush = new Drush();

        // Set up a dummy project with a remote environment.
        $this->project = new Project([
            'id' => 'test',
            'title' => 'Test project title',
        ], null, null, true);
        $this->environments[] = new Environment([
            'id' => 'master',
            'title' => 'master',
            '_links' => [
                'public-url' => ['href' => 'http://example.com'],
                'ssh' => ['href' => 'ssh://user@example.com'],
            ],
        ], null, null, true);

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
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = [];
        include_once "$homeDir/.drush/test.aliases.drushrc.php";
        $this->assertArrayHasKey('master', $aliases);
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
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = [];
        include_once "$homeDir/.drush/test.aliases.drushrc.php";

        // The aliases are the same as for single apps, because there's only one
        // Drupal application defined.
        $this->assertArrayHasKey('master', $aliases);
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
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = [];
        include_once "$homeDir/.drush/test.aliases.drushrc.php";

        $this->assertArrayHasKey('master--drupal1', $aliases);
        $this->assertArrayHasKey('_local--drupal1', $aliases);
        $this->assertArrayHasKey('master--drupal2', $aliases);
        $this->assertArrayHasKey('_local--drupal2', $aliases);
    }
}
