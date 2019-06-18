<?php

namespace Platformsh\Cli\Command\Helper;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RouteUrlHelperCommand extends HelperCommandBase
{
    protected function configure() {
        $this->setName('helper:route-url')
            ->setDescription(sprintf('Extract URL(s) from %sROUTES', $this->config()->get('service.env_prefix')))
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Find by route ID')
            ->addOption('primary', null, InputOption::VALUE_NONE, 'Find the primary route')
            ->addOption('upstream', null, InputOption::VALUE_REQUIRED, 'Find by upstream ID')
            ->addOption('one', null, InputOption::VALUE_NONE, 'Return 1 URL (error if more than 1 is found)');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $routes = $this->getArrayEnvVar('ROUTES');
        if (empty($routes)) {
            $this->stdErr->writeln('No routes found.');

            return 1;
        }

        $matching = $routes;
        if ($input->getOption('primary')) {
            $matching = array_filter($routes, function (array $route) {
                return !empty($route['primary']);
            });
        }
        if ($id = $input->getOption('id')) {
            $matching = array_filter($routes, function (array $route) use ($id) {
                return !empty($route['id']) && $route['id'] === $id;
            });
        }
        if ($upstream = $input->getOption('upstream')) {
            $matching = array_filter($routes, function (array $route) use ($upstream) {
                return !empty($route['upstream']) && $route['upstream'] === $upstream;
            });
        }
        if (!count($matching)) {
            $this->stdErr->writeln('No matching routes found.');

            return 1;
        }

        // Extract and sort the route URLs.
        $urls = array_keys($matching);
        usort($urls, [$this->api(), 'urlSort']);

        $success = true;
        if ($input->getOption('one')) {
            if (count($urls) !== 1) {
                $this->stdErr->writeln(sprintf('%d route URLs found (1 requested)', count($urls)));
                $success = false;
            }

            $output->writeln(reset($urls) ?: '');
        } else {
            $output->writeln($urls);
        }

        return $success ? 0 : 1;
    }
}
