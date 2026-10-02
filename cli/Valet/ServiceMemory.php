<?php

declare(strict_types=1);

namespace Valet;

class ServiceMemory
{
    /**
     * Get the current RAM usage (in KB) for a systemd service.
     *
     * Tries `systemctl show <service> -p MemoryCurrent --value` first,
     * falls back to summing RSS from `ps -o rss` for the service's processes.
     *
     * Returns null if the service is not found or memory cannot be determined.
     *
     * @return int|null Memory in KB, or null if unavailable
     */
    public static function serviceRamKb(string $serviceName): ?int
    {
        // Try systemctl MemoryCurrent (systemd >= 235)
        $output = @shell_exec(sprintf(
            'systemctl show %s -p MemoryCurrent --value 2>/dev/null',
            escapeshellarg($serviceName)
        ));

        if ($output !== false && $output !== '') {
            $value = trim($output);
            if ($value !== '' && $value !== '[not set]' && is_numeric($value)) {
                // MemoryCurrent is in bytes, convert to KB
                return (int) ($value / 1024);
            }
        }

        // Fallback: sum RSS from ps for processes belonging to the service
        // This is approximate since we can't perfectly map pids to systemd units without cgroups
        $psOutput = @shell_exec(sprintf(
            'ps -o rss= -C %s 2>/dev/null',
            escapeshellarg($serviceName)
        ));

        if ($psOutput !== false && $psOutput !== '') {
            $totalKb = 0;
            foreach (explode("\n", trim($psOutput)) as $line) {
                $line = trim($line);
                if ($line !== '' && is_numeric($line)) {
                    $totalKb += (int) $line;
                }
            }
            if ($totalKb > 0) {
                return $totalKb;
            }
        }

        return null;
    }

    /**
     * Get the total RAM usage (in KB) for a PHP-FPM pool (master + workers).
     *
     * Sums RSS for all processes matching `php-fpm<version>` or `php<version>-fpm`.
     *
     * @return int|null Total RSS in KB, or null if no processes found
     */
    public static function fpmPoolRamKb(string $version): ?int
    {
        $versionNormalized = preg_replace('~[^\d]~', '', $version);
        $patterns = [
            "php-fpm{$versionNormalized}",
            "php{$versionNormalized}-fpm",
            "php-fpm{$version}",
        ];

        $totalKb = 0;
        $found = false;

        foreach ($patterns as $pattern) {
            $psOutput = @shell_exec(sprintf(
                'ps -o rss= -C %s 2>/dev/null',
                escapeshellarg($pattern)
            ));

            if ($psOutput !== false && $psOutput !== '') {
                foreach (explode("\n", trim($psOutput)) as $line) {
                    $line = trim($line);
                    if ($line !== '' && is_numeric($line)) {
                        $totalKb += (int) $line;
                        $found = true;
                    }
                }
            }
        }

        return $found ? $totalKb : null;
    }

    /**
     * Format bytes/KB into human-readable string.
     *
     * @param int $kb Value in kilobytes
     * @return string Human-readable (e.g., "12.5 MB")
     */
    public static function human(int $kb): string
    {
        $bytes = $kb * 1024;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}