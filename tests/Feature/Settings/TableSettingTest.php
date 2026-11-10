<?php

namespace Unified\SsoClient\Tests\Feature\Settings;

use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use LogicException;
use Unified\SsoClient\Contracts\SettingsProvider;
use Unified\SsoClient\Settings\SettingsSchema;
use Unified\SsoClient\Tests\Fixtures\FakeTableSettingsProvider;
use Unified\SsoClient\Tests\TestCase;

/**
 * The `table` setting type: schema wire format, values passthrough, per-row
 * patch validation and what apply() receives.
 */
class TableSettingTest extends TestCase
{
    private FakeTableSettingsProvider $provider;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.core_api_key', 'core-key');
        $app['config']->set('sso.app_slug', 'cloudpcr');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeTableSettingsProvider;
        $this->app->instance(SettingsProvider::class, $this->provider);
    }

    /**
     * @param  list<mixed>  $rows
     */
    private function patchRows(array $rows, int $company = 42): TestResponse
    {
        return $this->withToken('core-key')->patchJson("/api/internal/settings/{$company}", [
            'patch' => ['non_agency_crew' => ['rows' => $rows]],
            'actor' => ['sso_user_id' => 7, 'name' => 'Dana Admin', 'source' => 'sso'],
        ])->assertOk();
    }

    public function test_schema_to_array_carries_the_columns_and_the_assistant_description(): void
    {
        $setting = $this->provider->schema()->toArray()['groups'][0]['settings'][0];

        $this->assertSame('non_agency_crew', $setting['key']);
        $this->assertSame('table', $setting['type']);
        $this->assertSame('Non-Agency Crew', $setting['label']);
        $this->assertSame('crew', $setting['group']);
        $this->assertNull($setting['options']);
        $this->assertSame(['rows' => [], 'options' => []], $setting['default']);
        $this->assertSame([
            ['key' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'required' => true, 'options' => null, 'options_from_values' => false, 'max' => 255],
            ['key' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'options' => null, 'options_from_values' => false, 'max' => 255],
            ['key' => 'level_code', 'label' => 'Level', 'type' => 'select', 'required' => false, 'options' => null, 'options_from_values' => true, 'max' => null],
            ['key' => 'certification_number', 'label' => 'Certification number', 'type' => 'text', 'required' => false, 'options' => null, 'options_from_values' => false, 'max' => 50],
            ['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'required' => false, 'options' => [['value' => 'ems', 'label' => 'EMS'], ['value' => 'fire', 'label' => 'Fire']], 'options_from_values' => false, 'max' => null],
            ['key' => 'is_active', 'label' => 'Active', 'type' => 'toggle', 'required' => false, 'options' => null, 'options_from_values' => false, 'max' => null],
        ], $setting['columns']);

        $this->assertSame(
            'A list of rows. Each row has an id and these columns: Last name (last_name): text, up to 255 characters, required; '
            .'First name (first_name): text, up to 255 characters, required; '
            .'Level (level_code): one of the values listed under options.level_code in the current value; '
            .'Certification number (certification_number): text, up to 50 characters; '
            .'Kind (kind): one of: ems (EMS), fire (Fire); Active (is_active): true or false. '
            .'To change it, send {"rows": [...]} with only the rows that change: a row without an id adds one, '
            .'a row with an id changes that row and only the columns it sends. '
            .'Rows are never deleted: set Active (is_active) to false to turn one off.',
            $setting['description'],
        );

        $toggle = $this->provider->schema()->toArray()['groups'][0]['settings'][1];
        $this->assertArrayNotHasKey('columns', $toggle, 'Other types keep their wire format.');
        $this->assertArrayNotHasKey('description', $toggle);
    }

    public function test_get_passes_the_rows_and_company_options_through(): void
    {
        $this->withToken('core-key')->getJson('/api/internal/settings/42')
            ->assertOk()
            ->assertJsonPath('values.non_agency_crew.rows.0.last_name', 'Smith')
            ->assertJsonPath('values.non_agency_crew.rows.0.id', 1)
            ->assertJsonPath('values.non_agency_crew.options.level_code.1', ['value' => '9925005', 'label' => 'Paramedic'])
            ->assertJsonPath('schema.groups.0.settings.0.type', 'table');
    }

    public function test_valid_rows_reach_apply_validated_with_only_declared_columns(): void
    {
        $this->patchRows([
            ['last_name' => 'Lee', 'first_name' => 'Ann', 'level_code' => '9925005', 'certification_number' => '', 'kind' => 'fire', 'is_active' => 1, 'not_a_column' => 'dropped'],
            ['id' => '1', 'is_active' => false],
            ['id' => 1 + 1, 'last_name' => 'Diaz', 'level_code' => null],
        ])->assertJsonPath('results.non_agency_crew.status', 'saved');

        $this->assertCount(1, $this->provider->applyCalls);
        $this->assertSame(['non_agency_crew' => ['rows' => [
            ['last_name' => 'Lee', 'first_name' => 'Ann', 'level_code' => '9925005', 'certification_number' => null, 'kind' => 'fire', 'is_active' => true],
            ['id' => 1, 'is_active' => false],
            ['id' => 2, 'last_name' => 'Diaz', 'level_code' => null],
        ]]], $this->provider->applyCalls[0]['patch']);
    }

    public function test_a_new_row_needs_every_required_column_and_a_change_only_the_ones_it_sends(): void
    {
        $this->patchRows([['last_name' => 'Lee']])
            ->assertJsonPath('results.non_agency_crew.status', 'invalid')
            ->assertJsonPath('results.non_agency_crew.message', 'Row 1: The First name field is required.');

        $this->patchRows([['id' => 1, 'last_name' => '']])
            ->assertJsonPath('results.non_agency_crew.message', 'Row 1: The Last name field is required.');

        $this->assertSame([], $this->provider->applyCalls);
    }

    public function test_select_columns_are_checked_against_static_and_per_company_options(): void
    {
        $this->patchRows([['id' => 1, 'kind' => 'police']])
            ->assertJsonPath('results.non_agency_crew.status', 'invalid')
            ->assertJsonPath('results.non_agency_crew.message', 'Row 1: The selected Kind is invalid.');

        $this->patchRows([['id' => 1, 'level_code' => '9925005'], ['id' => 2, 'level_code' => 'not-a-level']])
            ->assertJsonPath('results.non_agency_crew.message', 'Row 2: The selected Level is invalid.');

        $this->patchRows([['last_name' => 'Lee', 'first_name' => 'Ann', 'level_code' => '9925007']], 43)
            ->assertJsonPath('results.non_agency_crew.message', 'Row 1: The selected Level is invalid.', 'Company 43 has no levels, so 42\'s do not count.');

        $this->assertSame([], $this->provider->applyCalls);
    }

    public function test_patch_reads_values_once_for_the_company(): void
    {
        $this->patchRows([['id' => 1, 'level_code' => '9925005']])->assertJsonPath('results.non_agency_crew.status', 'saved');

        $this->assertSame(1, $this->provider->valuesCalls);
    }

    public function test_malformed_tables_are_invalid(): void
    {
        foreach ([
            [['rows' => 'nope'], 'Non-Agency Crew needs a list of rows.'],
            [['rows' => ['a' => []]], 'Non-Agency Crew needs a list of rows.'],
            [['rows' => ['x']], 'Row 1 of Non-Agency Crew is not a row.'],
            [['rows' => [['id' => 'abc', 'is_active' => true]]], 'Row 1 of Non-Agency Crew has an id that is not a whole number.'],
            [['rows' => [['id' => 1, 'is_active' => true], ['id' => 1, 'is_active' => false]]], 'Row 2 of Non-Agency Crew repeats a row already in this change.'],
            [['rows' => [['id' => 1, 'is_active' => 'maybe']]], 'Row 1: The Active field must be true or false.'],
            [['rows' => [['id' => 1, 'certification_number' => str_repeat('9', 51)]]], 'Row 1: The Certification number field must not be greater than 50 characters.'],
        ] as [$value, $message]) {
            $this->withToken('core-key')->patchJson('/api/internal/settings/42', [
                'patch' => ['non_agency_crew' => $value],
                'actor' => ['sso_user_id' => 7, 'name' => 'Dana Admin', 'source' => 'sso'],
            ])->assertOk()->assertJsonPath('results.non_agency_crew', ['status' => 'invalid', 'message' => $message]);
        }

        $this->assertSame([], $this->provider->applyCalls);
    }

    public function test_an_invalid_table_does_not_hold_back_other_keys(): void
    {
        $results = $this->withToken('core-key')->patchJson('/api/internal/settings/42', [
            'patch' => ['non_agency_crew' => ['rows' => [['last_name' => 'Lee']]], 'crew.allow_non_agency' => false],
            'actor' => ['sso_user_id' => 7, 'name' => 'Dana Admin', 'source' => 'sso'],
        ])->assertOk()->json('results');

        $this->assertSame('invalid', $results['non_agency_crew']['status']);
        $this->assertSame('saved', $results['crew.allow_non_agency']['status']);

        $this->assertSame(['crew.allow_non_agency' => false], $this->provider->applyCalls[0]['patch']);
    }

    public function test_column_declarations_are_checked(): void
    {
        $bad = [
            [['key' => 'id', 'label' => 'Id']],
            [['key' => 'a.b', 'label' => 'Dotted']],
            [['key' => 'x', 'label' => 'X', 'type' => 'date']],
            [['key' => 'x', 'label' => 'X', 'type' => 'select']],
            [['key' => 'x', 'label' => 'X', 'type' => 'text', 'options' => ['a' => 'A']]],
            [['key' => 'x', 'label' => 'X'], ['key' => 'x', 'label' => 'X again']],
            [],
        ];

        foreach ($bad as $columns) {
            try {
                SettingsSchema::make()->group('g', 'G')->table('t', 'T')->columns($columns);
                $this->fail('Accepted columns '.json_encode($columns));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        SettingsSchema::make()->group('g', 'G')->toggle('t', 'T')->columns([['key' => 'x', 'label' => 'X']]);
    }

    public function test_static_options_accept_a_value_label_map(): void
    {
        $setting = SettingsSchema::make()->group('g', 'G')->table('t', 'T')
            ->columns([['key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => ['ems' => 'EMS']]])
            ->find('t');

        $this->assertSame([['value' => 'ems', 'label' => 'EMS']], $setting->toArray()['columns'][0]['options']);
        $this->assertSame('A list of rows. Each row has an id and these columns: Kind (kind): one of: ems (EMS). To change it, send {"rows": [...]} with only the rows that change: a row without an id adds one, a row with an id changes that row and only the columns it sends. Rows are never deleted.', $setting->description());
    }
}
