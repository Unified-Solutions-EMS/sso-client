<?php

namespace Unified\SsoClient\Http\AgencyStatus;

enum AgencyStatusHealthLevel: string
{
    case Ok = 'ok';
    case Degraded = 'degraded';
    case Down = 'down';
    case Unknown = 'unknown';
}
