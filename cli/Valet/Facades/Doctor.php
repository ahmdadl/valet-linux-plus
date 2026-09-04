<?php

namespace Valet\Facades;

/**
 * Class Doctor.
 *
 * @method static array{diagnosis: array<string, mixed>, actions: array<int, array{id: string, description: string, status: string, detail?: string}>} run(bool $fix = false, bool $dryRun = false, bool $json = false)
 * @method static array<int, array{id: string, description: string, status: string, detail?: string}> planFixes(array $diagnosis)
 * @method static array<int, array{id: string, description: string, status: string, detail?: string}> applyFixes(array $actions)
 */
class Doctor extends Facade
{
}
