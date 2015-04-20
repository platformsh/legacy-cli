<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Local\LocalProject;

class ComposerTest extends BaseToolstackTest
{

    public function testBuildComposer()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/composer');
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $this->assertFileExists($webRoot . '/vendor/guzzlehttp/guzzle/src/Client.php');

        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        $this->assertFileExists($repositoryDir . '/.gitignore');
    }
}
