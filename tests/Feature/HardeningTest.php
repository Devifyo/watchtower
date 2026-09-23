<?php

use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Schema;
use Watchtower\Listeners\ExceptionListener;
use Watchtower\Models\ExceptionRecord;
use Watchtower\Models\JobRecord;
use Watchtower\Models\ScheduleRun;

function job(string $uuid)
{
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\X');
    $job->shouldReceive('getConnectionName')->andReturn('sync');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn(['uuid' => $uuid, 'secret' => 'sensitive-data']);
    $job->shouldReceive('attempts')->andReturn(1);

    return $job;
}

afterEach(fn () => Mockery::close());

// ── enabled flag ────────────────────────────────────────────────────────────

it('records nothing when disabled', function () {
    config()->set('watchtower.enabled', false);

    event(new ScheduledTaskSkipped(app(Schedule::class)->command('inspire')->everyMinute()));
    app(ExceptionListener::class)->handle(new RuntimeException('x'));

    expect(ScheduleRun::count())->toBe(0);
    expect(ExceptionRecord::count())->toBe(0);
});

// ── sampling ──────────────────────────────────────────────────────────────────

it('drops routine writes at sample rate 0 but always keeps failures', function () {
    config()->set('watchtower.sampling.rate', 0.0);

    event(new JobProcessing('sync', job('keep-fail')));
    event(new JobProcessed('sync', job('routine')));   // sampled away
    event(new JobFailed('sync', job('keep-fail'), new Exception('boom'))); // forced

    // The processed (routine) write was sampled away; the failure was forced.
    expect(JobRecord::where('status', 'failed')->count())->toBe(1);
    expect(JobRecord::where('status', 'processed')->count())->toBe(0);
});

it('keeps everything at sample rate 1', function () {
    config()->set('watchtower.sampling.rate', 1.0);

    event(new JobProcessing('sync', job('a')));
    event(new JobProcessed('sync', job('a')));

    expect(JobRecord::where('status', 'processed')->count())->toBe(1);
});

// ── truncation & payload redaction ────────────────────────────────────────────

it('truncates stored exception messages to the configured cap', function () {
    config()->set('watchtower.limits.message', 32);

    app(ExceptionListener::class)->handle(new RuntimeException(str_repeat('A', 500)));

    $message = ExceptionRecord::first()->message;
    expect(strlen($message))->toBeLessThan(100);
    expect($message)->toContain('truncated');
});

it('does not store job payloads when disabled', function () {
    config()->set('watchtower.limits.store_payload', false);

    event(new JobProcessing('sync', job('np')));

    expect(JobRecord::first()->payload)->toBeNull();
});

// ── after-response deferral ─────────────────────────────────────────────────────

it('writes inline in console context even when after_response is on', function () {
    // terminating() never fires inside a long-running queue worker, so console
    // writes must not be deferred.
    config()->set('watchtower.writes.after_response', true);

    event(new ScheduledTaskSkipped(app(Schedule::class)->command('inspire')->everyMinute()));

    expect(ScheduleRun::count())->toBe(1);
});

// ── separate connection ──────────────────────────────────────────────────────

it('writes to a separate configured connection', function () {
    config()->set('database.connections.wt_secondary', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    config()->set('watchtower.connection', 'wt_secondary');

    Schema::connection('wt_secondary')->create('watchtower_schedule_runs', function ($t) {
        $t->bigIncrements('id');
        $t->string('task_key', 64);
        $t->string('command');
        $t->string('expression', 64)->nullable();
        $t->string('timezone', 64)->nullable();
        $t->timestamp('started_at')->nullable();
        $t->timestamp('finished_at')->nullable();
        $t->unsignedInteger('duration_ms')->nullable();
        $t->string('status', 16)->default('running');
        $t->longText('output')->nullable();
        $t->integer('exit_code')->nullable();
        $t->string('host', 128)->nullable();
    });

    event(new ScheduledTaskSkipped(app(Schedule::class)->command('inspire')->everyMinute()));

    expect((new ScheduleRun)->getConnectionName())->toBe('wt_secondary');
    expect(ScheduleRun::on('wt_secondary')->count())->toBe(1);
    // The default connection's table was never touched.
    expect(\Illuminate\Support\Facades\DB::connection('testing')->table('watchtower_schedule_runs')->count())->toBe(0);
});
