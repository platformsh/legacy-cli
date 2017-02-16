<?php

namespace Platformsh\Cli\Tests\Toolstack;

/**
 * @group slow
 */
class DependenciesTest extends BaseToolstackTest
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

    public function testBuildSucceedsIfDepsInstalled()
    {
        $this->assertBuildSucceeds($this->sourceDir);
    }
}
