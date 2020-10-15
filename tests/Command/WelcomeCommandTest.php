<?php

namespace Platformsh\Cli\Tests\Command;

use Platformsh\Cli\Command\WelcomeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class WelcomeCommandTest extends \PHPUnit_Framework_TestCase
{
    private function runCommand(array $args) {
        $output = new BufferedOutput();
        $input = new ArrayInput($args);
        $input->setInteractive(false);
        (new WelcomeCommand())
            ->run($input, $output);

        return $output->fetch();
    }

    public function testWelcomeOnLocalContainer() {
        chdir('/');
        putenv('PLATFORM_PROJECT=test-project');
        putenv('PLATFORM_BRANCH=test-environment');
        putenv('PLATFORM_ROUTES=' . base64_encode(json_encode([])));
        putenv('PLATFORMSH_CLI_SESSION_ID=test' . rand(100, 999));
        $result = $this->runCommand([]);
        $this->assertContains(
            'Project ID: test-project',
            $result
        );
        $this->assertContains(
            'Local environment commands',
            $result
        );
    }
}
