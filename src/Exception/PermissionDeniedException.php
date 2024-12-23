<?php

declare(strict_types=1);

namespace Platformsh\Cli\Exception;

class PermissionDeniedException extends HttpException
{
    protected $message = 'Permission denied.';
    protected $code = 6;
}
