<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Utils;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auth:verify-phone-number', description: 'Verify your phone number interactively')]
class VerifyPhoneNumberCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }
    public function isEnabled(): bool
    {
        if (!$this->config->getBool('api.user_verification')) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            return 1;
        }
        $myUser = $this->api->getUser(null, true);

        if ($myUser->phone_number_verified) {
            $this->stdErr->writeln('Your user account already has a verified phone number.');
            return 0;
        }

        $defaultRegion = $myUser->country ?: null;

        $methods = ['sms' => 'SMS (default)', 'whatsapp' => 'WhatsApp message', 'call' => 'Call'];
        $channel = $this->questionHelper->choose($methods, 'Enter a number to choose a phone number verification method:', 'sms');

        $phoneUtil = PhoneNumberUtil::getInstance();
        $number = $this->questionHelper->askInput('Please enter your phone number', null, [], function ($number) use ($phoneUtil, $defaultRegion) {
            try {
                $parsed = $phoneUtil->parse($number, $defaultRegion);
            } catch (NumberParseException $e) {
                throw new InvalidArgumentException($e->getMessage());
            }
            if (!$phoneUtil->isValidNumber($parsed)) {
                throw new InvalidArgumentException('The phone number is not valid.');
            }
            return $phoneUtil->format($parsed, PhoneNumberFormat::E164);
        });

        $this->stdErr->writeln('');

        $this->io->debug('E164-formatted number: ' . $number);

        $httpClient = $this->api->getHttpClient();

        $response = $httpClient->post('/users/' . rawurlencode($myUser->id) . '/phonenumber', [
            'json' => [
                'channel' => $channel,
                'phone_number' => $number,
            ],
        ]);
        /** @var array{sid: string} $data */
        $data = (array) Utils::jsonDecode((string) $response->getBody(), true);
        $sid = $data['sid'];

        if ($channel === 'call') {
            $this->stdErr->writeln('Calling the number <info>' . $number . '</info> with a verification code.');
        } elseif ($channel === 'sms') {
            $this->stdErr->writeln('A verification code has been sent using SMS to the number: <info>' . $number . '</info>');
        } elseif ($channel === 'whatsapp') {
            $this->stdErr->writeln('A verification code has been sent using WhatsApp to the number: <info>' . $number . '</info>');
        }

        $this->stdErr->writeln('');

        $this->questionHelper->askInput('Please enter the verification code', null, [], function ($code) use ($httpClient, $sid, $myUser): void {
            if (!is_numeric($code)) {
                throw new InvalidArgumentException('Invalid verification code');
            }
            try {
                $httpClient->post('/users/' . rawurlencode($myUser->id) . '/phonenumber/' . rawurlencode($sid), [
                    'json' => ['code' => $code],
                ]);
            } catch (BadResponseException $e) {
                if (($response = $e->getResponse()) && $response->getStatusCode() === 400) {
                    $detail = (array) Utils::jsonDecode((string) $response->getBody(), true);
                    throw new InvalidArgumentException(isset($detail['error']) ? ucfirst((string) $detail['error']) : 'Invalid verification code');
                }
                throw $e;
            }
        });

        $this->io->debug('Refreshing phone verification status');
        $response = $httpClient->post('/me/verification?force_refresh=1');
        $needsVerify = (array) Utils::jsonDecode((string) $response->getBody(), true);
        $this->stdErr->writeln('');

        if ($needsVerify['type'] === 'phone') {
            $this->stdErr->writeln('Phone verification succeeded but the status check failed.');
            return 1;
        }

        $this->stdErr->writeln('Your phone number has been successfully verified.');
        return 0;
    }
}
