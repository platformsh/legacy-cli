<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DocsCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('docs')
            ->setDescription('Open the online documentation')
            ->addArgument('search', InputArgument::IS_ARRAY, 'Search term(s)');
        $this->addExample('Search for information about the CLI', 'CLI');
        Url::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = $this->config()->get('service.docs_url');

        $search = $input->getArgument('search');
        if ($search) {
            $query = $this->getSearchQuery($search);

            // @todo provide native or other search options?
            //$url .= '/search?q=' . urlencode($term);

            // Use Google search.
            $hostname = parse_url($this->config()->get('service.docs_url'), PHP_URL_HOST);
            $url = 'https://www.google.com/search?q='
                . urlencode('site:' . $hostname . ' ' . $query);
        }

        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        $urlService->openUrl($url);
    }

    /**
     * @param array $args
     *
     * @return string
     */
    protected function getSearchQuery(array $args)
    {
        $quoted = array_map([$this, 'quoteTerm'], $args);

        return implode(' ', $quoted);
    }

    /**
     * @param string $term
     *
     * @return string
     */
    public function quoteTerm($term)
    {
        return strpos($term, ' ') ? '"' . $term . '"' : $term;
    }
}
