<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentHttpaccessCommand extends EnvironmentCommand
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('environment:httpaccess')
            ->setAliases(array('httpaccess'))
            ->setDescription('HTTP access control')
            ->addOption('access', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Help.')
            ->addOption('auth', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Help.');
        $this->addProjectOption()->addEnvironmentOption();
    }

    private function parse_auth($auth) {
        if (empty($auth)) {
            return false;
        }

        $auth_parts = explode(':', $auth, 2);
        if (count($auth_parts) != 2) {
            return false;
        }

        return array("username" => $auth_parts[0], "password" => $auth_parts[1]);
    }

    private function parse_access($auth) {
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

        $this->auth   = $input->getOption('auth');
        $this->access = $input->getOption('access');

        if (empty($this->auth) && empty($this->access)) {
            $output->writeln("<error>You must specify the authentication or access.</error>");
            return false;
        }

        if (!empty($this->auth)) {
            foreach ($this->auth as $auth) {
                if (!$this->parse_auth($auth)) {
                    throw new \InvalidArgumentException(sprintf('Auth "%s" is not valid, please use "username:password" format.', $auth));
                }
            }
        }
        if (!empty($this->access)) {
            foreach ($this->access as $access) {
                if (!$this->parse_access($access)) {
                    throw new \InvalidArgumentException(sprintf('Access "%s" is not valid, please use "permission:address" format.', $auth));
                }
            }
        }

        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $accessOpts = array();
        $accessOpts["http_access"] = array();
        if (!empty($this->auth)) {
            $accessOpts["http_access"]["basic_auth"] = array();
            foreach ($this->auth as $auth) {
                $accessOpts["http_access"]["basic_auth"][$this->parse_auth($auth)["username"]] = $this->parse_auth($auth)["password"];
            }
        }
        if (!empty($this->access)) {
            $accessOpts["http_access"]["addresses"] = array();
            foreach ($this->access as $access) {
                array_push($accessOpts["http_access"]["addresses"], $this->parse_access($access));
            }
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->modifyEnvironmentAccess($accessOpts);
    }
}
