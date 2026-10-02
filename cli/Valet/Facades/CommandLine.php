<?php

namespace Valet\Facades;

/**
 * Class CommandLine.
 *
 * @method static void   quietly(string $command)
 * @method static void   quietlyAsUser(string $command)
 * @method static void   passthru(string $command)
 * @method static string run(string $command, callable $onError = null)
 * @method static array{output: string, errors: string, exitCode: int} runProcess(array $command, string $input = '', int|null $timeout = 300)
 * @method static string runAsUser(string $command, callable $onError = null)
 */
class CommandLine extends Facade
{
}
