<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentHttpAccessCommand extends EnvironmentCommand
{

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
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    protected function parseAuth($auth)
    {
        $parts = explode(':', $auth, 2);
        if (count($parts) != 2) {
            $message = sprintf('Auth "<error>%s</error>" is not valid. The format should be username:password', $auth);
            throw new \InvalidArgumentException($message);
        }

        if (!preg_match('#^[a-zA-Z0-9]{2,}$#', $parts[0])) {
            $message = sprintf('The username "<error>%s</error>" for --auth is not valid', $parts[0]);
            throw new \InvalidArgumentException($message);
        }

        $minLength = 6;
        if (strlen($parts[1]) < $minLength) {
            $message = sprintf('The minimum password length for --auth is %d characters', $minLength);
            throw new \InvalidArgumentException($message);
        }

        return array("username" => $parts[0], "password" => $parts[1]);
    }

    /**
     * @param $access
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    protected function parseAccess($access)
    {
        $parts = explode(':', $access, 2);
        if (count($parts) != 2) {
            $message = sprintf('Access "<error>%s</error>" is not valid, please use the format: permission:address', $access);
            throw new \InvalidArgumentException($message);
        }

        if (!in_array($parts[0], array('allow', 'deny'))) {
            $message = sprintf("The permission type '<error>%s</error>' is not valid; it must be one of 'allow' or 'deny'", $parts[0]);
            throw new \InvalidArgumentException($message);
        }

        $this->validateAddress($parts[1]);

        return array("permission" => $parts[0], "address" => $parts[1]);
    }

    /**
     * @param string $address
     *
     * @throws \InvalidArgumentException
     */
    protected function validateAddress($address)
    {
        if ($address == 'any') {
            return;
        }
        $extractIp = preg_match('#^([^/]+)(/([0-9]{1,2}))?$#', $address, $matches);
        if (!$extractIp || !filter_var($matches[1], FILTER_VALIDATE_IP) || (isset($matches[3]) && $matches[3] > 32)) {
            $message = sprintf('The address "<error>%s</error>" is not a valid IP address or CIDR', $address);
            throw new \InvalidArgumentException($message);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $auth = $input->getOption('auth');
        $access = $input->getOption('access');

        $accessOpts = array();
        $accessOpts["http_access"] = array();

        if ($access) {
            $accessOpts["http_access"]["addresses"] = array();
            foreach (array_filter($access) as $access) {
                $accessOpts["http_access"]["addresses"][] = $this->parseAccess($access);
            }
        }

        if ($auth) {
            $accessOpts["http_access"]["basic_auth"] = new \stdClass();
            foreach (array_filter($auth) as $auth) {
                $parsed = $this->parseAuth($auth);
                $accessOpts["http_access"]["basic_auth"]->{$parsed["username"]} = $parsed["password"];
            }
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);

        if ($auth || $access) {
            $environment->update($accessOpts);
            $environmentId = $this->environment['id'];
            $output->writeln("Updated HTTP access settings for the environment <info>$environmentId</info>");
            if (!$environment->hasActivity()) {
                $this->rebuildWarning($output);
            }
        }
        else {
            // Ensure the environment is refreshed.
            $environment->setData($this->getEnvironment($this->environment['id'], $this->project, true));
        }

        $output->writeln($environment->getPropertyFormatted('http_access'));
        return 0;
    }

}
