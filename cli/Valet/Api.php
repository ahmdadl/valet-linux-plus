<?php

namespace Valet;

use InvalidArgumentException;
use Valet\Facades\Dashboard as DashboardFacade;
use Valet\Facades\Environment as EnvironmentFacade;
use Valet\Facades\Health as HealthFacade;
use Valet\Facades\Profile as ProfileFacade;

class Api
{
    public const RESOURCES = ['sites', 'services', 'env', 'health', 'profiles'];

    /**
     * @return array<int, string>
     */
    public function resources(): array
    {
        return self::RESOURCES;
    }

    /**
     * Fetch a versioned API resource payload.
     *
     * @return array<string, mixed>
     */
    public function get(string $resource): array
    {
        $resource = strtolower(trim($resource));
        if (!in_array($resource, self::RESOURCES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown API resource [%s]. Valid: %s',
                $resource,
                implode(', ', self::RESOURCES)
            ));
        }

        $data = match ($resource) {
            'sites' => $this->sites(),
            'services' => $this->services(),
            'env' => EnvironmentFacade::gather(),
            'health' => $this->health(),
            'profiles' => $this->profiles(),
        };

        return JsonSchema::envelope($resource, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function catalog(): array
    {
        return [
            'schema_version' => JsonSchema::VERSION,
            'resources' => self::RESOURCES,
            'docs' => 'docs/api-v1.md',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sites(): array
    {
        try {
            $data = DashboardFacade::data();
            $sites = $data['sites'] ?? [];

            return is_array($sites) ? $sites : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function services(): array
    {
        try {
            $data = DashboardFacade::data();
            $services = $data['services'] ?? [];

            return is_array($services) ? $services : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @return array{services: array<int, mixed>, healthy: bool}
     */
    private function health(): array
    {
        try {
            $results = HealthFacade::checkAll();
            $services = $results;
            $healthy = true;
            foreach ($services as $row) {
                if (is_array($row) && array_key_exists('healthy', $row) && !$row['healthy']) {
                    $healthy = false;
                    break;
                }
            }

            return ['services' => $services, 'healthy' => $healthy];
        } catch (\Throwable $e) {
            return ['services' => [], 'healthy' => false];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profiles(): array
    {
        try {
            $list = ProfileFacade::listProfiles();
            $project = null;
            try {
                $project = ProfileFacade::show(null);
            } catch (\Throwable $e) {
                $project = null;
            }

            return [
                'project' => $project,
                'list' => $list,
            ];
        } catch (\Throwable $e) {
            return ['project' => null, 'list' => []];
        }
    }
}
