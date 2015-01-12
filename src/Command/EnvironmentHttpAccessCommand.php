<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentHttpAccessCommand extends EnvironmentCommand
{

    protected $auth;
    protected $access;

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('environment:http-access')
            ->setAliases(array('httpaccess'))
            ->setDescription('Update HTTP access settings for an environment')
            ->addOption('access', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Access restriction in the format "permission:address"')
            ->addOption('auth', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Authentication details in the format "username:password"');
        $this->addProjectOption()->addEnvironmentOption();
    }

    /**
     * @param $auth
     *
     * @return array|false
     */
    protected function parseAuth($auth) {
        if (empty($auth)) {
            return false;
        }

        $parts = explode(':', $auth, 2);
        if (count($parts) != 2) {
            return false;
        }

        return array("username" => $parts[0], "password" => $parts[1]);
    }

    /**
     * @param $access
     *
     * @return array|false
     */
    protected function parseAccess($access) {
        if (empty($access)) {
            return false;
        }

        $parts = explode(':', $access, 2);
        if (count($parts) != 2) {
            return false;
        }

        return array("permission" => $parts[0], "address" => $parts[1]);
    }

    protected function validateInput(InputInterface $input, OutputInterface $output)
    {
        if (!parent::validateInput($input, $output)) {
            return false;
        }

        $valid = true;

        $this->auth = $input->getOption('auth');
        $this->access = $input->getOption('access');

        if (!$this->auth && !$this->access) {
            $output->writeln('<error>You must specify at least one of --auth or --access</error>');
            $valid = false;
        }

        foreach ($this->auth as $auth) {
            if ($auth !== '0' && !$this->parseAuth($auth)) {
                $output->writeln(sprintf('Auth "<error>%s</error>" is not valid, please use the format: username:password', $auth));
                $valid = false;
            }
        }

        foreach ($this->access as $access) {
            if ($access !== '0' && !$this->parseAccess($access)) {
                $output->writeln(sprintf('Access "<error>%s</error>" is not valid, please use the format: permission:address', $access));
                $valid = false;
            }
        }

        return $valid;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $accessOpts = array();
        $accessOpts["http_access"] = array();

        if ($this->auth) {
            $accessOpts["http_access"]["basic_auth"] = new \stdClass();
            foreach ($this->auth as $auth) {
                if ($auth === '0') {
                    continue;
                }
                $parsed = $this->parseAuth($auth);
                $accessOpts["http_access"]["basic_auth"]->{$parsed["username"]} = $parsed["password"];
            }
        }

        if ($this->access) {
            $accessOpts["http_access"]["addresses"] = array();
            foreach ($this->access as $access) {
                if ($access === '0') {
                    continue;
                }
                $accessOpts["http_access"]["addresses"][] = $this->parseAccess($access);
            }
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);
        $environment->update($accessOpts);

        $environmentId = $this->environment['id'];
        $output->writeln("Updated HTTP access settings for the environment <info>$environmentId</info>");

        if (!$environment->hasActivity()) {
            $output->writeln(
              "<comment>"
              . "The remote environment must be rebuilt for the HTTP access change to take effect."
              . " Use 'git push' with new commit(s) to trigger a rebuild."
              . "</comment>"
            );
        }
        return 0;
    }
}
