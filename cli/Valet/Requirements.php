<?php

namespace Valet;

use ConsoleComponents\Writer;
use RuntimeException;

class Requirements
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var bool
     */
    public $ignoreSELinux = false;

    /**
     * Missing recommended PHP extensions discovered during pre-flight.
     *
     * @var array<int,string>
     */
    public array $missingExtensions = [];

    /**
     * Create a new Warning instance.
     */
    public function __construct(CommandLine $cli)
    {
        $this->cli = $cli;
    }

    /**
     * Determine if SELinux check should be skipped.
     */
    public function setIgnoreSELinux(bool $ignore = true): self
    {
        $this->ignoreSELinux = $ignore;

        return $this;
    }

    /**
     * Run all checks and output warnings.
     */
    public function check(): void
    {
        $this->homePathIsInsideRoot();
        $this->seLinuxIsEnabled();
        $this->checkPhpExtensions();
    }

    /**
     * Verify required PHP extensions are available.
     *
     * A missing extension is surfaced as a friendly warning rather than a hard
     * failure, since some (e.g. pdo_mysql/mysqli) are only needed for optional
     * database features.
     */
    public function checkPhpExtensions(): void
    {
        $required = ['posix', 'mbstring'];
        $databaseExtensions = ['pdo_mysql', 'mysqli'];

        $missing = [];
        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        // pdo_mysql and mysqli are interchangeable: only warn when both are absent.
        if (!extension_loaded('pdo_mysql') && !extension_loaded('mysqli')) {
            $missing = array_merge($missing, $databaseExtensions);
        }

        $this->missingExtensions = $missing;

        if (count($this->missingExtensions) > 0) {
            Writer::warn('Missing recommended PHP extensions: ' . implode(', ', $this->missingExtensions));
        }
    }

    /**
     * Verify if valet home is inside /root directory.
     *
     * This usually means the HOME parameters has not been
     * kept using sudo.
     */
    private function homePathIsInsideRoot(): void
    {
        if (strpos(VALET_HOME_PATH, '/root/') === 0) {
            throw new RuntimeException('Valet home directory is inside /root');
        }
    }

    /**
     * Verify is SELinux is enabled and in enforcing mode.
     */
    private function seLinuxIsEnabled(): void
    {
        if ($this->ignoreSELinux) {
            return;
        }

        $output = $this->cli->run('sestatus');

        if (preg_match('@SELinux status:(\s+)enabled@', $output)
            && preg_match('@Current mode:(\s+)enforcing@', $output)
        ) {
            throw new RuntimeException('SELinux is in enforcing mode');
        }
    }
}
