<?php

namespace Unified\SsoClient\Settings;

enum SettingsActorSource: string
{
    case Sso = 'sso';
    case App = 'app';
    case Ai = 'ai';
}
