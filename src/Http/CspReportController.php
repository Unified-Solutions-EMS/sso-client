<?php

declare(strict_types=1);

namespace Unified\SsoClient\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Unified\SsoClient\Security\SecurityEvents;

/**
 * Receives browser reports for the report-only Content-Security-Policy that
 * SecurityHeaders sends, and records a sampled share of them as info
 * `csp.violation` security events.
 *
 * Browsers post either `application/csp-report` ({"csp-report": {...}}, the
 * report-uri format) or `application/reports+json` ([{type, body}], the
 * Reporting API). Only the blocked resource's host (or keyword such as
 * `inline` / `eval`) and the directive are kept: never the page URL, the
 * referrer or the script sample, any of which can carry record ids or text.
 * Always answers 204; a browser does nothing with the response.
 */
class CspReportController extends Controller
{
    private const MAX_BODY_BYTES = 65536;

    private const MAX_REPORTS_PER_REQUEST = 10;

    public function __invoke(Request $request, SecurityEvents $security): Response
    {
        if (! config('security-headers.csp_report_only.enabled', false)
            || ! config('security-headers.csp_report_only.report', true)) {
            return response()->noContent();
        }

        $content = (string) $request->getContent();

        if ($content === '' || strlen($content) > self::MAX_BODY_BYTES) {
            return response()->noContent();
        }

        $decoded = json_decode($content, true);

        foreach (array_slice($this->reports($decoded), 0, self::MAX_REPORTS_PER_REQUEST) as $report) {
            if (! $this->sampled()) {
                continue;
            }

            $security->info('csp.violation', [
                'blocked' => $this->blocked($report['blocked'] ?? null),
                'directive' => $this->directive($report['directive'] ?? null),
            ]);
        }

        return response()->noContent();
    }

    /**
     * @return list<array{blocked: mixed, directive: mixed}>
     */
    protected function reports(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            return [];
        }

        if (isset($decoded['csp-report']) && is_array($decoded['csp-report'])) {
            $report = $decoded['csp-report'];

            return [[
                'blocked' => $report['blocked-uri'] ?? null,
                'directive' => $report['effective-directive'] ?? $report['violated-directive'] ?? null,
            ]];
        }

        $reports = [];

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'csp-violation' || ! is_array($entry['body'] ?? null)) {
                continue;
            }

            $reports[] = [
                'blocked' => $entry['body']['blockedURL'] ?? null,
                'directive' => $entry['body']['effectiveDirective'] ?? null,
            ];
        }

        return $reports;
    }

    protected function sampled(): bool
    {
        $rate = (float) config('security-headers.csp_report_only.sample_rate', 0.1);

        if ($rate <= 0) {
            return false;
        }

        return $rate >= 1 || random_int(1, 1_000_000) <= (int) round($rate * 1_000_000);
    }

    protected function blocked(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return 'unknown';
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return mb_substr(strtolower($host), 0, 255);
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (is_string($scheme) && preg_match('/^[a-z-]{1,20}$/', strtolower($scheme)) === 1) {
            return strtolower($scheme);
        }

        return preg_match('/^[a-z-]{1,20}$/', strtolower($value)) === 1 ? strtolower($value) : 'other';
    }

    protected function directive(mixed $value): string
    {
        $first = strtolower(strtok(is_string($value) ? trim($value) : '', ' ') ?: '');

        return preg_match('/^[a-z-]{1,40}$/', $first) === 1 ? $first : 'unknown';
    }
}
