<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DocsCommand extends UrlCommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
          ->setName('docs')
          ->setDescription('Open the Platform.sh online documentation')
          ->addArgument('search', InputArgument::IS_ARRAY, 'Search term(s)');
        $this->addExample('Search for information about the CLI', 'CLI');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $url = 'https://docs.platform.sh';

        $search = $input->getArgument('search');
        if ($search) {
            $query = $this->getSearchQuery($search);

            // @todo provide native or other search options?
            //$url .= '/search?q=' . urlencode($term);

            // Use Google search.
            $url = 'https://www.google.com/search?q='
              . urlencode('site:docs.platform.sh ' . $query);
        }

        $this->openUrl($url, $input, $output);
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
