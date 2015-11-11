<?php

namespace Platformsh\Cli\Tests;

use org\bovigo\vfs\vfsStream;
use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class DrushHelperTest extends \PHPUnit_Framework_TestCase
{

    /** @var DrushHelper */
    protected $drushHelper;

    /** @var string */
    protected $root;

    /** @var Project */
    protected $project;

    /** @var Environment[] */
    protected $environments = [];

    /**
     * @{inheritdoc}
     */
    public function setUp()
    {
        $root = vfsStream::setup(__CLASS__);
        $this->root = vfsStream::url(__CLASS__);
        $this->drushHelper = new DrushHelper();

        // Set up a dummy project with a remote environment.
        $this->project = new Project(array(
          'id' => 'test',
          'title' => 'Test project title',
        ));
        $this->environments[] = new Environment(array(
          'id' => 'master',
          '_links' => array(
            'public-url' => array('href' => 'http://example.com'),
            'ssh' => array('href' => 'ssh://user@example.com'),
          ),
        ));
    }

    public function testCreateAliases()
    {
        // Set up file structure.
        $testDir = tempnam($this->root, '');
        unlink($testDir);
        mkdir($testDir);
        $projectRoot = "$testDir/project";
        $homeDir = "$testDir/home";
        mkdir($projectRoot);
        mkdir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
        mkdir($homeDir);

        // Check that aliases are created.
        $this->drushHelper->setHomeDir($homeDir);
        $this->drushHelper->createAliases($this->project, $projectRoot, $this->environments);
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = array();
        include_once "$homeDir/.drush/test.aliases.drushrc.php";
        $this->assertArrayHasKey('master', $aliases);
        $this->assertArrayHasKey('_local', $aliases);
    }

    public function testCreateAliasesMultiApp()
    {
        // Set up file structure.
        $testDir = tempnam($this->root, '');
        unlink($testDir);
        mkdir($testDir);

        $fsHelper = new FilesystemHelper();
        $fsHelper->copyAll(__DIR__ . '/../data/repositories/multiple', $testDir . '/project/repository');
        $projectRoot = $testDir . '/project';

        $homeDir = "$testDir/home";
        mkdir($homeDir);

        // Check that aliases are created.
        $this->drushHelper->setHomeDir($homeDir);
        $this->drushHelper->createAliases($this->project, $projectRoot, $this->environments);
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = array();
        include_once "$homeDir/.drush/test.aliases.drushrc.php";

        // The aliases are the same as for single apps, because there's only one
        // Drupal application defined.
        $this->assertArrayHasKey('master', $aliases);
        $this->assertArrayHasKey('_local', $aliases);
    }

    public function testCreateAliasesMultiDrupal()
    {
        // Set up file structure.
        $testDir = tempnam($this->root, '');
        unlink($testDir);
        mkdir($testDir);

        $fsHelper = new FilesystemHelper();
        $fsHelper->copyAll(__DIR__ . '/../data/repositories/multi-drupal', $testDir . '/project/repository');
        $projectRoot = $testDir . '/project';

        $homeDir = "$testDir/home";
        mkdir($homeDir);

        // Check that aliases are created.
        $this->drushHelper->setHomeDir($homeDir);
        $this->drushHelper->createAliases($this->project, $projectRoot, $this->environments);
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = array();
        include_once "$homeDir/.drush/test.aliases.drushrc.php";

        $this->assertArrayHasKey('master--drupal1', $aliases);
        $this->assertArrayHasKey('_local--drupal1', $aliases);
        $this->assertArrayHasKey('master--drupal2', $aliases);
        $this->assertArrayHasKey('_local--drupal2', $aliases);
    }
}
