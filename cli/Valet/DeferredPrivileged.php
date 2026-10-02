<?php

namespace Valet;

/**
 * Hands privileged shell commands to the dashboard helper instead of running them.
 *
 * A handful of Valet operations genuinely need the machine's root privileges:
 * restarting nginx/dnsmasq/php-fpm, refreshing the CA trust store, enabling a
 * php-fpm service, toggling xdebug and editing /etc/hosts. Valet expresses
 * those as ordinary `sudo ...` shell commands, which is exactly what the
 * terminal does.
 *
 * The dashboard cannot do that. php-fpm has no TTY, so a sudo call from a web
 * request either blocks forever waiting for a password or, worse, is satisfied
 * by an attacker who managed to reach the endpoint.
 *
 * The privileged helper (see DashboardPrivilege) solves this by running the
 * Valet code as the ordinary user with VALET_DASHBOARD_DEFERRED pointing at a
 * file only root can create. Recognised privileged commands are appended to
 * that file instead of being executed, and the helper asks Valet to validate
 * the recorded list before performing it as root.
 *
 * Outside the helper nothing changes: with the environment variable absent the
 * commands run exactly as they always have.
 */
class DeferredPrivileged
{
    /**
     * Environment variable holding the file privileged commands are recorded to.
     */
    public const ENV = 'VALET_DASHBOARD_DEFERRED';

    /**
     * Environment variable holding the file the user phase writes its answer to.
     *
     * The helper redirects the user phase's stdout to a log, so the reply
     * travels through a file it can find instead.
     */
    public const RESULT_ENV = 'VALET_DASHBOARD_RESULT';

    /**
     * Read the deferred file the helper created.
     *
     * Public because the validator command runs in a separate process and has
     * to read the same file the user phase appended to.
     *
     * @return array<int, array{allowed: bool, command: string}>
     */
    public static function read(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            return [];
        }

        $entries = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = explode(' ', $line, 2);

            if (count($parts) !== 2 || ($parts[0] !== 'allow' && $parts[0] !== 'deny')) {
                return [];
            }

            $command = base64_decode($parts[1], true);

            if ($command === false || $command === '') {
                return [];
            }

            $entries[] = ['allowed' => $parts[0] === 'allow', 'command' => $command];
        }

        return $entries;
    }

    /**
     * The file this process should write its answer to, if the helper asked for one.
     */
    public static function resultFile(): ?string
    {
        $file = getenv(self::RESULT_ENV);

        if (!is_string($file) || $file === '' || strlen($file) > 4096) {
            return null;
        }

        return $file;
    }

    /**
     * Hand a reply back to the helper.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function writeResult(array $payload): bool
    {
        $file = self::resultFile();

        if ($file === null) {
            return false;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json !== false && @file_put_contents($file, $json) !== false;
    }

    /**
     * The file privileged commands are appended to, or null when disabled.
     */
    private static function deferredFile(): ?string
    {
        $file = getenv(self::ENV);

        if (!is_string($file) || $file === '' || strlen($file) > 4096) {
            return null;
        }

        // The helper creates this file as root; refuse to append to anything
        // the current (unprivileged) process could have pointed us at instead.
        if (!is_file($file) || !is_writable($file)) {
            return null;
        }

        return $file;
    }

    /**
     * The complete set of commands the helper may perform as root.
     *
     * This is the whole privilege surface the dashboard has: control of a
     * fixed set of services, the CA trust store, php-fpm's xdebug module and
     * the DNS resolver. Service names are restricted to the characters real
     * unit names use, and no pattern accepts a shell metacharacter, so a
     * command that reaches this list cannot be made to do anything other than
     * what it literally spells out.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        '/^(?:sudo )?service \'?[a-z0-9][a-z0-9._-]{0,63}\'? (?:start|stop|restart)$/',
        '/^(?:sudo )?systemctl (?:start|stop|restart|reload|enable|disable) \'?[a-z0-9][a-z0-9._-]{0,63}\'?$/',

        // Certificate trust store, used when trusting the mkcert CA.
        '/^(?:sudo )?update-ca-certificates$/',

        // php-fpm service enable/disable (sysv variant of the systemctl calls).
        '/^(?:sudo )?update-rc\.d \'?[a-z0-9][a-z0-9._-]{0,63}\'? defaults$/',
        '/^(?:sudo )?chmod (?:-x )?\/etc\/init\.d\/\'?[a-z0-9][a-z0-9._-]{0,63}\'?$/',

        // Xdebug, via phpenmod where available and symlinks otherwise.
        '/^(?:sudo )?phpenmod -v [0-9][0-9.]* xdebug$/',
        '/^(?:sudo )?phpdismod -v [0-9][0-9.]* xdebug$/',
        '/^(?:sudo )?ln -sf \/etc\/php\/[0-9][0-9.]*\/mods-available\/xdebug\.ini \/etc\/php\/[0-9][0-9.]*\/(?:fpm|cli)\/conf\.d\/20-xdebug\.ini$/',
        '/^(?:sudo )?rm -f \/etc\/php\/[0-9][0-9.]*\/(?:fpm|cli)\/conf\.d\/\*xdebug\*$/',
    ];

    /**
     * Read-only service queries, which must run in the user phase.
     *
     * Valet branches on these: `if ($this->sm->disabled(...))` only enqueues
     * the enable when the query says the unit is off. Deferring the query would
     * answer it with empty output and quietly invert the decision, so queries
     * are never captured and simply run as the unprivileged user, which is all
     * they ever needed.
     *
     * @var array<int, string>
     */
    private const QUERIES = [
        '/^systemctl (?:status|is-active|is-enabled) \'?[a-z0-9][a-z0-9._-]{0,63}\'?(?: .*)?$/',
        '/^service \'?[a-z0-9][a-z0-9._-]{0,63}\'? status$/',
    ];

    /**
     * Is privileged command deferral active for this process?
     */
    public static function enabled(): bool
    {
        return self::deferredFile() !== null;
    }

    /**
     * Rewrite `sudo -u <this user>` to a plain command.
     *
     * The helper runs the Valet code as the user who installed it, so dropping
     * privileges to that same account is a no-op that would otherwise block on
     * a password prompt. Returns null when the command targets somebody else.
     */
    public static function withoutSelfSudo(string $command): ?string
    {
        if (preg_match('/^sudo -u (\S+) (.*)$/s', $command, $matches) !== 1) {
            return null;
        }

        return $matches[1] === self::selfUser() ? trim($matches[2]) : null;
    }

    /**
     * Record a privileged command for the helper to perform as root.
     *
     * Returns true when the command was recognised and deferred. Anything the
     * allowlist does not cover still counts as an attempt to escalate, so it is
     * recorded as an explicit rejection instead of being silently ignored: the
     * helper refuses the whole request rather than completing part of it.
     */
    public static function capture(string $command): bool
    {
        $file = self::deferredFile();

        if ($file === null) {
            return false;
        }

        $normalized = self::normalize($command);

        if ($normalized === null || !self::isPrivileged($normalized)) {
            return false;
        }

        $entry = (self::isAllowed($normalized) ? 'allow' : 'deny').' '.base64_encode($normalized)."\n";

        return @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX) !== false;
    }

    /**
     * Is this a command the dashboard has any business escalating?
     *
     * A command qualifies when it is on the allowlist, or when it merely asks
     * for root with sudo. The second case is treated as suspicious so that the
     * helper aborts instead of quietly dropping a half-applied change.
     */
    public static function isPrivileged(string $command): bool
    {
        if (self::isQuery($command)) {
            return false;
        }

        return str_starts_with($command, 'sudo ') || self::isAllowed($command);
    }

    /**
     * Is this a read-only service query?
     *
     * Checked before isPrivileged() so that a query never reaches the deferred
     * file, even if the allowlist is later widened to cover its verb.
     */
    public static function isQuery(string $command): bool
    {
        $normalized = self::normalize($command);

        if ($normalized === null) {
            return false;
        }

        foreach (self::QUERIES as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this command on the allowlist?
     *
     * The helper re-checks every recorded command through this method before
     * running it, so a bug in a capture path can never widen the allowlist.
     */
    public static function isAllowed(string $command): bool
    {
        $normalized = self::normalize($command);

        if ($normalized === null) {
            return false;
        }

        foreach (self::ALLOWED as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip the leading sudo so the helper can run the command as root directly.
     *
     * Only meaningful for commands that already passed isAllowed().
     */
    public static function withoutSudo(string $command): string
    {
        return preg_replace('/^sudo\s+/', '', trim($command), 1) ?? trim($command);
    }

    /**
     * Canonical form of a shell command, or null when there is nothing to run.
     *
     * Output redirection to /dev/null (added by CommandLine::quietly) carries no
     * meaning once the helper is the one executing, and trailing whitespace is
     * noise, so both are removed before matching.
     */
    public static function normalize(string $command): ?string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', trim($command)) ?? '');
        $normalized = preg_replace('/\s*>?\s*\/dev\/null\s*(?:2>&1)?$/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s*2>&1$/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * The account the current process runs as.
     */
    private static function selfUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && $info['name'] !== '') {
                return $info['name'];
            }
        }

        return user();
    }
}
