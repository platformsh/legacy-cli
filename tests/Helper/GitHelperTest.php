<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Git;

class GitHelperTest extends \PHPUnit_Framework_TestCase
{

    use HasTempDirTrait;

    /** @var Git */
    protected $gitHelper;

    /**
     * @{inheritdoc}
     *
     * Set up before every test.
     *
     * @throws \Exception
     */
    public function setUp()
    {
        $this->tempDirSetUp();
        $repository = $this->getRepositoryDir();
        if (!is_dir($repository) && !mkdir($repository, 0755, true)) {
            throw new \Exception("Failed to create directories.");
        }

        $this->gitHelper = new Git();
        $this->gitHelper->init($repository, true);
        $this->gitHelper->setDefaultRepositoryDir($repository);
        chdir($repository);

        // Ensure we are on the master branch.
        $this->gitHelper->checkOut('master');

        // Add required Git config before committing.
        shell_exec('git config user.email test@example.com');
        shell_exec('git config user.name "Test"');
        shell_exec('git config commit.gpgsign false');

        // Make a dummy commit so that there is a HEAD.
        touch($repository . '/README.txt');
        shell_exec('git add -A && git commit -qm "Initial commit"');
    }

    /**
     * Test GitHelper::ensureInstalled().
     */
    public function testEnsureInstalled()
    {
        $this->gitHelper->ensureInstalled();
    }

    /**
     * Test GitHelper::isRepository().
     */
    public function testGetRoot()
    {
        $this->assertFalse($this->gitHelper->getRoot($this->tempDir));
        $repositoryDir = $this->getRepositoryDir();
        $this->assertEquals($repositoryDir, $this->gitHelper->getRoot($repositoryDir));
        mkdir($repositoryDir . '/1/2/3/4/5', 0755, true);
        $this->assertEquals($repositoryDir, $this->gitHelper->getRoot($repositoryDir . '/1/2/3/4/5'));
        $this->setExpectedException('Exception', 'Not a git repository');
        $this->gitHelper->getRoot($this->tempDir, true);
    }

    /**
     * Get a Git repository directory.
     *
     * @return string
     */
    protected function getRepositoryDir()
    {
        return $this->tempDir . '/repo';
    }

    /**
     * Test GitHelper::checkOutNew().
     */
    public function testCheckOutNew()
    {
        $this->assertTrue($this->gitHelper->checkOutNew('new'));
        $this->gitHelper->checkOut('master');
    }

    /**
     * Test GitHelper::branchExists().
     */
    public function testBranchExists()
    {
        $this->gitHelper->checkOutNew('existent');
        $this->assertTrue($this->gitHelper->branchExists('existent'));
        $this->assertFalse($this->gitHelper->branchExists('nonexistent'));
    }

    /**
     * Test GitHelper::branchExists() with unicode branch names.
     */
    public function testBranchExistsUnicode()
    {
        $this->gitHelper->checkOutNew('b®åñçh-wî†h-üní¢ø∂é');
        $this->assertTrue($this->gitHelper->branchExists('b®åñçh-wî†h-üní¢ø∂é'));
    }

    /**
     * Test GitHelper::getCurrentBranch().
     */
    public function testGetCurrentBranch()
    {
        $this->gitHelper->checkOutNew('test');
        $this->assertEquals('test', $this->gitHelper->getCurrentBranch());
    }

    /**
     * Test GitHelper::getMergedBranches().
     */
    public function testGetMergedBranches()
    {
        $this->gitHelper->checkOutNew('branch1');
        $this->gitHelper->checkOutNew('branch2');
        $this->assertEquals([
            'branch1',
            'branch2',
            'master',
        ], $this->gitHelper->getMergedBranches('master'));
    }

    /**
     * Test GitHelper::getConfig().
     */
    public function testGetConfig()
    {
        $config = $this->gitHelper->getConfig('user.email');
        $this->assertEquals('test@example.com', $config);
    }

}
