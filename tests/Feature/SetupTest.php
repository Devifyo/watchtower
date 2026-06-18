<?php

use Illuminate\Support\Facades\Schema;
use Watchtower\Watchtower;

beforeEach(fn () => Watchtower::auth(fn () => true));
afterEach(fn () => Watchtower::$authUsing = null);

it('reports installed status when the tables exist', function () {
    $this->getJson('watchtower/api/setup/status')
        ->assertOk()
        ->assertJsonPath('installed', true)
        ->assertJsonStructure(['installed', 'connection', 'tables' => ['schedule_runs', 'job_records', 'exceptions']]);
});

it('returns a friendly 503 (not a raw SQL error) when a table is missing', function () {
    Schema::drop('watchtower_schedule_runs');

    $this->getJson('watchtower/api/overview')
        ->assertStatus(503)
        ->assertJsonPath('error', 'watchtower_not_installed')
        ->assertJsonFragment(['connection' => 'testing']);
});

it('creates the tables from the setup endpoint on a fresh connection', function () {
    // Mirror the real multi-tenant fix: point Watchtower at a separate
    // connection that has never been migrated, then set it up via the endpoint.
    config()->set('database.connections.wt_setup', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    config()->set('watchtower.connection', 'wt_setup');

    $this->getJson('watchtower/api/setup/status')
        ->assertOk()
        ->assertJsonPath('installed', false)
        ->assertJsonPath('connection', 'wt_setup');

    $this->postJson('watchtower/api/setup/migrate')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('installed', true);

    expect(Schema::connection('wt_setup')->hasTable('watchtower_schedule_runs'))->toBeTrue();
});
