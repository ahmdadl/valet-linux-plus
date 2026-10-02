<?php

namespace Valet\Facades;

/**
 * Class DashboardJob.
 *
 * @method static array{id: string, status: string} start(string $slug, array $params = [])
 * @method static array<string, mixed>|null find(string $id)
 * @method static string log(string $id)
 * @method static array<int, array<string, mixed>> recent(int $limit = 10)
 * @method static bool markRunning(string $id)
 * @method static bool cancel(string $id)
 * @method static void finish(string $id, bool $ok, string $message, array $data = [])
 * @method static int prune()
 */
class DashboardJob extends Facade
{
}
