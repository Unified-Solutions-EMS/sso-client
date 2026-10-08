<?php

namespace Unified\SsoClient\Settings;

/**
 * Per-key outcome of a settings patch. HTTP 200 with mixed statuses is the
 * normal shape: the per-key status is the contract, not the response code.
 */
final class SettingsResult
{
    /** @var array<string, SettingResult> */
    private array $results = [];

    public static function make(): self
    {
        return new self;
    }

    public function saved(string $key): self
    {
        return $this->put($key, new SettingResult(SettingStatus::Saved));
    }

    public function invalid(string $key, string $message): self
    {
        return $this->put($key, new SettingResult(SettingStatus::Invalid, $message));
    }

    public function blocked(string $key, string $reason): self
    {
        return $this->put($key, new SettingResult(SettingStatus::Blocked, $reason));
    }

    public function unknownKey(string $key): self
    {
        return $this->put($key, new SettingResult(SettingStatus::UnknownKey));
    }

    public function put(string $key, SettingResult $result): self
    {
        $this->results[$key] = $result;

        return $this;
    }

    public function get(string $key): ?SettingResult
    {
        return $this->results[$key] ?? null;
    }

    /**
     * @return array<string, SettingResult>
     */
    public function all(): array
    {
        return $this->results;
    }

    /**
     * @return array<string, array{status: string, message?: string}>
     */
    public function toArray(): array
    {
        return array_map(fn (SettingResult $result): array => $result->toArray(), $this->results);
    }
}
