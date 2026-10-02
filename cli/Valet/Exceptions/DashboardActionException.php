<?php

namespace Valet\Exceptions;

/**
 * Thrown when a dashboard action is rejected before it reaches a handler.
 *
 * These are user-facing problems (unknown action, bad parameters, failed
 * security checks) rather than internal errors, so server.php reports them
 * with a 4xx status and the raw message.
 */
class DashboardActionException extends \Exception
{
}
