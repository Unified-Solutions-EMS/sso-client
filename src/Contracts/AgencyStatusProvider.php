<?php

namespace Unified\SsoClient\Contracts;

use Unified\SsoClient\Http\AgencyStatus\AgencyStatusResponse;

/**
 * Each consuming app binds an implementation of this contract in its
 * AppServiceProvider so the SSO hub can ask "what's this company's status
 * inside your app?" via a single shared endpoint.
 *
 *   $this->app->bind(AgencyStatusProvider::class, MyAgencyStatusProvider::class);
 *
 * The endpoint at /api/internal/agency-status/{ssoCompanyId} resolves and
 * invokes the bound provider. SSO's MCP server composes responses from
 * every app to give Fin / coding agents a unified picture.
 */
interface AgencyStatusProvider
{
    public function appSlug(): string;

    public function build(string $ssoCompanyId): AgencyStatusResponse;
}
