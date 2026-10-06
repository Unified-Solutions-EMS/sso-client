<?php

namespace Unified\SsoClient\Settings;

enum SettingEntity: string
{
    case Vehicle = 'vehicle';
    case Station = 'station';
    case Personnel = 'personnel';
    case Facility = 'facility';
    case Qualification = 'qualification';
}
