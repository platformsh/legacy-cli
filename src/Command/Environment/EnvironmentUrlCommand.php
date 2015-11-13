<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\UrlCommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('environment:url')
            ->setAliases(['url'])
            ->setDescription('Get the public URLs of an environment');
        $this->urlUtil->addBrowserOption($this->getDefinition());
        $this->urlUtil->addPipeOption($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();

        $urls = $selectedEnvironment->getRouteUrls();
        if (empty($urls)) {
            $output->writeln("No URLs found");
            return 1;
        }

        // Sort the URLs heuristically. Prefer short URLs with HTTPS.
        usort($urls, function ($a, $b) {
            $result = 0;
            if (parse_url($a, PHP_URL_SCHEME) === 'https') {
                $result -= 2;
            }
            if (parse_url($b, PHP_URL_SCHEME) === 'https') {
                $result += 2;
            }
            $result += strlen($a) <= strlen($b) ? -1 : 1;

            return $result;
        });

        // Just display the URLs if --browser is 0 or if --pipe is set.
        if ($input->getOption('pipe') || $input->getOption('browser') === '0') {
            $output->writeln($urls);
            return 0;
        }

        // Allow the user to choose a URL to open.
        /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $url = $questionHelper->choose(array_combine($urls, $urls), 'Enter a number to choose a URL', $urls[0]);

        $this->urlUtil->openUrl($url, $input, $output);

        return 0;
    }
}
