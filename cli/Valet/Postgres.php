<?php

namespace Valet;

use ConsoleComponents\Writer;
use PDO;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\PackageManagers\Pacman;

class Postgres
{
    private const DATABASE_USER = 'valet';
    public CommandLine $cli;
    public Filesystem $files;
    public PackageManager $pm;
    public ServiceManager $sm;
    public Configuration $configuration;
    /**
     * @var array|string[]
     */
    public array $systemDatabases = ['postgres', 'template0', 'template1'];
    private ?PDO $pdoConnection = null;
    private ?string $currentPackage = null;

    /**
     * Create a new instance.
     */
    public function __construct(
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration
    ) {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
        $this->configuration = $configuration;

        $this->currentPackage = $this->detectPackage();
    }

    /**
     * Install the service.
     */
    public function install(bool $usePostgres = true): void
    {
        if ($this->currentPackage === null) {
            $this->currentPackage = $this->resolvePackageName();

            if (!$this->pm instanceof Pacman && !extension_loaded('pdo_pgsql')) {
                $phpVersion = PhpFpmFacade::getCurrentVersion();
                $this->pm->ensureInstalled($this->pm->getPhpExtensionPrefix($phpVersion).'pgsql');
            }
        }

        if ($this->pm->installed($this->currentPackage)) {
            /** @var array<string, string> $config */
            $config = $this->configuration->get('pgsql', []);
            if (!isset($config['password'])) {
                Writer::info('Looks like PostgreSQL already installed to your system');
                $this->configure();
            }
        } else {
            $this->pm->installOrFail($this->currentPackage);
            $this->sm->enable($this->serviceName());

            /** @var ?string $password */
            $password = Writer::ask(\sprintf('Please enter new password for [%s] database user', self::DATABASE_USER));
            if ($password === null) {
                $password = '';
            }
            $this->createValetUser($password);
        }
    }

    /**
     * Stop the Postgres service.
     */
    public function stop(): void
    {
        $this->sm->stop($this->serviceName());
    }

    /**
     * Restart the Postgres service.
     */
    public function restart(): void
    {
        $this->sm->restart($this->serviceName());
    }

    /**
     * Prepare Postgres for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
    }

    /**
     * Postgres service status.
     */
    public function status(): void
    {
        $this->sm->printStatus($this->serviceName());
    }

    /**
     * Configure Database user for Valet.
     */
    public function configure(bool $force = false): void
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('pgsql', []);

        if (!$force && isset($config['password'])) {
            Writer::info('Valet database user is already configured. Use --force to reconfigure database user.');
            return;
        }

        $defaultUser = null;
        if (!empty($config['user'])) {
            $defaultUser = $config['user'];
        }
        /** @var string $user */
        $user = Writer::ask('Please enter PostgreSQL user:', $defaultUser);

        /** @var string $password */
        $password = Writer::ask('Please enter PostgreSQL password:');

        $connection = $this->validateCredentials($user, $password);
        if (!$connection) {
            $confirm = Writer::confirm('Would you like to try again?', true);
            if (!$confirm) {
                Writer::warn('Valet database user is not configured');
                return;
            }
            $this->configure($force);
            return;
        }
        $config['user'] = $user;
        $config['password'] = $password;
        $this->configuration->set('pgsql', $config);
        Writer::info('Database user configured successfully');
    }

    /**
     * Create Postgres database.
     */
    public function createDatabase(string $name): bool
    {
        if ($this->isDatabaseExists($name)) {
            Writer::warn("Database [$name] is already exists!");
            return false;
        }

        $isCreated = (bool)$this->query('CREATE DATABASE "'.$this->escapeIdentifier($name).'"');
        if (!$isCreated) {
            Writer::warn('Error creating database');
            return false;
        }

        return true;
    }

    /**
     * Drop Postgres database.
     */
    public function dropDatabase(string $name): bool
    {
        if (!$this->isDatabaseExists($name)) {
            Writer::warn("Database [$name] does not exists!");
            return false;
        }

        $dbDropped = (bool)$this->query('DROP DATABASE "'.$this->escapeIdentifier($name).'"');

        if (!$dbDropped) {
            Writer::warn('Error dropping database');
            return false;
        }

        return true;
    }

    /**
     * Export Postgres database.
     *
     * @return array<string, string>
     */
    public function exportDatabase(string $database, bool $exportSql = false): array
    {
        $filename = $database.'-'.\date('Y-m-d-H-i-s', \time());

        if ($exportSql) {
            $filename = $filename.'.sql';
        } else {
            $filename = $filename.'.sql.gz';
        }

        $credentials = $this->getCredentials();
        $pgpassFile = $this->writePgPass($credentials['user'], $credentials['password']);
        $command = 'PGPASSFILE='.escapeshellarg($pgpassFile).' pg_dump -U '.escapeshellarg($credentials['user']).' -h localhost '.escapeshellarg($database).' ';
        if ($exportSql) {
            $command .= ' > '.escapeshellarg($filename);
        } else {
            $command .= ' | gzip > '.escapeshellarg($filename);
        }
        $this->cli->run($command);
        @unlink($pgpassFile);

        return [
            'database' => $database,
            'filename' => $filename,
        ];
    }

    /**
     * Import Postgres database from file.
     */
    public function importDatabase(string $file, string $database): void
    {
        $isExistsDatabase = false;
        // check if database already exists.
        if ($this->isDatabaseExists($database)) {
            $confirm = Writer::confirm('Database already exists, are you sure you want to continue?');
            if (!$confirm) {
                Writer::warn('Aborted');
                return;
            }
            $isExistsDatabase = true;
        }

        if (!$isExistsDatabase) {
            $this->createDatabase($database);
        }
        $gzip = '';
        $sqlFile = '';
        if (\stristr($file, '.gz')) {
            $file = escapeshellarg($file);
            $gzip = "zcat {$file} | ";
        } else {
            $file = escapeshellarg($file);
            $sqlFile = " < {$file}";
        }
        $database = escapeshellarg($database);
        $credentials = $this->getCredentials();
        $pgpassFile = $this->writePgPass($credentials['user'], $credentials['password']);
        $this->cli->run(
            \sprintf(
                '%sPGPASSFILE=%s psql -U %s -h localhost %s %s',
                $gzip,
                escapeshellarg($pgpassFile),
                escapeshellarg($credentials['user']),
                $database,
                $sqlFile
            )
        );
        @unlink($pgpassFile);
    }

    /**
     * Get a list of databases.
     *
     * @return array<int, array<int, string>>
     */
    public function getDatabases(): array
    {
        $result = $this->query('SELECT datname FROM pg_database');

        if (!$result instanceof \PDOStatement) {
            return [['Failed to get databases']];
        }

        $systemDatabases = $this->getSystemDatabases();
        $databases = [];

        foreach ($result->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($row['datname']) || \in_array($row['datname'], $systemDatabases, true)) {
                continue;
            }

            $databases[] = [(string) $row['datname']];
        }

        return $databases;
    }

    /**
     * Check if database already exists.
     */
    private function isDatabaseExists(string $name): bool
    {
        $pdo = $this->getConnection();
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$name]);

        return (bool) $stmt->fetch();
    }

    /**
     * Run Postgres query.
     *
     * @return bool|\PDOStatement|void
     */
    private function query(string $query)
    {
        $link = $this->getConnection();

        try {
            return $link->query($query);
        } catch (\PDOException $e) {
            Writer::warn($e->getMessage());
        }
    }

    /**
     * Validate Username & Password.
     */
    private function validateCredentials(string $username, string $password): bool
    {
        try {
            // Create connection
            $connection = new PDO(
                'pgsql:host=localhost',
                $username,
                $password
            );
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\PDOException $e) {
            Writer::error('Invalid database credentials');

            return false;
        }
    }

    /**
     * Return Postgres connection.
     */
    private function getConnection(): PDO
    {
        // if connection already exists return it early.
        if ($this->pdoConnection) {
            return $this->pdoConnection;
        }

        try {
            // Create connection
            $credentials = $this->getCredentials();
            $this->pdoConnection = new PDO(
                'pgsql:host=localhost',
                $credentials['user'],
                $credentials['password']
            );
            $this->pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->pdoConnection;
        } catch (\PDOException $e) {
            Writer::warn('Failed to connect PostgreSQL due to :`'.$e->getMessage().'`');
            exit;
        }
    }

    /**
     * Get default databases of postgres.
     *
     * @return array<int, string>
     */
    private function getSystemDatabases(): array
    {
        return $this->systemDatabases;
    }

    private function serviceName(): string
    {
        if ($this->currentPackage !== null) {
            return $this->currentPackage;
        }

        try {
            return $this->pm->packageName('postgresql');
        } catch (\InvalidArgumentException $e) {
            return 'postgresql';
        }
    }

    /**
     * Set password of valet Postgres user.
     */
    private function createValetUser(string $password): void
    {
        $success = true;

        // Escape single quotes for safe embedding in the SQL statement.
        $escapedPassword = str_replace("'", "''", $password);
        $sql = "CREATE USER ".self::DATABASE_USER." WITH PASSWORD '".$escapedPassword."' SUPERUSER;";

        // Write the SQL (which contains the password) to a temp file with
        // restrictive permissions and pipe it via stdin so the password is
        // never exposed on the command line (visible via `ps`) or subject to
        // shell injection.
        $tmpFile = tempnam(sys_get_temp_dir(), 'valet-pg-');
        file_put_contents($tmpFile, $sql);
        chmod($tmpFile, 0600);

        $this->cli->run(
            'sudo -u postgres psql -f '.escapeshellarg($tmpFile),
            function ($statusCode, $error) use (&$success) {
                Writer::warn('Setting password for valet user failed due to `['.$statusCode.'] '.$error.'`');
                $success = false;
            }
        );

        @unlink($tmpFile);

        if ($success !== false) {
            /** @var array<string, string> $config */
            $config = $this->configuration->get('pgsql', []);

            $config['user'] = self::DATABASE_USER;
            $config['password'] = $password;
            $this->configuration->set('pgsql', $config);
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     * @return array{user: string, password: string}
     */
    private function getCredentials(): array
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('pgsql', []);
        if (!isset($config['password']) || $config['password'] === '') {
            Writer::warn('Valet database user is not configured!');
            exit;
        }

        // For previously installed user.
        if (empty($config['user'])) {
            $config['user'] = 'postgres';
        }

        return ['user' => $config['user'], 'password' => $config['password']];
    }

    /**
     * Write PostgreSQL client credentials to a temporary pgpass file with
     * restrictive permissions so they can be passed via the PGPASSFILE
     * environment variable instead of on the command line (which would expose
     * them via `ps`).
     */
    private function writePgPass(string $user, string $password): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'valet-pgpass-');
        $contents = 'localhost:5432:*:' . $user . ':' . $password . "\n";
        file_put_contents($tmpFile, $contents);
        chmod($tmpFile, 0600);

        return $tmpFile;
    }

    /**
     * Escape a Postgres identifier (double quotes) for safe embedding.
     */
    private function escapeIdentifier(string $name): string
    {
        return str_replace('"', '""', $name);
    }

    /**
     * Detect the installed PostgreSQL package name, if any.
     */
    private function detectPackage(): ?string
    {
        foreach (['postgres', 'postgresql'] as $key) {
            try {
                $name = $this->pm->packageName($key);
            } catch (\InvalidArgumentException $e) {
                continue;
            }

            if ($this->pm->installed($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Resolve the PostgreSQL package name to install.
     */
    private function resolvePackageName(): string
    {
        try {
            return $this->pm->packageName('postgres');
        } catch (\InvalidArgumentException $e) {
            return $this->pm->packageName('postgresql');
        }
    }
}
