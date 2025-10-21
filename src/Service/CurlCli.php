<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

readonly class CurlCli implements InputConfiguringInterface
{
    public function __construct(private Api $api) {}

    public static function configureInput(InputDefinition $definition): void
    {
        $definition->addArgument(new InputArgument('path', InputArgument::OPTIONAL, 'The API path'));
        $definition->addOption(new InputOption('request', 'X', InputOption::VALUE_REQUIRED, 'The request method to use'));
        $definition->addOption(new InputOption('data', 'd', InputOption::VALUE_REQUIRED, 'Data to send'));
        $definition->addOption(new InputOption('json', null, InputOption::VALUE_REQUIRED, 'JSON data to send'));
        $definition->addOption(new InputOption('include', 'i', InputOption::VALUE_NONE, 'Include headers in the output'));
        $definition->addOption(new InputOption('head', 'I', InputOption::VALUE_NONE, 'Fetch headers only'));
        $definition->addOption(new InputOption('disable-compression', null, InputOption::VALUE_NONE, 'Do not use the curl --compressed flag'));
        $definition->addOption(new InputOption('enable-glob', null, InputOption::VALUE_NONE, 'Enable curl globbing (remove the --globoff flag)'));
        $definition->addOption(new InputOption('no-retry-401', null, InputOption::VALUE_NONE, 'Disable automatic retry on 401 errors'));
        $definition->addOption(new InputOption('fail', 'f', InputOption::VALUE_NONE, 'Fail with no output on an error response'));
        $definition->addOption(new InputOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra header(s)'));
    }

    /**
     * Runs the curl command.
     */
    public function run(string $baseUrl, InputInterface $input, OutputInterface $output): int
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $url = rtrim($baseUrl, '/');

        if ($path = $input->getArgument('path')) {
            if (parse_url((string) $path, PHP_URL_HOST)) {
                $stdErr->writeln(sprintf('Invalid path: <error>%s</error>', $path));

                return 1;
            }
            $url .= '/' . ltrim((string) $path, '/');
        }

        $retryOn401 = !$input->getOption('no-retry-401');

        $token = $this->api->getAccessToken();

        // Censor the access token: this can be applied to verbose output.
        $censor = function ($str) use (&$token) {
            return str_replace($token, '[token]', $str);
        };

        $commandline = $this->buildCurlCommand($url, $token, $input);

        // Add --verbose if -vv is provided, or if retrying on 401 errors.
        // In the latter case the verbose output will be intercepted and hidden.
        if ($stdErr->isVeryVerbose() || $retryOn401) {
            $commandline .= ' --verbose';
        }

        $process = Process::fromShellCommandline($commandline);
        $shouldRetry = false;
        $newToken = '';
        $onOutput = function ($type, $buffer) use ($censor, $output, $stdErr, $process, $retryOn401, &$newToken, &$shouldRetry) {
            if ($shouldRetry) {
                // Ensure there is no output after a retry is triggered.
                return;
            }
            if ($type === Process::OUT) {
                $output->write($buffer);
                return;
            }
            if ($type === Process::ERR) {
                if ($retryOn401 && $this->parseCurlStatusCode($buffer) === 401 && $this->api->isLoggedIn()) {
                    $shouldRetry = true;
                    $process->clearErrorOutput();
                    $process->clearOutput();

                    $newToken = $this->api->getAccessToken(true);
                    $stdErr->writeln('The access token has been refreshed. Retrying request.');

                    $process->stop();
                    return;
                }
                if ($stdErr->isVeryVerbose()) {
                    $stdErr->write($censor($buffer));
                }
            }
        };

        $stdErr->writeln(sprintf('Running command: <info>%s</info>', $censor($commandline)), OutputInterface::VERBOSITY_VERBOSE);

        $process->run($onOutput);

        if ($shouldRetry) {
            // Create a new curl process, replacing the access token.
            $commandline = $this->buildCurlCommand($url, $newToken, $input);
            $process = Process::fromShellCommandline($commandline);
            $shouldRetry = false;

            // Update the $token variable in the $censor closure.
            $token = $newToken;

            $stdErr->writeln(sprintf('Running command: <info>%s</info>', $censor($commandline)), OutputInterface::VERBOSITY_VERBOSE);
            $process->run($onOutput);
        }

        return $process->getExitCode();
    }

    /**
     * Builds a curl command with a URL and access token.
     *
     * @param string $url
     * @param string $token
     * @param InputInterface $input
     *
     * @return string
     */
    private function buildCurlCommand(string $url, string $token, InputInterface $input): string
    {
        $commandline = sprintf(
            'curl -H %s %s',
            escapeshellarg('Authorization: Bearer ' . $token),
            escapeshellarg($url),
        );

        $passThroughFlags = ['head', 'include', 'fail'];
        foreach ($passThroughFlags as $flag) {
            if ($input->getOption($flag)) {
                $commandline .= ' --' . $flag;
            }
        }

        // Set --fail-with-body by default.
        if (!$input->getOption('fail')) {
            $commandline .= ' --fail-with-body';
        }

        if ($requestMethod = $input->getOption('request')) {
            $commandline .= ' --request ' . escapeshellarg((string) $requestMethod);
        }

        if ($data = $input->getOption('json')) {
            if (\json_decode((string) $data) === null && \json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('The value of --json contains invalid JSON.');
            }
            $commandline .= ' --data ' . escapeshellarg((string) $data);
            $commandline .= ' --header ' . escapeshellarg('Content-Type: application/json');
            $commandline .= ' --header ' . escapeshellarg('Accept: application/json');
        }

        if ($data = $input->getOption('data')) {
            $commandline .= ' --data ' . escapeshellarg((string) $data);
        }

        if (!$input->getOption('disable-compression')) {
            $commandline .= ' --compressed';
        }

        if (!$input->getOption('enable-glob')) {
            $commandline .= ' --globoff';
        }

        foreach ($input->getOption('header') as $header) {
            $commandline .= ' --header ' . escapeshellarg((string) $header);
        }

        $commandline .= ' --no-progress-meter';

        return $commandline;
    }

    /**
     * Parses an HTTP response status code from cURL verbose output.
     *
     * @param string $buffer
     * @return int|null
     */
    private function parseCurlStatusCode(string $buffer): ?int
    {
        if (preg_match('#< HTTP/[1-3]+(?:\.[0-9]+)? ([1-5][0-9]{2})\s#', $buffer, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
