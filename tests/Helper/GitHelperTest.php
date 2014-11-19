<?php

namespace CommerceGuys\Platform\Cli\Tests;

use CommerceGuys\Platform\Cli\Helper\GitHelper;

class GitHelperTest extends \PHPUnit_Framework_TestCase
{

    /** @var GitHelper */
    protected $gitHelper;

    /** @var string */
    protected $root;

    /**
     * @{inheritdoc}
     *
     * Set up before every test.
     *
     * @throws \Exception
     */
    public function setUp()
    {
        $this->root = '/tmp/pshCliTests/git';
        $repositoryDir = $this->root . '/repo';
        if (!is_dir($repositoryDir) && !mkdir($repositoryDir, 0755, true)) {
            throw new \Exception("Failed to create directories.");
        }
        $this->gitHelper = new GitHelper();
    }

    /**
     * @inheritdoc
     */
    public function tearDown()
    {
        exec('rm -rf /tmp/pshCliTests/git/repo');
    }

    /**
     * Get a Git repository directory.
     *
     * @return string
     */
    protected function getRepositoryDir() {
        $repositoryDir = $this->root . '/repo';
        $this->gitHelper->init($repositoryDir, true);
        return $repositoryDir;
    }

    /**
     * Test GitHelper::isRepository().
     */
    public function testIsRepository()
    {
        $this->assertFalse($this->gitHelper->isRepository($this->root));
        $repository = $this->getRepositoryDir();
        $this->assertTrue($this->gitHelper->isRepository($repository));
    }

    /**
     * Test GitHelper::branch().
     */
    public function testBranch()
    {
        $repository = $this->getRepositoryDir();
        $this->gitHelper->setDefaultRepositoryDir($repository);
        $this->assertTrue($this->gitHelper->branch('new'));
    }

    /**
     * Test GitHelper::branchExists().
     */
    public function testBranchExists()
    {
        $repository = $this->getRepositoryDir();
        chdir($repository);

        // Create a branch.
        shell_exec('git checkout -q -b new');

        // Add required Git config before committing.
        shell_exec('git config user.email test@example.com');
        shell_exec('git config user.name "Platform.sh CLI Test"');

        // Make a dummy commit so that there is a HEAD.
        touch($repository . '/README.txt');
        shell_exec('git add -A && git commit -qm "Initial commit"');

        $this->assertTrue($this->gitHelper->branchExists('new', $repository));
    }

    /**
     * Test GitHelper::getConfig().
     */
    public function testGetConfig()
    {
        $email = 'test@example.com';
        $repository = $this->getRepositoryDir();
        chdir($repository);
        shell_exec('git config user.email ' . $email);
        $config = $this->gitHelper->getConfig('user.email', $repository);
        $this->assertEquals($email, $config);
    }

}
