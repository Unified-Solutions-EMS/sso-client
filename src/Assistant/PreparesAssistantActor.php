<?php

namespace Unified\SsoClient\Assistant;

/**
 * Optional hook on an AssistantToolProvider. The package already signs the
 * acting user in on the default guard, loads the company's roles and sets
 * session('selected_company_id'); implement this when the app's own login
 * establishes the tenant some other way (a tenancy container binding, a
 * different session key, a cached scope).
 */
interface PreparesAssistantActor
{
    public function prepare(AssistantActor $actor): void;
}
