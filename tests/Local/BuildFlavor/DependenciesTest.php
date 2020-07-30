<?php

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use Platformsh\Cli\Service\Shell;

/**
 * @group slow
 */
class DependenciesTest extends BaseBuildFlavorTest
{
    protected $sourceDir = 'tests/data/apps/build-deps';

    public function testBuildSucceedsIfDepsNotRequired()
    {
        $this->assertBuildSucceeds($this->sourceDir, ['no-deps' => true, 'no-build-hooks' => true]);
    }

    public function testBuildFailsIfDepsNotInstalled()
    {
        $this->assertBuildSucceeds($this->sourceDir, ['no-deps' => true], false);
    }

    public function testBuildSucceedsIfNodejsDepsInstalled()
    {
        $shell = new Shell();
        if ($shell->commandExists('npm')) {
            $this->assertBuildSucceeds($this->sourceDir . '/nodejs');
        } else {
            $this->markTestSkipped();
        }
    }

    public function testBuildSucceedsIfPhpDepsInstalled()
    {
        $shell = new Shell();
        if ($shell->commandExists('composer')) {
            $this->assertBuildSucceeds($this->sourceDir . '/php');
        } else {
            $this->markTestSkipped();
        }
    }

    public function testBuildSucceedsIfPythonDepsInstalled()
    {
        $shell = new Shell();
        if ($shell->commandExists('pip') || $shell->commandExists('pip3')) {
            // Python dependencies are known to fail on the Travis PHP environment:
            // python and pip are available but too old or mis-configured.
            // @todo review this
            try {
                $this->assertBuildSucceeds($this->sourceDir . '/python');
            } catch (\RuntimeException $e) {
                if (\getenv('TRAVIS') && strpos($e->getMessage(), 'The command failed') !== false && strpos($e->getMessage(), 'pip install') !== false) {
                    $this->markTestSkipped('Installing python dependencies is known to fail on Travis');
                    return;
                }
                throw $e;
            }
        } else {
            $this->markTestSkipped();
        }
    }

    public function testBuildSucceedsIfRubyDepsInstalled()
    {
        $shell = new Shell();
        if ($shell->commandExists('bundle')) {
            $this->assertBuildSucceeds($this->sourceDir . '/ruby');
        } else {
            $this->markTestSkipped();
        }
    }
}
