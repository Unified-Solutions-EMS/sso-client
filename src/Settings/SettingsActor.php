<?php

namespace Unified\SsoClient\Settings;

/**
 * Who asked for a settings change, for the app's audit trail. An AI-sourced
 * change still names the SSO user who approved it.
 */
final class SettingsActor
{
    public function __construct(
        public readonly ?int $ssoUserId,
        public readonly string $name,
        public readonly SettingsActorSource $source,
    ) {}

    /**
     * @param  array{sso_user_id?: int|string|null, name: string, source: string}  $actor
     */
    public static function fromArray(array $actor): self
    {
        return new self(
            isset($actor['sso_user_id']) ? (int) $actor['sso_user_id'] : null,
            $actor['name'],
            SettingsActorSource::from($actor['source']),
        );
    }

    /**
     * @return array{sso_user_id: int|null, name: string, source: string}
     */
    public function toArray(): array
    {
        return [
            'sso_user_id' => $this->ssoUserId,
            'name' => $this->name,
            'source' => $this->source->value,
        ];
    }
}
