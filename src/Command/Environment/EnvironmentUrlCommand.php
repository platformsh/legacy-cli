<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\UrlCommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentUrlCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
          ->setName('environment:url')
          ->setAliases(array('url'))
          ->setDescription('Get the public URL of an environment')
          ->addArgument(
            'path',
            InputArgument::OPTIONAL,
            'A path to append to the URL.'
          );
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

        // Select a default.
        $url = $urls[0];

        // Allow the user to choose a URL.
        if ($input->getOption('browser') !== '0') {
            /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $url = $questionHelper->choose(array_combine($urls, $urls), 'Enter a number to choose a URL', $input, $output, $url);
        }

        $path = $input->getArgument('path');
        if ($path) {
            $url .= trim($path);
        }

        $this->openUrl($url, $input, $output);

        return 0;
    }
}
