<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends CommandBase
{
    protected static $defaultName = 'environment:url';

    private $api;
    private $questionHelper;
    private $selector;
    private $url;

    public function __construct(
        Api $api,
        QuestionHelper $questionHelper,
        Selector $selector,
        Url $url
    ) {
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->url = $url;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['url'])
            ->setDescription('Get the public URLs of an environment');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->url->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();

        $urls = $environment->getRouteUrls();
        if (empty($urls)) {
            $output->writeln("No URLs found");
            return 1;
        }

        usort($urls, [$this->api, 'urlSort']);

        // Just display the URLs if --browser is 0 or if --pipe is set.
        if ($input->getOption('pipe') || $input->getOption('browser') === '0') {
            $output->writeln($urls);
            return 0;
        }

        // Allow the user to choose a URL to open.
        $url = $this->questionHelper->choose(array_combine($urls, $urls), 'Enter a number to open a URL', $urls[0]);

        $this->url->openUrl($url);

        return 0;
    }
}
