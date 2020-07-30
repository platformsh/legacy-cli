<?php

namespace Platformsh\Cli\Exception;

use Platformsh\Cli\Service\Config;

class LoginRequiredException extends HttpException
{
    protected $message = 'Authentication is required.';
    protected $code = 3;
    private $config;

    public function __construct(
        $message = null,
        Config $config = null,
        $previous = null)
    {
        $message = $message ?: $this->message;
        $this->config = $config ?: new Config();
        $executable = $this->config->get('application.executable');
        $message .= "\n\nPlease log in by running:\n    <comment>$executable login</comment>"
            . "\n\nTo log in using an API token, run: <comment>$executable auth:api-token-login</comment>";

        parent::__construct($message, $previous);
    }
}
