<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class CurlCli implements InputConfiguringInterface {

    private $api;

    public function __construct(Api $api) {
        $this->api = $api;
    }

    public static function configureInput(InputDefinition $definition)
    {
        $definition->addArgument(new InputArgument('path', InputArgument::OPTIONAL, 'The API path'));
        $definition->addOption(new InputOption('request', 'X', InputOption::VALUE_REQUIRED, 'The request method to use'));
        $definition->addOption(new InputOption('data', 'd', InputOption::VALUE_REQUIRED, 'Data to send'));
        $definition->addOption(new InputOption('json', null, InputOption::VALUE_REQUIRED, 'JSON data to send'));
        $definition->addOption(new InputOption('include', 'i', InputOption::VALUE_NONE, 'Include headers in the output'));
        $definition->addOption(new InputOption('head', 'I', InputOption::VALUE_NONE, 'Fetch headers only'));
        $definition->addOption(new InputOption('disable-compression', null, InputOption::VALUE_NONE, 'Do not use the curl --compressed flag'));
        $definition->addOption(new InputOption('enable-glob', null, InputOption::VALUE_NONE, 'Enable curl globbing (remove the --globoff flag)'));
        $definition->addOption(new InputOption('fail', 'f', InputOption::VALUE_NONE, 'Fail with no output on an error response'));
        $definition->addOption(new InputOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra header(s)'));
    }

    /**
     * Runs the curl command.
     *
     * @param string $baseUrl
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    public function run($baseUrl, InputInterface $input, OutputInterface $output) {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $url = rtrim($baseUrl, '/');

        if ($path = $input->getArgument('path')) {
            if (parse_url($path, PHP_URL_HOST)) {
                $stdErr->writeln(sprintf('Invalid path: <error>%s</error>', $path));

                return 1;
            }
            $url .= '/' . ltrim($path, '/');
        }

        $token = $this->api->getAccessToken();
        $commandline = sprintf(
            'curl -H %s %s',
            escapeshellarg('Authorization: Bearer ' . $token),
            escapeshellarg($url)
        );

        $passThroughFlags = ['head', 'include', 'fail'];
        foreach ($passThroughFlags as $flag) {
            if ($input->getOption($flag)) {
                $commandline .= ' --' . $flag;
            }
        }

        if ($requestMethod = $input->getOption('request')) {
            $commandline .= ' --request ' . escapeshellarg($requestMethod);
        }

        if ($data = $input->getOption('json')) {
            if (\json_decode($data) === null && \json_last_error() !== JSON_ERROR_NONE) {
                $stdErr->writeln('The value of --json contains invalid JSON.');
                return 1;
            }
            $commandline .= ' --data ' . escapeshellarg($data);
            $commandline .= ' --header ' . escapeshellarg('Content-Type: application/json');
            $commandline .= ' --header ' . escapeshellarg('Accept: application/json');
        }

        if ($data = $input->getOption('data')) {
            $commandline .= ' --data ' . escapeshellarg($data);
        }

        if (!$input->getOption('disable-compression')) {
            $commandline .= ' --compressed';
        }

        if (!$input->getOption('enable-glob')) {
            $commandline .= ' --globoff';
        }

        foreach ($input->getOption('header') as $header) {
            $commandline .= ' --header ' . escapeshellarg($header);
        }

        if ($output->isVeryVerbose()) {
            $commandline .= ' --verbose';
        } else {
            $commandline .= ' --silent --show-error';
        }

        // Censor the access token: this can be applied to verbose output.
        $censor = function ($str) use ($token) {
            return str_replace($token, '[token]', $str);
        };

        $stdErr->writeln(sprintf('Running command: <info>%s</info>', $censor($commandline)), OutputInterface::VERBOSITY_VERBOSE);

        $process = new Process($commandline);
        $process->run(function ($type, $buffer) use ($stdErr, $censor, $output) {
            if ($type === Process::ERR) {
                $stdErr->write($censor($buffer));
            } else {
                $output->write($buffer);
            }
        });

        return $process->getExitCode();
    }
}
