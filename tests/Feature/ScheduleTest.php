<?php

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
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

it('records a skipped run', function () {
    $event = app(Schedule::class)->command('inspire')->everyMinute();

    event(new ScheduledTaskSkipped($event));

    expect(ScheduleRun::where('status', 'skipped')->count())->toBe(1);
});

it('discovers tasks registered only on the console side', function () {
    // Mirrors Laravel 11+ bootstrap/app.php withSchedule(): the callback is
    // wired through Artisan::starting, which never fires during an HTTP
    // request unless the inspector boots the console kernel itself.
    Artisan::starting(fn () => app()->afterResolving(
        Schedule::class,
        fn (Schedule $schedule) => $schedule->command('inspire')->hourly()
    ));

    $response = $this->getJson('watchtower/api/schedule');

    $response->assertOk();
    expect($response->json('summary.total'))->toBe(1);
    expect($response->json('tasks.0.command'))->toContain('inspire');
});

it('discovers tasks from the legacy App\\Console\\Kernel::schedule() style', function () {
    // Laravel 10 and below define tasks by overriding schedule() on the
    // console kernel — the container only reaches it via that kernel.
    app()->singleton(ConsoleKernel::class, fn ($app) => new class($app, $app['events']) extends FoundationKernel
    {
        protected function schedule(Schedule $schedule): void
        {
            $schedule->command('inspire')->daily();
        }
    });
    app()->forgetInstance(Schedule::class);

    $response = $this->getJson('watchtower/api/schedule');

    $response->assertOk();
    expect($response->json('summary.total'))->toBe(1);
    expect($response->json('tasks.0.command'))->toContain('inspire');
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
