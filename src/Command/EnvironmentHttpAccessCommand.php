<?php

namespace CommerceGuys\Platform\Cli\Command;

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
            ->setDescription('Control HTTP access for an environment')
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

        $auth_parts = explode(':', $auth, 2);
        if (count($auth_parts) != 2) {
            return false;
        }

        return array("username" => $auth_parts[0], "password" => $auth_parts[1]);
    }

    /**
     * @param $auth
     *
     * @return array|false
     */
    protected function parseAccess($auth) {
        if (empty($auth)) {
            return false;
        }

        $auth_parts = explode(':', $auth, 2);
        if (count($auth_parts) != 2) {
            return false;
        }

        return array("permission" => $auth_parts[0], "address" => $auth_parts[1]);
    }

    protected function validateInput(InputInterface $input, OutputInterface $output)
    {
        if (!parent::validateInput($input, $output)) {
            return false;
        }

        $valid = true;

        $this->auth   = $input->getOption('auth');
        $this->access = $input->getOption('access');

        if (empty($this->auth)) {
            $output->writeln('<error>The --auth option is required.</error>');
            $valid = false;
        }
        if (empty($this->access)) {
            $output->writeln('<error>The --access option is required.</error>');
            $valid = false;
        }

        foreach ($this->auth as $auth) {
            if (!$this->parseAuth($auth)) {
                $output->writeln(sprintf('Auth "<error>%s</error>" is not valid, please use the format: username:password', $auth));
                $valid = false;
            }
        }

        foreach ($this->access as $access) {
            if (!$this->parseAccess($access)) {
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

        $accessOpts["http_access"]["basic_auth"] = array();
        foreach ($this->auth as $auth) {
            $parsed = $this->parseAuth($auth);
            $accessOpts["http_access"]["basic_auth"][$parsed["username"]] = $parsed["password"];
        }

        $accessOpts["http_access"]["addresses"] = array();
        foreach ($this->access as $access) {
            $accessOpts["http_access"]["addresses"][] = $this->parseAccess($access);
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->modifyEnvironmentAccess($accessOpts);
        return 0;
    }
}
