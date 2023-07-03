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
        $envPrefix = $this->config->get('application.env_prefix');
        $message .= "\n\nPlease log in by running:\n    $executable login"
            . "\n\nAlternatively, to log in using an API token (without a browser), run: $executable auth:api-token-login"
            . "\n\nTo authenticate non-interactively, configure an API token using the {$envPrefix}TOKEN environment variable.";

        parent::__construct($message, $previous);
    }
}
