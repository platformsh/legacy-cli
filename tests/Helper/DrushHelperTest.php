<?php

namespace Platformsh\Cli\Tests;

use org\bovigo\vfs\vfsStream;
use Platformsh\Cli\Helper\DrushHelper;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

class DrushHelperTest extends \PHPUnit_Framework_TestCase
{

    /** @var DrushHelper */
    protected $drushHelper;

    /** @var string */
    protected $root;

    /**
     * @{inheritdoc}
     */
    public function setUp()
    {
        $root = vfsStream::setup(__CLASS__);
        $this->root = vfsStream::url(__CLASS__);
        $this->drushHelper = new DrushHelper();
    }

    public function testCreateAliases()
    {
        // Set up a dummy project with a remote environment.
        $project = new Project(array(
          'id' => 'test',
          'title' => 'Test project title',
        ));
        $environments = array();
        $environments[] = new Environment(array(
          'id' => 'master',
          '_links' => array(
            'public-url' => array('href' => 'http://example.com'),
            'ssh' => array('href' => 'ssh://user@example.com'),
          ),
        ));

        // Set up file structure.
        $testDir = tempnam($this->root, '');
        unlink($testDir);
        mkdir($testDir);
        $projectRoot = "$testDir/project";
        $homeDir = "$testDir/home";
        mkdir($projectRoot);
        mkdir($homeDir);

        // Check that aliases are created.
        $this->drushHelper->setHomeDir($homeDir);
        $this->drushHelper->createAliases($project, $projectRoot, $environments);
        $this->assertFileExists("$homeDir/.drush/test.aliases.drushrc.php");

        // Check that aliases exist for the 'master' and local environments.
        $aliases = array();
        include_once "$homeDir/.drush/test.aliases.drushrc.php";
        $this->assertArrayHasKey('master', $aliases);
        $this->assertArrayHasKey('_local', $aliases);
    }
}
