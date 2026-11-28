<?php

declare(strict_types=1);

namespace Unified\SsoClient\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Unified\SsoClient\Release\ReleaseChannel;

/**
 * Local override and debugging for release channels. SSO is the normal
 * source: its value replaces this one at the agency's next sign-in sync or
 * `company.updated` delivery.
 *
 * Reads the companies table itself (no tenant scope involved) and writes one
 * row, found by the SSO company id the operator names.
 */
class ReleaseChannelCommand extends Command
{
    protected $signature = 'sso:release-channel
        {ssoCompanyId? : The agency\'s SSO company id}
        {channel? : stable or beta}
        {--list : Show this deployment\'s settings and the agencies not on stable}';

    protected $description = 'Put one agency on the stable or beta release channel here, or list the agencies not on stable.';

    public function handle(): int
    {
        if (! ReleaseChannel::hasColumn()) {
            $this->error('The companies table has no release_channel column yet. Run php artisan migrate first.');

            return self::FAILURE;
        }

        if ($this->option('list')) {
            return $this->listAgencies();
        }

        $ssoCompanyId = trim((string) $this->argument('ssoCompanyId'));
        $channel = ReleaseChannel::agencyValue($this->argument('channel'));

        if (! ctype_digit($ssoCompanyId)) {
            $this->error('Usage: sso:release-channel {ssoCompanyId} {stable|beta}, or sso:release-channel --list');

            return self::FAILURE;
        }

        if ($channel === null) {
            $this->error('Unknown channel "'.$this->argument('channel').'". Use stable or beta.');

            return self::FAILURE;
        }

        $model = ReleaseChannel::companyModel();
        $companies = $model::query()->where('sso_company_id', $ssoCompanyId)->get();

        if ($companies->count() !== 1) {
            $this->error($companies->isEmpty()
                ? "No agency here has SSO company id {$ssoCompanyId}."
                : "More than one agency here has SSO company id {$ssoCompanyId}; nothing changed.");

            return self::FAILURE;
        }

        /** @var Model $company */
        $company = $companies->first();
        $previous = ReleaseChannel::forCompany($company);

        $company->forceFill([ReleaseChannel::COLUMN => $channel])->save();

        $this->info("[{$company->getKey()}] {$company->getAttribute('name')} (SSO {$ssoCompanyId}): {$previous} -> {$channel}");
        $this->line('SSO sets this value too; its next sign-in sync or company update replaces this one.');

        if ($channel === ReleaseChannel::BETA && ReleaseChannel::url(ReleaseChannel::BETA) === null) {
            $this->warn('No beta address is set on this deployment (RELEASE_BETA_URL), so nobody is sent to beta from here.');
        }

        return self::SUCCESS;
    }

    private function listAgencies(): int
    {
        $this->line('This deployment: '.ReleaseChannel::current());
        $this->line('Agencies sorted between deployments here: '.(ReleaseChannel::routingActive() ? 'yes' : 'no'));

        foreach (ReleaseChannel::AGENCY_CHANNELS as $channel) {
            $this->line(sprintf(
                '%s: %s, worker queue "%s"',
                $channel,
                ReleaseChannel::url($channel) ?? '(no address)',
                ReleaseChannel::workerQueue($channel),
            ));
        }

        $model = ReleaseChannel::companyModel();
        $rows = $model::query()
            ->where(ReleaseChannel::COLUMN, '!=', ReleaseChannel::STABLE)
            ->orderBy('name')
            ->get(['id', 'sso_company_id', 'name', ReleaseChannel::COLUMN]);

        if ($rows->isEmpty()) {
            $this->info('Every agency is on stable.');

            return self::SUCCESS;
        }

        $this->table(
            ['Local id', 'SSO id', 'Agency', 'Channel'],
            $rows->map(fn (Model $c): array => [
                $c->getKey(),
                $c->getAttribute('sso_company_id'),
                $c->getAttribute('name'),
                $c->getAttribute(ReleaseChannel::COLUMN),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
