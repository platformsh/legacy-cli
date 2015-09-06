<?php
namespace Platformsh\Cli\Command\Domain;

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
        $this->setHelp('See https://docs.platform.sh/use-platform/going-live.html#1-domains');
        $this->addExample('Add the domain example.com', 'example.com');
        $this->addExample(
          'Add the domain secure.example.com with SSL enabled',
          'secure.example.com --cert=/etc/ssl/private/secure-example-com.crt --key=/etc/ssl/private/secure-example-com.key'
        );
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
                           ->addDomain($this->domainName, $this->sslOptions);
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
