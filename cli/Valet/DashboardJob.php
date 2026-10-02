<?php

namespace Valet;

/**
 * Runs long dashboard actions in the background.
 *
 * Database imports, backups and snapshot restores take longer than a request
 * should hold open, and php-fpm would kill them at max_execution_time. Each one
 * is therefore recorded as a job file and executed by a detached CLI process,
 * with the browser polling the job's status.
 *
 * Job files live under the Valet home directory rather than in the web root, so
 * nothing about a running operation is reachable over HTTP. The id is random and
 * is checked against the file's shape on read, because the id arrives from the
 * browser.
 */
class DashboardJob
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Where job state is stored.
     */
    public const JOB_DIR = VALET_HOME_PATH . '/dashboard-jobs';

    /**
     * Finished job files kept on disk.
     */
    public const KEEP = 25;

    public function __construct(
        private Filesystem $files,
        private CommandLine $commandLine
    ) {
    }

    /**
     * Queue an action and return its job id.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string, status: string}
     */
    public function start(string $slug, array $params = []): array
    {
        $id = $this->newId();

        $this->files->ensureDirExists(self::JOB_DIR, user());

        $this->write($id, [
            'id' => $id,
            'slug' => $slug,
            'params' => $params,
            'status' => self::STATUS_QUEUED,
            'message' => 'Queued',
            'data' => [],
            'started_at' => date('c'),
            'finished_at' => null,
        ]);

        $this->spawn($id);

        return ['id' => $id, 'status' => self::STATUS_QUEUED];
    }

    /**
     * Read a job's current state, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (!$this->validId($id) || !$this->files->exists($this->jobFile($id))) {
            return null;
        }

        $job = json_decode((string) $this->files->get($this->jobFile($id)), true);

        if (!is_array($job) || ($job['id'] ?? null) !== $id || !isset($job['slug'], $job['status'])) {
            return null;
        }

        return $job;
    }

    /**
     * Read the log a job appended to, if any.
     */
    public function log(string $id): string
    {
        if (!$this->validId($id) || !$this->files->exists($this->logFile($id))) {
            return '';
        }

        return $this->files->get($this->logFile($id));
    }

    /**
     * Most recent jobs, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        if (!$this->files->isDir(self::JOB_DIR)) {
            return [];
        }

        $files = $this->files->scandir(self::JOB_DIR);
        $jobs = [];

        foreach ($files as $file) {
            if (!is_string($file) || !str_ends_with($file, '.json')) {
                continue;
            }

            $job = $this->find(basename($file, '.json'));

            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        usort($jobs, static fn (array $a, array $b): int => strcmp(
            is_string($b['started_at'] ?? null) ? $b['started_at'] : '',
            is_string($a['started_at'] ?? null) ? $a['started_at'] : ''
        ));

        return array_slice($jobs, 0, max(1, min(50, $limit)));
    }

    /**
     * Claim a queued job for execution.
     *
     * Called by the worker process so the browser sees `running` immediately
     * rather than a queued job that is actually already in flight.
     */
    public function markRunning(string $id): bool
    {
        $job = $this->find($id);

        if ($job === null || $job['status'] !== self::STATUS_QUEUED) {
            return false;
        }

        $job['status'] = self::STATUS_RUNNING;
        $job['message'] = 'Running';

        $this->write($id, $job);

        return true;
    }

    /**
     * Mark a job cancelled.
     *
     * Running work is not interrupted: the process keeps going and its result is
     * discarded, because a half-finished restore cannot be safely unwound.
     */
    public function cancel(string $id): bool
    {
        $job = $this->find($id);

        if ($job === null || in_array($job['status'], [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        $job['status'] = self::STATUS_CANCELLED;
        $job['message'] = 'Cancelled';
        $job['finished_at'] = date('c');

        $this->write($id, $job);

        return true;
    }

    /**
     * Record a job's outcome. Called by the worker process, not by the browser.
     *
     * @param  array<string|int, mixed>  $data
     */
    public function finish(string $id, bool $ok, string $message, array $data = []): void
    {
        $job = $this->find($id);

        if ($job === null) {
            return;
        }

        $job['status'] = $ok ? self::STATUS_DONE : self::STATUS_FAILED;
        $job['message'] = $message;
        $job['data'] = $data;
        $job['finished_at'] = date('c');

        $this->write($id, $job);

        $this->prune();
    }

    /**
     * Remove finished job files beyond the retention limit.
     */
    public function prune(): int
    {
        if (!$this->files->isDir(self::JOB_DIR)) {
            return 0;
        }

        $finished = [];

        foreach ($this->files->scandir(self::JOB_DIR) as $file) {
            if (!is_string($file) || !str_ends_with($file, '.json')) {
                continue;
            }

            $job = $this->find(basename($file, '.json'));

            if ($job !== null && in_array($job['status'], [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
                $finished[] = basename($file, '.json');
            }
        }

        if (count($finished) <= self::KEEP) {
            return 0;
        }

        // find() returns newest first, so the tail is the oldest.
        $removed = 0;

        foreach (array_slice($finished, self::KEEP) as $id) {
            if ($this->files->exists($this->logFile($id))) {
                $this->files->unlink($this->logFile($id));
            }

            if ($this->files->exists($this->jobFile($id))) {
                $this->files->unlink($this->jobFile($id));
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Detach a worker process for the job.
     */
    private function spawn(string $id): void
    {
        // cli/app.php only defines the commands; cli/valet.php is what runs
        // them, so that is the entry point a detached worker needs.
        $command = sprintf(
            'nohup setsid %s %s dashboard:job %s >> %s 2>&1 & echo $!',
            escapeshellarg($this->phpBinary()),
            escapeshellarg(VALET_ROOT_PATH.'/cli/valet.php'),
            escapeshellarg($id),
            escapeshellarg($this->logFile($id))
        );

        $pid = (int) trim($this->commandLine->run($command));

        $job = $this->find($id);

        if ($job !== null && $pid > 0) {
            $job['pid'] = $pid;
            $this->write($id, $job);
        }
    }

    /**
     * A PHP CLI binary to run the worker with.
     *
     * The dashboard is served by php-fpm, so PHP_BINARY points at the FPM
     * binary rather than an interpreter. Look for a real CLI build instead.
     */
    private function phpBinary(): string
    {
        static $binary = null;

        if (is_string($binary)) {
            return $binary;
        }

        $candidates = [];

        foreach (['/usr/bin', '/usr/local/bin', '/bin', '/opt/homebrew/bin'] as $directory) {
            foreach ((array) glob($directory.'/php*') as $candidate) {
                if (is_string($candidate) && is_executable($candidate) && basename($candidate) !== 'php-fpm') {
                    $candidates[] = $candidate;
                }
            }
        }

        // Prefer the plain `php` name; otherwise the highest version wins.
        usort($candidates, static function (string $a, string $b): int {
            $rank = static fn (string $path): int => basename($path) === 'php' ? 0 : 1;

            return $rank($a) <=> $rank($b);
        });

        foreach ($candidates as $candidate) {
            $sapi = $this->commandLine->runProcess([$candidate, '-r', 'echo PHP_SAPI;']);

            if (trim($sapi['output']) === 'cli') {
                return $binary = $candidate;
            }
        }

        return $binary = PHP_BINARY;
    }

    /**
     * Write a job file.
     *
     * @param  array<string, mixed>  $job
     */
    private function write(string $id, array $job): void
    {
        $this->files->ensureDirExists(self::JOB_DIR, user());

        $encoded = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (is_string($encoded)) {
            $this->files->putAsUser($this->jobFile($id), $encoded);
        }
    }

    private function jobFile(string $id): string
    {
        return self::JOB_DIR . '/' . $id . '.json';
    }

    private function logFile(string $id): string
    {
        return self::JOB_DIR . '/' . $id . '.log';
    }

    private function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Job ids are hex only, so a request can never address anything else.
     */
    private function validId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $id) === 1;
    }
}
