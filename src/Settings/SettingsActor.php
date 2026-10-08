<?php

namespace Unified\SsoClient\Settings;

/**
 * Who asked for a settings change, for the app's audit trail. Every source
 * names an SSO user: an AI-sourced change names the person who approved it.
 */
final class SettingsActor
{
    public function __construct(
        public readonly int $ssoUserId,
        public readonly string $name,
        public readonly SettingsActorSource $source,
    ) {}

    /**
     * @param  array{sso_user_id: int|string, name: string, source: string}  $actor
     */
    public static function fromArray(array $actor): self
    {
        return new self(
            (int) $actor['sso_user_id'],
            $actor['name'],
            SettingsActorSource::from($actor['source']),
        );
    }

    /**
     * @return array{sso_user_id: int, name: string, source: string}
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
