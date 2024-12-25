<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Url;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'docs', description: 'Open the online documentation')]
class DocsCommand extends CommandBase
{
    public function __construct(private readonly Config $config, private readonly Url $url)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('search', InputArgument::IS_ARRAY, 'Search term(s)');
        $this->addExample('Search for information about the CLI', 'CLI');
        Url::configureInput($this->getDefinition());
    }

    public function isEnabled(): bool
    {
        return $this->config->has('service.docs_url')
            && $this->config->has('service.docs_search_url')
            && parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($searchArguments = $input->getArgument('search')) {
            $query = $this->getSearchQuery($searchArguments);
            $url = str_replace('{{ terms }}', rawurlencode($query), $this->config->getStr('service.docs_search_url'));
        } else {
            $url = $this->config->getStr('service.docs_url');
        }
        $this->url->openUrl($url);
        return 0;
    }

    /**
     * Turn a list of command arguments into a search query.
     *
     * Arguments containing a space would have been quoted on the command line,
     * so quotes are added again here.
     *
     * @param string[] $args
     *
     * @return string
     */
    protected function getSearchQuery(array $args): string
    {
        return implode(' ', array_map(fn($term) => strpos((string) $term, ' ') ? '"' . $term . '"' : $term, $args));
    }
}
