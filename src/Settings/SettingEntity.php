<?php

namespace Unified\SsoClient\Settings;

/**
 * The SSO lists a setting can point at. settingsPath() is the SSO Settings
 * tab that edits the list, so an app's read-only editor can link "Edit in
 * Settings" to `{SSO_BASE_URL}{path}` (settingsUrl()); SSO answers a
 * browser visit to the path with /system and that tab open.
 */
enum SettingEntity: string
{
    case Vehicle = 'vehicle';
    case Station = 'station';
    case Personnel = 'personnel';
    case Facility = 'facility';
    case Qualification = 'qualification';
    case Agency = 'agency';
    case Contacts = 'contacts';
    case Devices = 'devices';
    case StateConfigurations = 'state_configurations';
    case Units = 'units';
    case Rotations = 'rotations';

    public function settingsPath(): string
    {
        return match ($this) {
            self::Vehicle => '/system/vehicles',
            self::Station => '/system/locations',
            self::Personnel => '/system/users',
            self::Facility => '/system/facilities',
            self::Qualification => '/system/qualifications',
            self::Agency => '/system/agency',
            self::Contacts => '/system/contacts',
            self::Devices => '/system/devices',
            self::StateConfigurations => '/system/state-configuration',
            self::Units => '/system/units',
            self::Rotations => '/system/rotations',
        };
    }

    public function settingsUrl(): string
    {
        return rtrim((string) config('sso.base_url'), '/').$this->settingsPath();
    }
}
