<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCurlCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('project:curl')
            ->setDescription("Run a cURL request on a project's API")
            ->addArgument('path', InputArgument::OPTIONAL, 'The API path')
            ->addOption('request', 'X', InputOption::VALUE_REQUIRED, 'The request method to use')
            ->addOption('data', 'd', InputOption::VALUE_REQUIRED, 'Data to send')
            ->addOption('include', 'i', InputOption::VALUE_NONE, 'Include headers in the output')
            ->addOption('head', 'I', InputOption::VALUE_NONE, 'Fetch headers only')
            ->addOption('header', 'H', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Extra header(s)');
        $this->addProjectOption();
        $this->addExample('Change the project title', '-X PATCH -d \'{"title": "New title"}\'');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $url = $this->getSelectedProject()->getUri();

        if ($path = $input->getArgument('path')) {
            if (parse_url($path, PHP_URL_HOST)) {
                $this->stdErr->writeln(sprintf('Invalid path: <error>%s</error>', $path));

                return 1;
            }
            $url .= '/' . ltrim($path, '/');
        }

        $token = $this->api()->getAccessToken();
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

        foreach ($input->getOption('header') as $header) {
            $commandline .= ' --header ' . escapeshellarg($header);
        }

        if ($output->isVeryVerbose()) {
            $commandline .= ' --verbose';
        } else {
            $commandline .= ' --silent --show-error';
        }

        $this->stdErr->writeln(sprintf('Running command: <info>%s</info>', str_replace($token, '[token]', $commandline)), OutputInterface::VERBOSITY_VERBOSE);

        $process = proc_open($commandline, [STDIN, STDOUT, STDERR], $pipes);

        return proc_close($process);
    }
}
