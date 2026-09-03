<?php

namespace Valet;

class JsonSchema
{
    public const VERSION = 1;

    public const VALID_COMMANDS = ['diagnose', 'status', 'env', 'health'];

    /**
     * Get schema definition for a command.
     *
     * @return array<string, mixed>
     */
    public static function get(string $command): array
    {
        $command = strtolower($command);

        return match ($command) {
            'diagnose' => self::diagnoseSchema(),
            'status' => self::statusSchema(),
            'env' => self::envSchema(),
            'health' => self::healthSchema(),
            default => throw new \InvalidArgumentException(sprintf('Unknown schema command [%s]. Valid: %s', $command, implode(', ', self::VALID_COMMANDS))),
        };
    }

    /**
     * Return all schema definitions keyed by command.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $schemas = [];
        foreach (self::VALID_COMMANDS as $command) {
            $schemas[$command] = self::get($command);
        }

        return $schemas;
    }

    public static function isValid(string $command): bool
    {
        return in_array(strtolower($command), self::VALID_COMMANDS, true);
    }

    /**
     * Wrap data payload with versioned envelope.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function envelope(string $command, array $data): array
    {
        return [
            'schema_version' => self::VERSION,
            'schema_command' => strtolower($command),
            'data' => $data,
            'timestamp' => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function diagnoseSchema(): array
    {
        return [
            'schema_version' => self::VERSION,
            'schema_command' => 'diagnose',
            'fields' => [
                'os' => 'object',
                'package_manager' => 'string',
                'service_manager' => 'string',
                'php' => 'object',
                'nginx' => 'object',
                'dns' => 'object',
                'services' => 'object',
                'paths' => 'object',
                'valet_version' => 'string',
                'schema_version' => 'integer',
                'timestamp' => 'string',
            ],
            'description' => 'Diagnostic information about the Valet installation.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function statusSchema(): array
    {
        return [
            'schema_version' => self::VERSION,
            'schema_command' => 'status',
            'fields' => [
                'services' => 'array',
                'config' => 'object',
                'timestamp' => 'string',
                'schema_version' => 'integer',
            ],
            'service_row' => [
                'service' => 'string',
                'installed' => 'boolean',
                'enabled' => 'boolean',
                'active' => 'boolean',
            ],
            'config_fields' => [
                'domain' => 'string',
                'port' => 'integer',
                'phpVersion' => 'string',
                'paths' => 'integer',
                'sites' => 'integer',
            ],
            'description' => 'Unified status of all Valet services and config.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function envSchema(): array
    {
        return [
            'schema_version' => self::VERSION,
            'schema_command' => 'env',
            'fields' => [
                'site' => 'string',
                'url' => 'string',
                'driver' => 'string',
                'framework' => 'string',
                'env_path' => 'string|null',
                'site_path' => 'string|null',
                'timestamp' => 'string',
                'schema_version' => 'integer',
            ],
            'description' => 'Project context derived from CWD.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function healthSchema(): array
    {
        return [
            'schema_version' => self::VERSION,
            'schema_command' => 'health',
            'fields' => [
                'services' => 'array',
                'healthy' => 'boolean',
                'timestamp' => 'string',
                'schema_version' => 'integer',
            ],
            'service_result' => [
                'service' => 'string',
                'healthy' => 'boolean',
                'latency_ms' => 'float',
                'message' => 'string',
                'timestamp' => 'string',
            ],
            'description' => 'Health check results per service.',
        ];
    }
}
