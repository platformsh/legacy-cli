<?php
namespace Platformsh\Cli\Command\Auth;

use GuzzleHttp\Exception\BadResponseException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VerifyPhoneNumberCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('auth:verify-phone-number')
            ->setDescription('Verify your phone number interactively');
    }

    public function isEnabled()
    {
        $config = $this->config();
        if (!$config->getWithDefault('api.user_verification', false)
            || !$config->getWithDefault('api.auth', false)
            || !$config->getWithDefault('api.base_url', '')) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            return 1;
        }
        $myUser = $this->api()->getUser(null, true);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if ($myUser->phone_number_verified) {
            $this->stdErr->writeln('Your user account already has a verified phone number.');
            return 0;
        }

        $defaultRegion = $myUser->country ?: null;

        $methods = ['sms' => 'SMS (default)', 'whatsapp' => 'WhatsApp message', 'call' => 'Call'];
        $channel = $questionHelper->choose($methods, 'Enter a number to choose a phone number verification method:', 'sms');

        $phoneUtil = PhoneNumberUtil::getInstance();
        $number = $questionHelper->askInput('Please enter your phone number', null, [], function ($number) use ($phoneUtil, $defaultRegion) {
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

        $this->debug('E164-formatted number: ' . $number);

        $httpClient = $this->api()->getHttpClient();

        $sid = $httpClient->post('/users/' . rawurlencode($myUser->id) . '/phonenumber', [
            'json' => [
                'channel' => $channel,
                'phone_number' => $number,
            ],
        ])->json()['sid'];

        if ($channel === 'call') {
            $this->stdErr->writeln('Calling the number <info>' . $number . '</info> with a verification code.');
        } elseif ($channel === 'sms') {
            $this->stdErr->writeln('A verification code has been sent using SMS to the number: <info>' . $number . '</info>');
        } elseif ($channel === 'whatsapp') {
            $this->stdErr->writeln('A verification code has been sent using WhatsApp to the number: <info>' . $number . '</info>');
        }

        $this->stdErr->writeln('');

        $questionHelper->askInput('Please enter the verification code', null, [], function ($code) use ($httpClient, $sid , $myUser) {
            if (!is_numeric($code)) {
                throw new InvalidArgumentException('Invalid verification code');
            }
            try {
                $httpClient->post('/users/' . rawurlencode($myUser->id) . '/phonenumber/' . rawurlencode($sid), [
                    'json' => ['code' => $code],
                ]);
            } catch (BadResponseException $e) {
                if (($response = $e->getResponse()) && $response->getStatusCode() === 400) {
                    $detail = $response->json();
                    throw new InvalidArgumentException(isset($detail['error']) ? ucfirst($detail['error']) : 'Invalid verification code');
                }
                throw $e;
            }
        });

        $this->debug('Refreshing phone verification status');
        $needsVerify = $httpClient->post( '/me/verification?force_refresh=1')->json();
        $this->stdErr->writeln('');

        if ($needsVerify['type'] == 'phone') {
            $this->stdErr->writeln('Phone verification succeeded but the status check failed.');
            return 1;
        }

        $this->stdErr->writeln('Your phone number has been successfully verified.');
        return 0;
    }
}
