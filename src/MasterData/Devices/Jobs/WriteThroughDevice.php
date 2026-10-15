<?php

declare(strict_types=1);

namespace Unified\SsoClient\MasterData\Devices\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\MasterData\Devices\DeviceMirror;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * Sends one device to SSO's devices import (see DeviceWriteThrough). Best
 * effort: on a sync queue it runs inside the caller's request, so every
 * failure is logged and swallowed; the next push-up or the agency's own
 * edit in Settings heals a missed one.
 */
class WriteThroughDevice implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** Seconds SSO gets to answer; this runs beside a monitor transmission. */
    public const TIMEOUT = 15;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $device
     */
    public function __construct(
        public int $localCompanyId,
        public array $device,
    ) {}

    public function handle(MasterDataRegistry $registry, MasterDataClient $client): void
    {
        try {
            // The app's own company id, so the SSO id read here is authoritative (§4a).
            $ssoCompanyId = DB::table('companies')->where('id', $this->localCompanyId)->value('sso_company_id');

            if ($ssoCompanyId === null || $ssoCompanyId === '') {
                return;
            }

            $client->import('devices', $ssoCompanyId, [
                'app_slug' => (string) config('sso.app_slug'),
                'devices' => [$this->row($registry)],
            ], self::TIMEOUT);
        } catch (\Throwable $e) {
            Log::warning('SSO master data: device write-through failed; the next push or edit in Settings heals it', [
                'company_id' => $this->localCompanyId,
                'local_id' => $this->device['local_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(MasterDataRegistry $registry): array
    {
        $row = $this->device + ['source' => 'integration'];
        $localVehicleId = $row['vehicle_local_id'] ?? null;

        if (isset($row['vehicle_sso_id']) || ! is_numeric($localVehicleId)) {
            return $row;
        }

        $mirror = $registry->mirror('devices');

        if (! $mirror instanceof DeviceMirror) {
            return $row;
        }

        unset($row['vehicle_local_id']);

        return $row + $mirror->vehicleReference($this->localCompanyId, (int) $localVehicleId);
    }
}
