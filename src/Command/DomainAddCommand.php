<?php

namespace Platformsh\Cli\Command;

use GuzzleHttp\Exception\ClientException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends DomainCommand
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('domain:add')
          ->setDescription('Add a new domain to the project');
        $this->addProjectOption();
        $this->addDomainOptions();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        if (!$this->validateDomainInput($input)) {
            return 1;
        }

        try {
            $domain = $this->getSelectedProject()
                           ->addDomain($this->domainName, $this->wildcard, $this->sslOptions);
        }
        catch (ClientException $e) {
            // Catch 409 Conflict errors.
            $response = $e->getResponse();
            if ($response && $response->getStatusCode() === 409) {
                $this->stdErr->writeln("The domain <error>{$this->domainName}</error> already exists on the project.");
                $this->stdErr->writeln("Use <info>domain:delete</info> to delete an existing domain");
                return 1;
            }

            throw $e;
        }

        $this->stdErr->writeln("The domain <info>{$this->domainName}</info> was successfully added to the project.");

        $this->displayDomain($domain, $this->stdErr);

        return 0;
    }
}
