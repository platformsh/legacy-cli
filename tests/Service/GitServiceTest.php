<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Tests\HasTempDirTrait;

#[Group('slow')]
class GitServiceTest extends TestCase
{
    use HasTempDirTrait;

    protected Git $git;

    /**
     * @{inheritdoc}
     *
     * Set up before every test.
     *
     * @throws \Exception
     */
    public function setUp(): void
    {
        $this->tempDirSetUp();
        $repository = $this->getRepositoryDir();
        if (!is_dir($repository) && !mkdir($repository, 0o755, true)) {
            throw new \Exception("Failed to create directories.");
        }

        $this->git = new Git();
        $this->git->init($repository, '', true);
        $this->git->setDefaultRepositoryDir($repository);
        chdir($repository);

        // Ensure we are on the main branch.
        $this->git->execute(['checkout', '-b', 'main'], null, true);

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
    public function testEnsureInstalled(): void
    {
        $this->expectNotToPerformAssertions();
        $this->git->ensureInstalled();
    }

    /**
     * Test GitHelper::isRepository().
     */
    public function testGetRoot(): void
    {
        // Test a real repository.
        $repositoryDir = $this->getRepositoryDir();
        $this->assertEquals($repositoryDir, $this->git->getRoot($repositoryDir));
        mkdir($repositoryDir . '/1/2/3/4/5', 0o755, true);
        $this->assertEquals($repositoryDir, $this->git->getRoot($repositoryDir . '/1/2/3/4/5'));

        // Test a non-repository.
        $this->assertFalse($this->git->getRoot($this->tempDir));
        $this->expectException('Exception');
        $this->expectExceptionMessage('Not a git repository');
        $this->git->getRoot($this->tempDir, true);
    }

    /**
     * Get a Git repository directory.
     *
     * @return string
     */
    protected function getRepositoryDir(): string
    {
        return $this->tempDir . '/repo';
    }

    /**
     * Test GitHelper::checkOutNew().
     */
    public function testCheckOutNew(): void
    {
        $this->assertTrue($this->git->checkOutNew('new'));
        $this->git->checkOut('main');
    }

    /**
     * Test GitHelper::branchExists().
     */
    public function testBranchExists(): void
    {
        $this->git->checkOutNew('existent');
        $this->assertTrue($this->git->branchExists('existent'));
        $this->assertFalse($this->git->branchExists('nonexistent'));
    }

    /**
     * Test GitHelper::branchExists() with unicode branch names.
     */
    public function testBranchExistsUnicode(): void
    {
        $this->git->checkOutNew('b®åñçh-wî†h-üní¢ø∂é');
        $this->assertTrue($this->git->branchExists('b®åñçh-wî†h-üní¢ø∂é'));
    }

    /**
     * Test GitHelper::getCurrentBranch().
     */
    public function testGetCurrentBranch(): void
    {
        $this->git->checkOutNew('test');
        $this->assertEquals('test', $this->git->getCurrentBranch());
    }

    /**
     * Test GitHelper::getConfig().
     */
    public function testGetConfig(): void
    {
        $config = $this->git->getConfig('user.email');
        $this->assertEquals('test@example.com', $config);
    }

}
