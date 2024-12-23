<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:http-access', description: 'Update HTTP access settings for an environment', aliases: ['httpaccess'])]
class EnvironmentHttpAccessCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this
            ->addOption(
                'access',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Access restriction in the format "permission:address". Use 0 to clear all addresses.',
            )
            ->addOption(
                'auth',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'HTTP Basic auth credentials in the format "username:password". Use 0 to clear all credentials.',
            )
            ->addOption(
                'enabled',
                null,
                InputOption::VALUE_REQUIRED,
                'Whether access control should be enabled: 1 to enable, 0 to disable',
            );
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Require a username and password', '--auth myname:mypassword');
        $this->addExample('Restrict access to only one IP address', '--access allow:69.208.1.192 --access deny:any');
        $this->addExample('Remove the password requirement, keeping IP restrictions', '--auth 0');
        $this->addExample('Disable all HTTP access control', '--enabled 0');
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return array{username: string, password: string}
     */
    protected function parseAuth(string $auth): array
    {
        $parts = explode(':', $auth, 2);
        if (count($parts) != 2) {
            $message = sprintf('Auth "<error>%s</error>" is not valid. The format should be username:password', $auth);
            throw new InvalidArgumentException($message);
        }

        if (!preg_match('#^[a-zA-Z0-9-_]{2,}$#', $parts[0])) {
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
     * @throws InvalidArgumentException
     *
     * @return array{address: string, permission: string}
     */
    protected function parseAccess(string $access): array
    {
        $parts = explode(':', $access, 2);
        if (count($parts) != 2) {
            $message = sprintf(
                'Access "<error>%s</error>" is not valid, please use the format: permission:address',
                $access,
            );
            throw new InvalidArgumentException($message);
        }

        if (!in_array($parts[0], ['allow', 'deny'])) {
            $message = sprintf(
                "The permission type '<error>%s</error>' is not valid; it must be one of 'allow' or 'deny'",
                $parts[0],
            );
            throw new InvalidArgumentException($message);
        }

        [$permission, $address] = $parts;

        $this->validateAddress($address);

        // Normalize the address so that we can compare accurately with the
        // current value returned from the API.
        if ($address == 'any') {
            $address = '0.0.0.0/0';
        } elseif ($address && !strpos($address, '/')) {
            $is_valid_ipv4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            $is_valid_ipv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

            if ($is_valid_ipv4) {
                $address .= '/32';
            }
            if ($is_valid_ipv6) {
                $address .= '/128';
            }
        }

        return ["address" => $address, "permission" => $permission];
    }

    /**
     * Validates an IP address.
     *
     * @throws InvalidArgumentException
     */
    protected function validateAddress(string $address): void
    {
        if ($address == 'any') {
            return;
        }
        $extractIp = preg_match('#^([^/]+)(/([0-9]{1,3}))?$#', $address, $matches);
        $is_valid_ip = $extractIp && filter_var($matches[1], FILTER_VALIDATE_IP);
        if (!$extractIp || !$is_valid_ip) {
            $message = sprintf('The address "<error>%s</error>" is not a valid IP address or CIDR', $address);
            throw new InvalidArgumentException($message);
        }
        $is_valid_ipv4 = filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $is_valid_ipv6 = filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        if ($is_valid_ipv4 && isset($matches[3]) && $matches[3] > 32) {
            $message = sprintf('The address "<error>%s</error>" is not a valid IPv4 address or CIDR', $address);
            throw new InvalidArgumentException($message);
        }
        if ($is_valid_ipv6 && isset($matches[3]) && $matches[3] > 128) {
            $message = sprintf('The address "<error>%s</error>" is not a valid IPv6 address or CIDR', $address);
            throw new InvalidArgumentException($message);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $auth = $input->getOption('auth');
        $access = $input->getOption('access');

        $accessOpts = [];
        $change = false;

        $enabled = $input->getOption('enabled');
        if ($enabled !== null) {
            $change = true;
            $accessOpts['is_enabled'] = !in_array($enabled, ['0', 'false']);
        }

        if ($access === ['0']) {
            $accessOpts['addresses'] = null;
            $change = true;
        } elseif ($access !== []) {
            $accessOpts['addresses'] = [];
            foreach (array_filter($access) as $access) {
                $accessOpts['addresses'][] = $this->parseAccess($access);
            }
            $change = true;
        }

        if ($auth === ['0']) {
            $accessOpts['basic_auth'] = null;
            $change = true;
        } elseif ($auth !== []) {
            foreach (array_filter($auth) as $auth) {
                $parsed = $this->parseAuth($auth);
                $accessOpts['basic_auth'][$parsed['username']] = $parsed['password'];
            }
            $change = true;
        }

        $selectedEnvironment = $selection->getEnvironment();
        $environmentId = $selectedEnvironment->id;

        // Patch the environment with the changes.
        if ($change) {
            $result = $selectedEnvironment->update(['http_access' => $accessOpts]);
            $this->api->clearEnvironmentsCache($selectedEnvironment->project);

            $this->stdErr->writeln("Updated HTTP access settings for the environment <info>$environmentId</info>:");

            $output->writeln($this->propertyFormatter->format($selectedEnvironment->http_access, 'http_access'));

            $success = true;
            if (!$result->countActivities()) {
                $this->api->redeployWarning();
            } elseif ($this->activityMonitor->shouldWait($input)) {
                $activityMonitor = $this->activityMonitor;
                $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            }

            return $success ? 0 : 1;
        }

        $this->stdErr->writeln("HTTP access settings for the environment <info>$environmentId</info>:");
        $output->writeln($this->propertyFormatter->format($selectedEnvironment->http_access, 'http_access'));

        return 0;
    }
}
