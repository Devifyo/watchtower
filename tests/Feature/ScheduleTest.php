<?php

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Watchtower\Models\ScheduleRun;
use Watchtower\Watchtower;

beforeEach(fn () => Watchtower::auth(fn () => true));
afterEach(fn () => Watchtower::$authUsing = null);

it('records a successful run from starting + finished events', function () {
    $event = app(Schedule::class)->command('inspire')->everyMinute();

    event(new ScheduledTaskStarting($event));
    $event->exitCode = 0;
    event(new ScheduledTaskFinished($event, 0.123));

    expect(ScheduleRun::count())->toBe(1);

    $run = ScheduleRun::first();
    expect($run->status)->toBe('success');
    expect($run->duration_ms)->toBe(123);
    expect($run->command)->toContain('inspire');
});

it('stores a non-negative duration when it is derived from started_at', function () {
    // Carbon 3 diffs are signed; duration_ms is an unsigned column, so a
    // negative value is rejected by MySQL outright.
    $event = app(Schedule::class)->command('inspire')->everyMinute();

    event(new ScheduledTaskStarting($event));
    $this->travel(2)->seconds();
    event(new ScheduledTaskFailed($event, new Exception('boom')));

    expect(ScheduleRun::first()->duration_ms)->toBeGreaterThanOrEqual(2000);
});

it('records a skipped run', function () {
    $event = app(Schedule::class)->command('inspire')->everyMinute();

    event(new ScheduledTaskSkipped($event));

    expect(ScheduleRun::where('status', 'skipped')->count())->toBe(1);
});

it('returns the registered tasks via the API with missed detection', function () {
    app(Schedule::class)->command('inspire')->everyMinute();

    $response = $this->getJson('watchtower/api/schedule');

    $response->assertOk()
        ->assertJsonStructure([
            'tasks' => [['key', 'command', 'human', 'next_run_at', 'missed']],
            'summary' => ['total', 'missed', 'failing'],
        ]);

    expect($response->json('summary.total'))->toBe(1);
    // No history yet ⇒ not flagged missed (avoids false alarms on fresh install).
    expect($response->json('tasks.0.missed'))->toBeFalse();
});

it('flags a task as missed when its last run predates the expected run', function () {
    $event = app(Schedule::class)->command('inspire')->hourly();

    ScheduleRun::create([
        'task_key' => \Watchtower\Support\TaskKey::for($event),
        'command' => 'inspire',
        'expression' => '0 * * * *',
        'started_at' => now()->subDays(2),
        'finished_at' => now()->subDays(2),
        'status' => 'success',
    ]);

    $response = $this->getJson('watchtower/api/schedule');

    expect($response->json('tasks.0.missed'))->toBeTrue();
    expect($response->json('summary.missed'))->toBe(1);
});

it('runs an artisan-backed task on demand and captures its output', function () {
    \Illuminate\Support\Facades\Artisan::command('wt:ping', function () {
        $this->line('pong');
    });

    $event = app(Schedule::class)->command('wt:ping')->everyMinute();
    $key = \Watchtower\Support\TaskKey::for($event);

    $response = $this->postJson('watchtower/api/schedule/run', ['key' => $key]);

    $response->assertOk()
        ->assertJsonPath('result.status', 'success')
        ->assertJsonPath('result.exit_code', 0);

    expect($response->json('result.output'))->toContain('pong');
    expect(ScheduleRun::where('status', 'success')->count())->toBeGreaterThan(0);
});

it('runs a callback task on demand', function () {
    $ran = false;
    app(Schedule::class)->call(function () use (&$ran) {
        $ran = true;
    })->everyMinute();

    $key = \Watchtower\Support\TaskKey::for(app(Schedule::class)->events()[0]);

    $this->postJson('watchtower/api/schedule/run', ['key' => $key])
        ->assertOk()
        ->assertJsonPath('result.status', 'success');
});

it('returns 404 running an unknown task', function () {
    $this->postJson('watchtower/api/schedule/run', ['key' => 'nope'])
        ->assertNotFound();
});
