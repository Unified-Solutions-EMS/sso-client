<?php

namespace Unified\SsoClient\Http\AgencyStatus;

final readonly class AgencyStatusResponse
{
    /**
     * @param  array<int, string>  $dependsOn  app slugs this app's status depends on
     * @param  array<string, mixed>  $extension  app-specific payload
     */
    public function __construct(
        public string $appSlug,
        public bool $isActive,
        public ?string $lastActivityAt,
        public int $activeUserCount,
        public string $appVersion,
        public AgencyStatusHealth $health,
        public array $dependsOn = [],
        public array $extension = [],
    ) {}

    /**
     * Company exists in this app and is active.
     */
    public static function active(
        string $appSlug,
        ?string $lastActivityAt,
        int $activeUserCount,
        string $appVersion,
        AgencyStatusHealth $health,
        array $dependsOn = [],
        array $extension = [],
    ): self {
        return new self(
            appSlug: $appSlug,
            isActive: true,
            lastActivityAt: $lastActivityAt,
            activeUserCount: $activeUserCount,
            appVersion: $appVersion,
            health: $health,
            dependsOn: $dependsOn,
            extension: $extension,
        );
    }

    /**
     * Company has no presence in this app (not provisioned, not enabled).
     * Distinct from "not found" — this is a normal, expected response.
     */
    public static function notProvisioned(string $appSlug, string $appVersion): self
    {
        return new self(
            appSlug: $appSlug,
            isActive: false,
            lastActivityAt: null,
            activeUserCount: 0,
            appVersion: $appVersion,
            health: AgencyStatusHealth::ok(),
            dependsOn: [],
            extension: [],
        );
    }

    public function toArray(): array
    {
        return [
            'app_slug' => $this->appSlug,
            'is_active' => $this->isActive,
            'last_activity_at' => $this->lastActivityAt,
            'active_user_count' => $this->activeUserCount,
            'app_version' => $this->appVersion,
            'health' => $this->health->toArray(),
            'depends_on' => $this->dependsOn,
            'extension' => $this->extension,
        ];
    }
}
