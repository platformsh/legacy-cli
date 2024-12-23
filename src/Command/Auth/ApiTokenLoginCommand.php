<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Service\Login;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\TokenConfig;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Utils;
use League\OAuth2\Client\Token\AccessToken;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\OAuth2\Client\Grant\ApiToken;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'auth:api-token-login', description: 'Log in using an API token')]
class ApiTokenLoginCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Login $login, private readonly QuestionHelper $questionHelper, private readonly TokenConfig $tokenConfig)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $service = $this->config->getStr('service.name');
        $executable = $this->config->getStr('application.executable');

        $help = 'Use this command to log in to your ' . $service . ' account using an API token.';
        if ($this->config->has('service.register_url')) {
            $help .= "\n\nYou can create an account at:\n    <info>" . $this->config->getStr('service.register_url') . '</info>';
        }
        if ($this->config->has('service.api_tokens_url')) {
            $help .= "\n\nIf you have an account, but you do not already have an API token, you can create one here:\n    <info>"
                . $this->config->getStr('service.api_tokens_url') . '</info>';
        }
        $help .= "\n\nAlternatively, to log in to the CLI with a browser, run:\n    <info>" . $executable . ' auth:browser-login</info>';
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->api->hasApiToken(false)) {
            $this->stdErr->writeln('An API token is already set via config');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            $this->stdErr->writeln("\n" . $this->login->getNonInteractiveAuthHelp('comment'));
            return 1;
        }

        $validator = function (string $apiToken): string {
            $apiToken = trim($apiToken);
            if (!strlen($apiToken)) {
                throw new \RuntimeException('The token cannot be empty');
            }

            try {
                $provider = $this->api->getClient()->getConnector()->getOAuth2Provider();
                $token = $provider->getAccessToken(new ApiToken(), [
                    'api_token' => $apiToken,
                ]);
            } catch (BadResponseException $e) {
                if ($this->exceptionMeansInvalidToken($e)) {
                    throw new \RuntimeException('Invalid API token');
                }
                throw $e;
            }

            // Finalise login.
            $this->stdErr->writeln('');
            $this->stdErr->writeln('The API token is valid.');
            $this->saveTokens($apiToken, $token);

            return $apiToken;
        };
        $question = new Question("Please enter an API token:\n> ");
        $question->setValidator($validator);
        $question->setMaxAttempts(5);
        $question->setHidden(true);
        $this->questionHelper->ask($input, $output, $question);

        $this->login->finalize();

        return 0;
    }

    /**
     * Saves the new tokens and safely logs out of the previous session.
     *
     * @param string      $apiToken
     * @param AccessToken $accessToken
     */
    private function saveTokens(string $apiToken, AccessToken $accessToken): void
    {
        $this->api->logout();
        $this->tokenConfig->storage()->storeToken($apiToken);

        $this->api
            ->getClient(false, true)
            ->getConnector()
            ->saveToken($accessToken);
    }

    /**
     * @param \Exception $e
     *
     * @return bool
     */
    private function exceptionMeansInvalidToken(\Exception $e): bool
    {
        if (!$e instanceof BadResponseException || !in_array($e->getResponse()->getStatusCode(), [400, 401], true)) {
            return false;
        }
        $json = (array) Utils::jsonDecode((string) $e->getResponse()->getBody(), true);
        // Compatibility with legacy auth provider.
        if (isset($json['error'], $json['error_description'])
            && $json['error'] === 'invalid_grant'
            && stripos((string) $json['error_description'], 'Invalid API token') !== false) {
            return true;
        }
        // Compatibility with new auth provider.
        if (isset($json['error'], $json['error_hint'])
            && $json['error'] === 'request_unauthorized'
            && stripos((string) $json['error_hint'], 'API token') !== false) {
            return true;
        }

        return false;
    }
}
