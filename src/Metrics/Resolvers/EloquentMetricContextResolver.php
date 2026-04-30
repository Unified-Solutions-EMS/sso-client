<?php

declare(strict_types=1);

namespace Unified\SsoClient\Metrics\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Unified\SsoClient\Metrics\Contracts\MetricContextResolver;

/**
 * Default resolver. Looks up the configured Company / User Eloquent
 * model and reads the configured sso_*_id column.
 *
 * Apps with non-standard schemas can bind a custom MetricContextResolver
 * implementation in their AppServiceProvider — this default exists so
 * the common case (companies.sso_company_id, users.sso_id) needs zero
 * code in the consuming app.
 */
class EloquentMetricContextResolver implements MetricContextResolver
{
    public function __construct(
        protected string $companyModel,
        protected string $userModel,
        protected string $companySsoIdColumn = 'sso_company_id',
        protected string $userSsoIdColumn = 'sso_id',
    ) {}

    public function ssoCompanyId(?int $localCompanyId): ?int
    {
        return $this->lookupSsoId($this->companyModel, $localCompanyId, $this->companySsoIdColumn);
    }

    public function ssoUserId(?int $localUserId): ?int
    {
        return $this->lookupSsoId($this->userModel, $localUserId, $this->userSsoIdColumn);
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    protected function lookupSsoId(string $modelClass, ?int $localId, string $column): ?int
    {
        if ($localId === null || ! class_exists($modelClass)) {
            return null;
        }

        $model = $modelClass::query()->find($localId, [$column]);

        if ($model === null) {
            return null;
        }

        $value = $model->getAttribute($column);

        return $value === null ? null : (int) $value;
    }
}
