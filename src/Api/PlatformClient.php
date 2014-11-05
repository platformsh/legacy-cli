<?php

namespace CommerceGuys\Platform\Cli\Api;

use Guzzle\Http\Exception\ClientErrorResponseException;
use Guzzle\Service\Client;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Common\Exception\ExceptionCollection;

class PlatformClient extends Client
{

    /**
     * @{inheritdoc}
     *
     * Catch ClientErrorResponseException to alter the message.
     */
    public function send($requests)
    {
        if (!($requests instanceof RequestInterface)) {
            return $this->sendMultiple($requests);
        }

        try {
            try {
                /** @var $requests RequestInterface */
                $this->getCurlMulti()
                     ->add($requests)
                     ->send();

                return $requests->getResponse();
            } catch (ExceptionCollection $e) {
                throw $e->getFirst();
            }
        }
        catch (ClientErrorResponseException $e) {
            $response = $e->getResponse();
            if ($response && ($json = $response->json())) {
                $reason = $response->getReasonPhrase();
                $code = $json['code'];
                $message = $json['message'];
                if (!empty($json['detail'])) {
                    $message .= "\n  " . $json['detail'];
                }
                throw new \RuntimeException("The Platform.sh API call failed.\nError: $code $reason\nMessage:\n  $message");
            }
            throw $e;
        }
    }

}
