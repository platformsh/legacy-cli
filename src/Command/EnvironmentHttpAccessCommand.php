<?php

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentHttpAccessCommand extends PlatformCommand
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

        list($permission, $address) = $parts;

        $this->validateAddress($address);

        // Normalize the address so that we can compare accurately with the
        // current value returned from the API.
        if ($address == 'any') {
            $address = '0.0.0.0/0';
        }
        elseif ($address && !strpos($address, '/')) {
            $address .= '/32';
        }

        return array("address" => $address, "permission" => $permission);
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

        if ($access) {
            $accessOpts['addresses'] = array();
            foreach (array_filter($access) as $access) {
                $accessOpts["addresses"][] = $this->parseAccess($access);
            }
        }

        if ($auth) {
            $accessOpts['basic_auth'] = array();
            foreach (array_filter($auth) as $auth) {
                $parsed = $this->parseAuth($auth);
                $accessOpts["basic_auth"][$parsed["username"]] = $parsed["password"];
            }
        }

        // Ensure the environment is refreshed.
        $selectedEnvironment = $this->getSelectedEnvironment();
        $selectedEnvironment->ensureFull();
        $environmentId = $selectedEnvironment['id'];

        if ($auth || $access) {
            $current = (array) $selectedEnvironment->getProperty('http_access');

            // Merge existing settings. Not using a reference here, as that
            // would affect the comparison with $current later.
            foreach ($current as $key => $value) {
                if (!isset($accessOpts[$key])) {
                    $accessOpts[$key] = $value;
                }
            }

            if ($current != $accessOpts) {

                // The API only accepts {} for an empty "basic_auth" value,
                // rather than [].
                if (isset($accessOpts['basic_auth']) && $accessOpts['basic_auth'] === array()) {
                    $accessOpts['basic_auth'] = (object) array();
                }

                // Patch the environment with the changes.
                $selectedEnvironment->update(array('http_access' => $accessOpts));

                $output->writeln("Updated HTTP access settings for the environment <info>$environmentId</info>:");

                $output->writeln($selectedEnvironment->getProperty('http_access'));

                // @todo
//                if (!$environment->hasActivity()) {
//                    $this->rebuildWarning($output);
//                }
                return 0;
            }
        }

        $output->writeln("HTTP access settings for the environment <info>$environmentId</info>:");
        $output->writeln($selectedEnvironment->getProperty('http_access'));
        return 0;
    }

}
