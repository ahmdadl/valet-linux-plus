<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\Configuration;
use Valet\Facades\SiteProxy as SiteProxyFacade;
use Valet\Facades\SiteSecure as SiteSecureFacade;

class Mailpit
{
    /**
     * @var PackageManager
     */
    public $pm;
    /**
     * @var ServiceManager
     */
    public $sm;
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var Filesystem
     */
    public $files;
    /**
     * @var string
     */
    public const SERVICE_NAME = 'mailpit';

    /**
     * Create a new Mailpit instance.
     *
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
    }

    /**
     * Install the configuration files for Mailpit.
     */
    public function install(): void
    {
        $this->ensureInstalled();

        $this->createService();

        $this->sm->start(self::SERVICE_NAME);

        try {
            if (!$this->sm->disabled('mailhog')) {
                $this->sm->disable('mailhog');
                if ($this->files->exists('/opt/valet-linux/mailhog')) {
                    $this->files->remove('/opt/valet-linux/mailhog');
                }
                $domain = Configuration::get('domain');
                if ($this->files->exists(VALET_HOME_PATH . "/Nginx/mailhog.$domain")) {
                    SiteSecureFacade::unsecure("mailhog.$domain");
                }
            }
        } catch (\DomainException $e) {
        }
    }

    /**
     * Start the Mailpit service.
     */
    public function start(): void
    {
        $this->sm->start(self::SERVICE_NAME);
    }

    /**
     * Restart the Mailpit service.
     */
    public function restart(): void
    {
        $this->sm->restart(self::SERVICE_NAME);
    }

    /**
     * Stop the Mailpit service.
     */
    public function stop(): void
    {
        $this->sm->stop(self::SERVICE_NAME);
    }

    /**
     * Mailpit service status.
     */
    public function status(): void
    {
        $this->sm->printStatus(self::SERVICE_NAME);
    }

    /**
     * Prepare Mailpit for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
    }

    /**
     * Validate if system already has Mailpit installed in it.
     */
    private function ensureInstalled(): void
    {
        if (!$this->isAvailable()) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'mailpit-install-');
            $this->cli->run(
                'curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh -o '.escapeshellarg($tmpFile)
            );

            if (file_exists($tmpFile) && filesize($tmpFile) > 0) {
                // Security note: the install script is fetched over HTTPS from the
                // official Mailpit repository. Ideally verify a published checksum
                // here before executing; none is currently published for the
                // develop branch, so review the downloaded script if in doubt.
                $this->cli->runAsUser('bash '.escapeshellarg($tmpFile));
            }

            @unlink($tmpFile);
        }
    }

    /**
     * Create Mailpit service files
     */
    private function createService(): void
    {
        $servicePath = '/etc/init.d/mailpit';
        $serviceFile = VALET_ROOT_PATH . '/cli/stubs/init/mailpit.sh';
        $hasSystemd = $this->sm->isSystemd();

        if ($hasSystemd) {
            $servicePath = '/etc/systemd/system/mailpit.service';
            $serviceFile = VALET_ROOT_PATH . '/cli/stubs/init/mailpit';
        }

        $this->files->put(
            $servicePath,
            $this->files->get($serviceFile)
        );

        if (!$hasSystemd) {
            $this->cli->run("chmod +x $servicePath");
        }

        $this->sm->enable(self::SERVICE_NAME);

        $this->updateDomain();
    }

    /**
     * Update domain for HTTP access.
     */
    private function updateDomain(): void
    {
        $domain = Configuration::get('domain');

        SiteProxyFacade::proxyCreate("mails.$domain", 'http://localhost:8025', true);
    }

    private function isAvailable(): bool
    {
        try {
            $output = $this->cli->run(
                'which mailpit',
                function () {
                    throw new DomainException('Service not available');
                }
            );

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }
}
