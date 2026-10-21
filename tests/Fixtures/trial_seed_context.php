<?php

/*
 * SSO's `trial.seed_data` `master_data.context` block (SeedTrialData, trial
 * rework plan Phase 0), exactly as SSO sends it.
 */
return [
    'state' => 'NY',
    'state_fips' => '36',
    'city' => 'Albany',
    'county_fips' => '36001',
    'zip' => '12207',
    'timezone' => 'America/New_York',
    'agency_name' => 'Example EMS',
    'phone' => '5185550100',
    'owner_user_id' => 12,
    'fake_user_ids' => [13, 14],
    'divisions' => ['North District', 'South District'],
    'stations' => [
        ['number' => 1, 'name' => 'Station 1 - Headquarters', 'street' => '100 Main St', 'city' => 'Albany', 'state' => 'NY', 'zip' => '12207'],
        ['number' => 2, 'name' => 'Station 2', 'street' => '200 State St', 'city' => 'Albany', 'state' => 'NY', 'zip' => '12207'],
        ['number' => 3, 'name' => 'Station 3', 'street' => '300 Central Ave', 'city' => 'Schenectady', 'state' => 'NY', 'zip' => '12305'],
        ['number' => 4, 'name' => 'Station 4', 'street' => '400 Western Ave', 'city' => 'Albany', 'state' => 'NY', 'zip' => '12203'],
    ],
    'vehicles' => [
        ['unit_number' => 'Medic 1', 'call_sign' => 'M1', 'type' => 'ALS'],
        ['unit_number' => 'Medic 2', 'call_sign' => 'M2', 'type' => 'ALS'],
        ['unit_number' => 'Medic 3', 'call_sign' => 'M3', 'type' => 'BLS'],
        ['unit_number' => 'Engine 1', 'call_sign' => 'E1', 'type' => 'Engine'],
        ['unit_number' => 'Engine 2', 'call_sign' => 'E2', 'type' => 'Engine'],
        ['unit_number' => 'Command 1', 'call_sign' => 'C1', 'type' => 'Command'],
        ['unit_number' => 'Rescue 1', 'call_sign' => 'R1', 'type' => 'Rescue'],
    ],
    'units' => ['Medic 1', 'Medic 2', 'Medic 3', 'Engine 1'],
    'facilities' => [
        ['name' => 'Albany Medical Center Hospital', 'type' => 'hospital'],
        ['name' => "St. Peter's Hospital", 'type' => 'hospital'],
    ],
    'people' => [
        ['user_id' => 13, 'first_name' => 'Jordan', 'last_name' => 'Reyes', 'licence' => 'DEMO-1234', 'level' => '9925007'],
        ['user_id' => 14, 'first_name' => 'Casey', 'last_name' => 'Morgan', 'licence' => 'DEMO-5678', 'level' => '9925005'],
    ],
];
