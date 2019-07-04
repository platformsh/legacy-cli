<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Route;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:url')
            ->setAliases(['url'])
            ->setDescription('Get the public URLs of an environment');
        Url::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_ROUTES.
        $prefix = $this->config()->get('service.env_prefix');
        if (getenv($prefix . 'ROUTES') && !$this->doesEnvironmentConflictWithCommandLine($input)) {
            $this->debug('Reading URLs from environment variable ' . $prefix . 'ROUTES');
            $decoded = json_decode(base64_decode(getenv($prefix . 'ROUTES'), true), true);
            if (empty($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'ROUTES');
            }
            $routes = Route::fromVariables($decoded);
        } else {
            $this->debug('Reading URLs from the API');
            $this->validateInput($input);
            $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());
            $routes = Route::fromDeploymentApi($deployment->routes);
        }
        if (empty($routes)) {
            $output->writeln("No URLs found");
            return 1;
        }

        usort($urls, [$this->api(), 'urlSort']);

        // Just display the URLs if --browser is 0 or if --pipe is set.
        if ($input->getOption('pipe') || $input->getOption('browser') === '0') {
            $output->writeln($urls);
            return 0;
        }

        // Allow the user to choose a URL to open.
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $url = $questionHelper->choose(array_combine($urls, $urls), 'Enter a number to open a URL', $urls[0]);

        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        $urlService->openUrl($url);

        return 0;
    }
}
