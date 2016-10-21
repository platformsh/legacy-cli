<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainAddCommand extends DomainCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:add')
            ->setDescription('Add a new domain to the project');
        $this->addDomainOptions();
        $this->addProjectOption()->addNoWaitOption();
        $this->addExample('Add the domain example.com', 'example.com');
        $this->addExample(
            'Add the domain secure.example.com with SSL enabled',
            'secure.example.com --cert secure-example-com.crt --key secure-example-com.key'
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

        $project = $this->getSelectedProject();

        try {
            $this->stdErr->writeln("Adding the domain <info>{$this->domainName}</info>");
            $result = $project->addDomain($this->domainName, $this->sslOptions);
        }
        catch (ClientException $e) {
            // Catch 409 Conflict errors.
            $response = $e->getResponse();
            if ($response && $response->getStatusCode() === 409) {
                $this->stdErr->writeln("The domain <error>{$this->domainName}</error> already exists on the project.");
                $this->stdErr->writeln("Use <info>domain:delete</info> to delete an existing domain");
            }
            else {
                $this->handleApiException($e, $project);
            }

            return 1;
        }

        if (!$input->getOption('no-wait')) {
            ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $project);
        }

        return 0;
    }
}
