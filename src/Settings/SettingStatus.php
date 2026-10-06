<?php

namespace Unified\SsoClient\Settings;

enum SettingStatus: string
{
    case Saved = 'saved';
    case Invalid = 'invalid';
    case Blocked = 'blocked';
    case UnknownKey = 'unknown_key';
}
