<?php

namespace Unified\SsoClient\Contracts;

use Unified\SsoClient\Assistant\AssistantActor;
use Unified\SsoClient\Assistant\AssistantTool;

/**
 * Exposes this app's tools to the Unified Assistant (the one platform-wide
 * assistant whose model runs in SSO). Bind an implementation in
 * AppServiceProvider::register(), or name it in config('sso.assistant.tool_provider'):
 *
 *   $this->app->bind(AssistantToolProvider::class, \App\Assistant\CrewAssistantTools::class);
 *
 * The package registers GET /api/internal/assistant/tools (catalog) and
 * POST /api/internal/assistant/tools/{name} (execute) behind CORE_APP_API_KEY.
 * Before either reaches the provider the package resolves the local user from
 * users.sso_id and the local company from companies.sso_company_id, checks the
 * user belongs to that company, calls $user->loadRolesForCompany($company->id),
 * sets the acting user on the default guard and session('selected_company_id'),
 * so tools authorize with $actor->user->can('...') exactly as the UI does.
 * Implement PreparesAssistantActor as well when the app's login does more than
 * that to establish the tenant.
 *
 * Apps that bind nothing answer an empty catalog, which is a normal answer:
 * SSO's own tools, how-do-I answers and the Help handoff still work there.
 */
interface AssistantToolProvider
{
    /**
     * Every tool this user may see in this company, including tools that
     * only exist to reverse others (mark those HiddenAssistantTool). Return
     * [] when the company has no presence in this app.
     *
     * @return list<AssistantTool>
     */
    public function tools(AssistantActor $actor): array;
}
