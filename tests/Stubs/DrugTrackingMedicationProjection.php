<?php

namespace Unified\SsoClient\Tests\Stubs;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\Medications\MedicationProjection;

/**
 * Drug Tracking's shape, reduced: `company_drugs` (name, rxcui, controlled,
 * schedule) as the stock list. The push sends each drug with no levels (Drug
 * Tracking does not say who may give it); project() links each drug to the
 * SSO medication with its code in `sso_medication_id`, and clears the link
 * when SSO has no such code.
 */
class DrugTrackingMedicationProjection implements MedicationProjection
{
    public function localItems(int $localCompanyId): array
    {
        return DB::table('company_drugs')->where('company_id', $localCompanyId)->orderBy('id')->get()
            ->map(fn (object $drug): array => [
                'key' => 'drug-'.$drug->id,
                'code' => $drug->rxcui,
                'label' => $drug->name,
                'levels' => [],
                'is_controlled' => (bool) $drug->is_controlled,
                'dea_schedule' => $drug->dea_schedule,
            ])
            ->all();
    }

    public function project(int $localCompanyId, array $items): void
    {
        $ssoByCode = [];
        foreach ($items as $item) {
            if ($item['code'] !== null) {
                $ssoByCode[$item['code']] = $item['sso_id'];
            }
        }

        foreach (DB::table('company_drugs')->where('company_id', $localCompanyId)->get() as $drug) {
            $wanted = $drug->rxcui === null ? null : ($ssoByCode[$drug->rxcui] ?? null);

            if ($drug->sso_medication_id !== $wanted) {
                DB::table('company_drugs')->where('company_id', $localCompanyId)->where('id', $drug->id)->update(['sso_medication_id' => $wanted]);
            }
        }
    }
}
