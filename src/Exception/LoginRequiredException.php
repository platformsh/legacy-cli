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
        $message .= "\n\nPlease log in by running:\n    <comment>$executable login</comment>";
        if ($aHelp = $this->getApiTokenHelp()) {
            $message .= "\n\n" . $aHelp;
        }

        parent::__construct($message, $previous);
    }

    /**
     * @return string|null
     */
    private function getApiTokenHelp()
    {
        if ($this->config->has('service.api_token_help_url')) {
            return 'To authenticate non-interactively using an API token, see:'
                . "\n    " . $this->config->get('service.api_token_help_url');
        }

        return null;
    }
}
