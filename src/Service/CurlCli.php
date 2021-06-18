<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
        $definition->addOption(new InputOption('include', 'i', InputOption::VALUE_NONE, 'Include headers in the output'));
        $definition->addOption(new InputOption('head', 'I', InputOption::VALUE_NONE, 'Fetch headers only'));
        $definition->addOption(new InputOption('disable-compression', null, InputOption::VALUE_NONE, 'Do not use the curl --compressed flag'));
        $definition->addOption(new InputOption('enable-glob', null, InputOption::VALUE_NONE, 'Enable curl globbing (remove the --globoff flag)'));
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

        if ($input->getOption('head')) {
            $commandline .= ' --head';
        }

        if ($input->getOption('include')) {
            $commandline .= ' --include';
        }

        if ($requestMethod = $input->getOption('request')) {
            $commandline .= ' -X ' . escapeshellarg($requestMethod);
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

        $commandline .= ' --fail';

        $stdErr->writeln(sprintf('Running command: <info>%s</info>', str_replace($token, '[token]', $commandline)), OutputInterface::VERBOSITY_VERBOSE);

        $process = proc_open($commandline, [STDIN, STDOUT, STDERR], $pipes);

        return proc_close($process);
    }
}
