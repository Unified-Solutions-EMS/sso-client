<?php

namespace Unified\SsoClient\Http\AgencyStatus;

final readonly class AgencyStatusHealth
{
    /**
     * @param  array<int, array{code: string, message: string, since?: string}>  $openIncidents
     */
    public function __construct(
        public AgencyStatusHealthLevel $status,
        public array $openIncidents = [],
        public ?string $message = null,
    ) {}

    public static function ok(): self
    {
        return new self(AgencyStatusHealthLevel::Ok);
    }

    public static function degraded(string $message, array $openIncidents = []): self
    {
        return new self(AgencyStatusHealthLevel::Degraded, $openIncidents, $message);
    }

    public static function down(string $message, array $openIncidents = []): self
    {
        return new self(AgencyStatusHealthLevel::Down, $openIncidents, $message);
    }

    public static function unknown(?string $message = null): self
    {
        return new self(AgencyStatusHealthLevel::Unknown, [], $message);
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'open_incidents' => $this->openIncidents,
            'message' => $this->message,
        ];
    }
}
