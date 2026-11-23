<?php

namespace Unified\SsoClient\Contracts;

/**
 * An app's `App\Services\TrialDataSeeder` implements this to tell SSO its
 * seeder is safe to repeat, so SSO may send `trial.seed_data` again after a
 * timeout or a 502/504 (when the first delivery may still be seeding), not
 * only after the app's own 429/500/503.
 *
 * Implement it only when all of this holds:
 * - a second delivery for the same company writes nothing;
 * - two overlapping deliveries write exactly one set of sample data (a
 *   per-company lock in the shared cache, and the "already seeded?" check
 *   re-read inside the seed's transaction under a row lock);
 * - a seed that failed part way is completed by the next delivery, without
 *   duplicating what the first wrote;
 * - a company SSO no longer lists as a trial is never seeded.
 *
 * The package says so in every `trial.seed_data` answer
 * (`seed_repeatable: true`); SSO records it per app.
 */
interface RepeatableTrialSeeder {}
