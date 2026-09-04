<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Facades\Environment as EnvironmentFacade;

class DatabaseGui
{
    public function __construct(
        public Configuration $config,
        public CommandLine $cli
    ) {
    }

    /**
     * Print a database connection URL.
     */
    public function url(bool $pg = false): string
    {
        return EnvironmentFacade::databaseUrl($pg);
    }

    /**
     * Open a database GUI. Adminer is the supported browser GUI.
     */
    public function open(string $gui = 'adminer', bool $pg = false): void
    {
        $gui = strtolower($gui);

        if ($gui === 'adminer') {
            $this->openAdminer($pg);

            return;
        }

        if (in_array($gui, ['dbeaver', 'tableplus'], true)) {
            $url = $this->url($pg);
            Writer::info(sprintf('Connection URL for %s:', $gui));
            Writer::info($url);
            Writer::warn(sprintf('Launch %s manually and paste the URL (auto-launch not configured for this GUI).', $gui));

            return;
        }

        throw new \InvalidArgumentException(sprintf('Unsupported GUI [%s]. Use adminer, dbeaver, or tableplus.', $gui));
    }

    public function runUrl(bool $pg = false): void
    {
        Writer::info($this->url($pg));
    }

    public function runOpen(string $gui = 'adminer', bool $pg = false): void
    {
        try {
            $this->open($gui, $pg);
        } catch (\InvalidArgumentException $e) {
            Writer::error($e->getMessage());
        }
    }

    private function openAdminer(bool $pg): void
    {
        \Valet\Facades\Adminer::ensureInstalled();

        $adminer = \Valet\Facades\Adminer::url();
        $vars = EnvironmentFacade::gather();
        $server = $vars['DB_HOST'] ?? '127.0.0.1';
        $user = $vars['DB_USERNAME'] ?? 'valet';
        $db = $vars['DB_DATABASE'] ?? '';
        $driver = $pg || (($vars['DB_CONNECTION'] ?? '') === 'pgsql') ? 'pgsql' : 'server';

        // Adminer accepts these query params without putting the password in the URL.
        $query = http_build_query([
            'db' => $db,
            'username' => $user,
            $driver => $server,
        ]);

        $url = $adminer . '/?' . $query;
        $this->cli->quietly('xdg-open ' . escapeshellarg($url));
        Writer::info('Opening Adminer: ' . $url);
        Writer::info(sprintf('Plugins: %s', \Valet\Facades\Adminer::pluginsPath()));
        Writer::info('Enter the database password in the Adminer form (not stored in temp files).');
    }
}