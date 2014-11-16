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
                $url = $e->getRequest()->getUrl();
                $reason = $response->getReasonPhrase();
                $code = $json['code'];
                $message = $json['message'];
                if (!empty($json['detail'])) {
                    $message .= "\n  " . $json['detail'];
                }
                // Re-throw the exception with a more detailed message.
                $exception = new ClientErrorResponseException(
                  "The Platform.sh API call failed.\nURL: $url\nError: $code $reason\nMessage:\n  $message",
                  $code
                );
                $exception->setRequest($e->getRequest());
                $exception->setResponse($response);
                throw $exception;
            }
            throw $e;
        }
    }

}
