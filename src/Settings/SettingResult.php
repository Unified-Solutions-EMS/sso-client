<?php

namespace Unified\SsoClient\Settings;

final class SettingResult
{
    public function __construct(
        public readonly SettingStatus $status,
        public readonly ?string $message = null,
    ) {}

    /**
     * @return array{status: string, message?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'message' => $this->message,
        ], fn (?string $value): bool => $value !== null);
    }
}
