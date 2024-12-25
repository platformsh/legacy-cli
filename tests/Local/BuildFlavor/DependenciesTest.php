<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use PHPUnit\Framework\Attributes\Group;
use Platformsh\Cli\Service\Shell;

#[Group('slow')]
class DependenciesTest extends BuildFlavorTestBase
{
    protected string $sourceDir = 'tests/data/apps/build-deps';

    public function testBuildSucceedsIfDepsNotRequired(): void
    {
        $this->assertBuildSucceeds($this->sourceDir, ['no-deps' => true, 'no-build-hooks' => true]);
    }

    public function testBuildFailsIfDepsNotInstalled(): void
    {
        $this->assertBuildSucceeds($this->sourceDir, ['no-deps' => true], false);
    }

    public function testBuildSucceedsIfNodejsDepsInstalled(): void
    {
        $shell = new Shell();
        if ($shell->commandExists('npm')) {
            $this->assertBuildSucceeds($this->sourceDir . '/nodejs');
        } else {
            $this->markTestSkipped();
        }
    }

    public function testBuildSucceedsIfPhpDepsInstalled(): void
    {
        $shell = new Shell();
        if ($shell->commandExists('composer')) {
            $this->assertBuildSucceeds($this->sourceDir . '/php');
        } else {
            $this->markTestSkipped();
        }
    }

    public function testBuildSucceedsIfPythonDepsInstalled(): void
    {
        $shell = new Shell();
        if ($shell->commandExists('pip') || $shell->commandExists('pip3')) {
            // Python dependencies are known to fail on the Travis PHP environment:
            // python and pip are available but too old or mis-configured.
            // @todo review this
            try {
                $this->assertBuildSucceeds($this->sourceDir . '/python');
            } catch (\RuntimeException $e) {
                if (\getenv('TRAVIS') && str_contains($e->getMessage(), 'The command failed') && str_contains($e->getMessage(), 'pip install')) {
                    $this->markTestSkipped('Installing python dependencies is known to fail on Travis');
                }
                throw $e;
            }
        } else {
            $this->markTestSkipped();
        }
    }

    public function testBuildSucceedsIfRubyDepsInstalled(): void
    {
        $shell = new Shell();
        if ($shell->commandExists('bundle')) {
            $this->assertBuildSucceeds($this->sourceDir . '/ruby');
        } else {
            $this->markTestSkipped();
        }
    }
}
