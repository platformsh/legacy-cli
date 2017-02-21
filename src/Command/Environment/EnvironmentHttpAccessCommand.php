<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentHttpAccessCommand extends CommandBase
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('environment:http-access')
            ->setAliases(['httpaccess'])
            ->setDescription('Update HTTP access settings for an environment')
            ->addOption(
                'access',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Access restriction in the format "permission:address"'
            )
            ->addOption(
                'auth',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Authentication details in the format "username:password"'
            )
            ->addOption(
                'enabled',
                null,
                InputOption::VALUE_REQUIRED,
                'Whether access control should be enabled: 1 to enable, 0 to disable'
            );
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->addExample('Require a username and password', '--auth myname:mypassword');
        $this->addExample('Restrict access to only one IP address', '--access deny:any --access allow:69.208.1.192');
        $this->addExample('Remove the password requirement, keeping IP restrictions', '--auth 0');
        $this->addExample('Disable all HTTP access control', '--enabled 0');
    }

    /**
     * @param $auth
     *
     * @throws InvalidArgumentException
     *
     * @return array
     */
    protected function parseAuth($auth)
    {
        $parts = explode(':', $auth, 2);
        if (count($parts) != 2) {
            $message = sprintf('Auth "<error>%s</error>" is not valid. The format should be username:password', $auth);
            throw new InvalidArgumentException($message);
        }

        if (!preg_match('#^[a-zA-Z0-9]{2,}$#', $parts[0])) {
            $message = sprintf('The username "<error>%s</error>" for --auth is not valid', $parts[0]);
            throw new InvalidArgumentException($message);
        }

        $minLength = 6;
        if (strlen($parts[1]) < $minLength) {
            $message = sprintf('The minimum password length for --auth is %d characters', $minLength);
            throw new InvalidArgumentException($message);
        }

        return ["username" => $parts[0], "password" => $parts[1]];
    }

    /**
     * @param $access
     *
     * @throws InvalidArgumentException
     *
     * @return array
     */
    protected function parseAccess($access)
    {
        $parts = explode(':', $access, 2);
        if (count($parts) != 2) {
            $message = sprintf(
                'Access "<error>%s</error>" is not valid, please use the format: permission:address',
                $access
            );
            throw new InvalidArgumentException($message);
        }

        if (!in_array($parts[0], ['allow', 'deny'])) {
            $message = sprintf(
                "The permission type '<error>%s</error>' is not valid; it must be one of 'allow' or 'deny'",
                $parts[0]
            );
            throw new InvalidArgumentException($message);
        }

        list($permission, $address) = $parts;

        $this->validateAddress($address);

        // Normalize the address so that we can compare accurately with the
        // current value returned from the API.
        if ($address == 'any') {
            $address = '0.0.0.0/0';
        } elseif ($address && !strpos($address, '/')) {
            $address .= '/32';
        }

        return ["address" => $address, "permission" => $permission];
    }

    /**
     * @param string $address
     *
     * @throws InvalidArgumentException
     */
    protected function validateAddress($address)
    {
        if ($address == 'any') {
            return;
        }
        $extractIp = preg_match('#^([^/]+)(/([0-9]{1,2}))?$#', $address, $matches);
        if (!$extractIp || !filter_var($matches[1], FILTER_VALIDATE_IP) || (isset($matches[3]) && $matches[3] > 32)) {
            $message = sprintf('The address "<error>%s</error>" is not a valid IP address or CIDR', $address);
            throw new InvalidArgumentException($message);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $auth = $input->getOption('auth');
        $access = $input->getOption('access');

        $accessOpts = [];

        $enabled = $input->getOption('enabled');
        if ($enabled !== null) {
            $accessOpts['is_enabled'] = !in_array($enabled, ['0', 'false']);
        }

        if ($access) {
            $accessOpts['addresses'] = [];
            foreach (array_filter($access) as $access) {
                $accessOpts["addresses"][] = $this->parseAccess($access);
            }
        }

        if ($auth) {
            $accessOpts['basic_auth'] = [];
            foreach (array_filter($auth) as $auth) {
                $parsed = $this->parseAuth($auth);
                $accessOpts["basic_auth"][$parsed["username"]] = $parsed["password"];
            }
        }

        // Ensure the environment is refreshed.
        $selectedEnvironment = $this->getSelectedEnvironment();
        $selectedEnvironment->ensureFull();
        $environmentId = $selectedEnvironment->id;

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        if (!empty($accessOpts)) {
            $current = (array) $selectedEnvironment->http_access;

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
                if (isset($accessOpts['basic_auth']) && $accessOpts['basic_auth'] === []) {
                    $accessOpts['basic_auth'] = (object) [];
                }

                // Patch the environment with the changes.
                $result = $selectedEnvironment->update(['http_access' => $accessOpts]);
                $this->api()->clearEnvironmentsCache($selectedEnvironment->project);

                $this->stdErr->writeln("Updated HTTP access settings for the environment <info>$environmentId</info>:");

                $output->writeln($formatter->format($selectedEnvironment->http_access, 'http_access'));

                $success = true;
                if (!$result->countActivities()) {
                    $this->rebuildWarning();
                } elseif (!$input->getOption('no-wait')) {
                    /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
                    $activityMonitor = $this->getService('activity_monitor');
                    $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
                }

                return $success ? 0 : 1;
            }
        }

        $this->stdErr->writeln("HTTP access settings for the environment <info>$environmentId</info>:");
        $output->writeln($formatter->format($selectedEnvironment->http_access, 'http_access'));

        return 0;
    }
}
