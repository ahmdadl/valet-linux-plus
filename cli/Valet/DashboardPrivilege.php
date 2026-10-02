<?php

namespace Valet;

/**
 * Client for the root-owned helper that performs privileged dashboard actions.
 *
 * The dashboard is served by php-fpm as an unprivileged user with no TTY, so it
 * cannot restart nginx or trust a certificate itself. Those operations are
 * handed to a small root-owned helper installed by
 * `sudo valet dashboard:privileges install`.
 *
 * Three properties make this safe:
 *
 *  1. The helper is root-owned and not writable by anybody else. A NOPASSWD
 *     sudoers rule pointing at a script the invoking user can edit would be a
 *     privilege escalation, not a feature.
 *  2. The sudoers rule is pinned to the exact helper path and forbids
 *     arguments, so the only thing that can be executed as root is the helper
 *     itself.
 *  3. The verb and its arguments arrive as JSON on stdin, never on the command
 *     line, and are re-validated inside the helper.
 *
 * This class only decides whether the helper is present and trustworthy, and
 * decodes its JSON reply. The policy lives with the helper.
 */
class DashboardPrivilege
{
    /**
     * Location of the root-owned helper.
     */
    public const HELPER_PATH = '/usr/local/libexec/valet-dashboard-helper';

    /**
     * Location of the NOPASSWD sudoers fragment granting the helper.
     */
    public const SUDOERS_PATH = '/etc/sudoers.d/valet-dashboard';

    /**
     * Binary used to invoke the helper without a password prompt.
     */
    public const SUDO_BINARY = '/usr/bin/sudo';

    /**
     * Privileged operations the helper knows how to perform.
     *
     * These are the dashboard's own root-tier action slugs, so the helper can
     * hand the request straight to DashboardApi without translating it. The
     * dashboard refuses anything not listed here, so a mistake in the web tier
     * cannot become a root request.
     *
     * @var array<int, string>
     */
    public const VERBS = [
        'service.start',
        'service.stop',
        'service.restart',
        'site.secure',
        'site.unsecure',
        'site.proxy',
        'site.unproxy',
        'site.isolate',
        'site.unisolate',
        'domain.set',
        'port.set',
        'trust.ca',
        'cert.renew',
        'php.switch',
        'xdebug.enable',
        'xdebug.disable',
    ];

    /**
     * How long a privileged action may take before it is considered hung.
     */
    public const TIMEOUT = 120;

    public function __construct(
        private Filesystem $files,
        private CommandLine $commandLine
    ) {
    }

    /**
     * Is the helper installed, root-owned and granted a sudoers rule?
     */
    public function installed(): bool
    {
        return $this->helperTrusted() && $this->files->exists(self::SUDOERS_PATH);
    }

    /**
     * A description of the current privilege setup, for the dashboard UI.
     *
     * @return array{
     *     enabled: bool,
     *     helper_path: string,
     *     helper_installed: bool,
     *     helper_trusted: bool,
     *     sudoers_path: string,
     *     sudoers_installed: bool,
     *     granted_users: array<int, string>,
     *     install_command: string,
     *     verbs: array<int, string>
     * }
     */
    public function status(): array
    {
        return [
            'enabled' => $this->installed(),
            'helper_path' => self::HELPER_PATH,
            'helper_installed' => $this->files->exists(self::HELPER_PATH),
            'helper_trusted' => $this->helperTrusted(),
            'sudoers_path' => self::SUDOERS_PATH,
            'sudoers_installed' => $this->files->exists(self::SUDOERS_PATH),
            'granted_users' => $this->grantedUsers(),
            'install_command' => 'sudo valet dashboard:privileges install',
            'verbs' => self::VERBS,
        ];
    }

    /**
     * The account whose sudoers entry the helper will grant.
     *
     * When installing through sudo this is the invoking user, not root: a rule
     * for root would let anybody who reached the dashboard restart services.
     */
    public function installUser(): string
    {
        return $this->installUserFor($this->euid());
    }

    /**
     * The same decision, with the privilege level passed in.
     *
     * A rule for root would let anybody who reached the dashboard restart
     * services, so SUDO_USER is only believed when this process really is
     * root - otherwise any caller could set the variable and have a rule
     * written for an account of their choosing.
     */
    private function installUserFor(int $euid): string
    {
        $sudoUser = $_SERVER['SUDO_USER'] ?? null;

        if ($euid === 0 && is_string($sudoUser) && $sudoUser !== '') {
            return $sudoUser;
        }

        return user();
    }

    /**
     * Install the helper and its sudoers rule.
     *
     * Must run as root (`sudo valet dashboard:privileges install`). The helper
     * is written from the copy in cli/scripts so the version that runs is the
     * version in this checkout, and the sudoers rule is proved parseable by
     * visudo before it is left in place.
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>}
     */
    public function install(): array
    {
        if ($this->euid() !== 0) {
            return ['ok' => false, 'message' => 'Run: sudo valet dashboard:privileges install', 'data' => []];
        }

        $source = VALET_ROOT_PATH . '/cli/scripts/valet-dashboard-helper';

        if (!$this->files->exists($source)) {
            return ['ok' => false, 'message' => 'The helper script is missing from this install.', 'data' => []];
        }

        $helper = $this->helperContents($source, $this->installUser());

        if ($helper === null) {
            return ['ok' => false, 'message' => 'The helper script has unresolved placeholders.', 'data' => []];
        }

        $sudoers = $this->sudoersContents($this->installUser());

        // Prove the rule parses before writing it anywhere sudo will read it.
        // A malformed fragment in /etc/sudoers.d locks everybody out of sudo.
        if (!$this->sudoersParses($sudoers)) {
            return ['ok' => false, 'message' => 'visudo rejected the generated rule; nothing was installed.', 'data' => []];
        }

        if (!$this->installHelper($helper)) {
            return ['ok' => false, 'message' => 'Could not install ' . self::HELPER_PATH, 'data' => []];
        }

        if (@file_put_contents(self::SUDOERS_PATH, $sudoers) === false) {
            return ['ok' => false, 'message' => 'Could not write ' . self::SUDOERS_PATH, 'data' => []];
        }

        @chmod(self::SUDOERS_PATH, 0440);
        @chown(self::SUDOERS_PATH, 0);

        return [
            'ok' => true,
            'message' => 'Dashboard privileges installed.',
            'data' => [
                'helper' => self::HELPER_PATH,
                'sudoers' => self::SUDOERS_PATH,
                'user' => $this->installUser(),
            ],
        ];
    }

    /**
     * Remove the helper and the sudoers rule.
     *
     * Must run as root. The sudoers rule goes first: while it exists the helper
     * is runnable, and we would rather be left with an unrunnable helper than an
     * orphaned rule.
     *
     * @return array{ok: bool, message: string, data: array<string|int, mixed>}
     */
    public function remove(): array
    {
        if ($this->euid() !== 0) {
            return ['ok' => false, 'message' => 'Run: sudo valet dashboard:privileges uninstall', 'data' => []];
        }

        $removed = [];

        foreach ([self::SUDOERS_PATH, self::HELPER_PATH] as $path) {
            if (file_exists($path) && @unlink($path)) {
                $removed[] = $path;
            }
        }

        return ['ok' => true, 'message' => 'Dashboard privileges removed.', 'data' => ['removed' => $removed]];
    }

    /**
     * The effective user id of this process.
     */
    private function euid(): int
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : 0;
    }

    /**
     * Write the helper into place, atomically.
     *
     * The staged file is renamed into position so sudo can never see a partial
     * program, and the result is checked with the same trust test the dashboard
     * uses before it will talk to it.
     */
    private function installHelper(string $helper): bool
    {
        $directory = dirname(self::HELPER_PATH);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $staged = self::HELPER_PATH . '.new';

        if (@file_put_contents($staged, $helper, LOCK_EX) === false) {
            return false;
        }

        @chmod($staged, 0755);
        @chown($staged, 0);

        if (!@rename($staged, self::HELPER_PATH)) {
            @unlink($staged);

            return false;
        }

        if (!$this->helperTrusted()) {
            @unlink(self::HELPER_PATH);

            return false;
        }

        return true;
    }

    /**
     * Fill the helper's install-time constants.
     *
     * The script is shipped as a template so it never carries a path from the
     * machine that installed it.
     */
    private function helperContents(string $source, string $user): ?string
    {
        $template = $this->files->get($source);

        if (!is_string($template) || str_contains($template, '@@VALET_')) {
            return null;
        }

        $replacements = [
            '@@VALET_ENTRY@@' => VALET_ROOT_PATH . '/cli/valet.php',
            '@@VALET_PHP@@' => PHP_BINARY,
            '@@VALET_USER@@' => $user,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Build the sudoers fragment.
     *
     * The `""` argument is what pins the rule: sudo only matches it when the
     * command is invoked with no arguments at all, so nothing can be appended
     * to turn the helper into something else.
     */
    private function sudoersContents(string $user): string
    {
        if (preg_match('/^[a-z_][a-z0-9_-]*$/', $user) !== 1) {
            $user = 'root';
        }

        return implode("\n", [
            '# Managed by `valet dashboard:privileges`. Remove with `sudo valet dashboard:privileges uninstall`.',
            '# The helper reads its work from stdin and takes no arguments, which',
            '# is what the empty argument list below enforces.',
            $user.' ALL=(root) NOPASSWD: '.self::HELPER_PATH.' ""',
            '',
        ]);
    }

    /**
     * A private, freshly named directory for one candidate file.
     *
     * The name is random and mkdir() is what creates it, so nothing else can
     * be waiting at that path to be written to later.
     */
    private function scratchDirectory(): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $directory = sys_get_temp_dir().'/valet-sudoers-'.bin2hex(random_bytes(8));

            if (@mkdir($directory, 0700)) {
                return $directory;
            }
        }

        return null;
    }

    /**
     * Ask visudo to parse a candidate rule in an isolated directory.
     *
     * The check happens outside /etc/sudoers.d on purpose: a broken rule in
     * there would deny sudo to everybody, including the person installing.
     */
    private function sudoersParses(string $contents): bool
    {
        $visudo = '/usr/sbin/visudo';

        if (!is_executable($visudo)) {
            // Without visudo we cannot prove the rule; refuse rather than guess.
            return false;
        }

        $directory = $this->scratchDirectory();

        if ($directory === null) {
            return false;
        }

        $candidate = $directory.'/valet-dashboard';

        if (@file_put_contents($candidate, $contents) === false) {
            @rmdir($directory);

            return false;
        }

        @chmod($candidate, 0440);
        @chown($candidate, 0);

        $result = $this->commandLine->runProcess([$visudo, '-c', '-f', $candidate], '', 30);

        @unlink($candidate);
        @rmdir($directory);

        return $result['exitCode'] === 0;
    }

    /**
     * Perform a privileged operation through the helper.
     *
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, message: string, data: array<string|int, mixed>}
     */
    public function run(string $slug, array $params = []): array
    {
        if (!in_array($slug, self::VERBS, true)) {
            return ['ok' => false, 'message' => 'Unknown privileged action.', 'data' => []];
        }

        if (!$this->installed()) {
            return [
                'ok' => false,
                'message' => 'Privileged actions are not available. Run: sudo valet dashboard:privileges install',
                'data' => [],
            ];
        }

        try {
            $payload = json_encode(
                ['action' => $slug, 'params' => $params],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            );
        } catch (\JsonException $e) {
            return ['ok' => false, 'message' => 'Could not encode the helper request.', 'data' => []];
        }

        // No arguments: the sudoers rule pins the helper to an empty argument
        // list, so anything else would be denied.
        $result = $this->commandLine->runProcess(
            [self::SUDO_BINARY, '-n', self::HELPER_PATH],
            $payload,
            self::TIMEOUT
        );

        return $this->decode($result, $slug);
    }

    /**
     * Turn the helper's reply into a normal action result.
     *
     * @param  array{output: string, errors: string, exitCode: int}  $result
     * @return array{ok: bool, message: string, data: array<string|int, mixed>}
     */
    private function decode(array $result, string $slug): array
    {
        $decoded = json_decode(trim($result['output']), true);

        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {
            $reason = trim($result['errors']) !== '' ? trim($result['errors']) : 'no response';

            return [
                'ok' => false,
                'message' => 'The privileged helper failed ('.$slug.'): '.$reason,
                'data' => [],
            ];
        }

        $data = $decoded['data'] ?? [];

        return [
            'ok' => (bool) $decoded['ok'],
            'message' => is_string($decoded['message'] ?? null) ? $decoded['message'] : '',
            'data' => is_array($data) ? $data : [],
        ];
    }

    /**
     * The helper must exist, be a real file owned by root and be writable by
     * root alone. Anything else means it can be replaced by the user who
     * reached the dashboard.
     */
    private function helperTrusted(): bool
    {
        if (!$this->files->exists(self::HELPER_PATH) || !is_file(self::HELPER_PATH)) {
            return false;
        }

        if (is_link(self::HELPER_PATH)) {
            return false;
        }

        if (fileowner(self::HELPER_PATH) !== 0) {
            return false;
        }

        // Group- or world-writable would let anyone edit the root-run program.
        return (fileperms(self::HELPER_PATH) & 0022) === 0;
    }

    /**
     * Accounts the sudoers fragment grants, for display.
     *
     * @return array<int, string>
     */
    private function grantedUsers(): array
    {
        if (!$this->files->exists(self::SUDOERS_PATH)) {
            return [];
        }

        $contents = $this->files->get(self::SUDOERS_PATH);

        if (!is_string($contents)) {
            return [];
        }

        $users = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];
            $subject = $parts[0] ?? '';

            // Defaults: lines and %group specifications are not accounts we
            // granted, so they do not belong in the list shown to the user.
            if ($subject === '' || str_starts_with($subject, 'Defaults') || str_starts_with($subject, '%')) {
                continue;
            }

            $users[] = $subject;
        }

        return $users;
    }
}
