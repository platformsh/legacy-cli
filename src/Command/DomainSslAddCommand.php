<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainSslAddCommand extends PlatformCommand
{
    protected function configure()
    {
        $this
            ->setName('domain:ssl-add')
            ->setDescription('Add your SSL certificate to a domain.')
            ->addArgument(
                'domain',
                InputArgument::OPTIONAL,
                'The domain to attach the SSL certificate to.'
            )
            ->addArgument(
                'certificate',
                InputArgument::OPTIONAL,
                'The properly formatted file which contains the SSL certificate.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $domain = $input->getArgument('domain');
        if (empty($domain)) {
            $output->writeln("<error>You must specify a domain name.</error>");
            return;
        }

        // @todo: Check that there is a domain attached.

        $certificate = $input->getArgument('certificate');
        if (empty($certificate)) {
            $output->writeln("<error>You must specify a properly formatted file which contains your SSL certificate.</error>");
            return;
        }

        // @todo: Check that the file is properly formatted.

        //$domain = $this->getDomain($domain);
        $domain = "This is my domain";

        if (!$domain) {
            $output->writeln("<error>Domain not found.</error>");
            return;
        }

        $message = '<info>';
        $message = "\nThe SSL certificate for the domain #$domain has been added. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
