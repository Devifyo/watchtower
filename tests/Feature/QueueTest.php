<?php

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Watchtower\Models\JobRecord;
use Watchtower\Watchtower;

beforeEach(fn () => Watchtower::auth(fn () => true));
afterEach(function () {
    Watchtower::$authUsing = null;
    Mockery::close();
});

function fakeJob(string $uuid, string $name = 'App\\Jobs\\SendInvoice', int $attempts = 1)
{
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('getConnectionName')->andReturn('database');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn(['uuid' => $uuid, 'displayName' => $name]);
    $job->shouldReceive('attempts')->andReturn($attempts);

    return $job;
}

it('records the job lifecycle from queue events keyed by uuid', function () {
    $job = fakeJob('uuid-1');

    event(new JobProcessing('database', $job));
    event(new JobProcessed('database', $job));

    expect(JobRecord::count())->toBe(1);

    $record = JobRecord::first();
    expect($record->uuid)->toBe('uuid-1');
    expect($record->status)->toBe('processed');
    expect($record->name)->toBe('App\\Jobs\\SendInvoice');
    expect($record->started_at)->not->toBeNull();
    expect($record->finished_at)->not->toBeNull();
});

it('marks a job failed on JobFailed', function () {
    $job = fakeJob('uuid-2');

    event(new JobProcessing('database', $job));
    event(new JobFailed('database', $job, new Exception('boom')));

    $record = JobRecord::where('uuid', 'uuid-2')->first();
    expect($record->status)->toBe('failed');
});

it('records processed and failed jobs inline in a worker (after_response on)', function () {
    // terminating() never fires inside queue:work — both the success and the
    // failure path must land in the DB while the worker is still running.
    config()->set('watchtower.writes.after_response', true);

    $ok = fakeJob('uuid-worker-ok');
    event(new JobProcessing('database', $ok));
    event(new JobProcessed('database', $ok));

    $bad = fakeJob('uuid-worker-fail');
    event(new JobProcessing('database', $bad));
    event(new JobFailed('database', $bad, new Exception('boom')));

    expect(JobRecord::where('uuid', 'uuid-worker-ok')->value('status'))->toBe('processed');
    expect(JobRecord::where('uuid', 'uuid-worker-fail')->value('status'))->toBe('failed');
});

it('respects the job ignore list', function () {
    config()->set('watchtower.ignore.jobs', ['App\\Jobs\\SendInvoice']);

    event(new JobProcessing('database', fakeJob('uuid-3')));

    expect(JobRecord::count())->toBe(0);
});

it('returns queue metrics in the expected shape', function () {
    $base = ['name' => 'Foo', 'queued_at' => null, 'started_at' => null, 'finished_at' => null, 'duration_ms' => null, 'attempts' => 0, 'connection' => null, 'payload' => null, 'exception_id' => null];
    JobRecord::insert([
        array_merge($base, ['uuid' => 'a', 'queue' => 'default', 'status' => 'processed', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'duration_ms' => 100, 'attempts' => 1]),
        array_merge($base, ['uuid' => 'b', 'queue' => 'default', 'status' => 'processed', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'duration_ms' => 300, 'attempts' => 1]),
        array_merge($base, ['uuid' => 'c', 'queue' => 'emails', 'status' => 'failed', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'duration_ms' => 50, 'attempts' => 2]),
        array_merge($base, ['uuid' => 'd', 'queue' => 'emails', 'status' => 'queued', 'queued_at' => now()]),
    ]);

    $response = $this->getJson('watchtower/api/queues/metrics?window=day');

    $response->assertOk()->assertJsonStructure([
        'totals' => ['pending', 'processing', 'processed', 'failed'],
        'duration' => ['avg_ms', 'p95_ms'],
        'per_queue', 'throughput', 'pending_estimate',
    ]);

    expect($response->json('totals.processed'))->toBe(2);
    expect($response->json('totals.failed'))->toBe(1);
    expect($response->json('totals.pending'))->toBe(1);
    expect($response->json('duration.avg_ms'))->toBe(200);
});

// ── Failed jobs against the real database failer ──────────────────────────────

function setUpDatabaseQueue(): void
{
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database', [
        'driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
    ]);
    config()->set('queue.failed', [
        'driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs',
    ]);

    // The failer singleton may have resolved during boot with the old config;
    // forget it so it rebuilds as the UUID-backed provider.
    app()->forgetInstance('queue.failer');

    Schema::create('jobs', function ($t) {
        $t->bigIncrements('id');
        $t->string('queue')->index();
        $t->longText('payload');
        $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at');
        $t->unsignedInteger('created_at');
    });

    Schema::create('failed_jobs', function ($t) {
        $t->bigIncrements('id');
        $t->string('uuid')->unique();
        $t->text('connection');
        $t->text('queue');
        $t->longText('payload');
        $t->longText('exception');
        $t->timestamp('failed_at')->useCurrent();
    });
}

function logFailedJob(string $name = 'App\\Jobs\\SendInvoice', string $queue = 'default'): string
{
    $uuid = (string) Str::uuid();
    $payload = json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => $name, 'data' => []]);

    app('queue.failer')->log('database', $queue, $payload, new Exception('Database deadlock'));

    return $uuid;
}

it('lists native failed jobs with pagination + filters', function () {
    setUpDatabaseQueue();
    logFailedJob('App\\Jobs\\SendInvoice', 'default');
    logFailedJob('App\\Jobs\\ProcessImage', 'media');

    $response = $this->getJson('watchtower/api/queues/failed');

    $response->assertOk()->assertJsonStructure([
        'data' => [['id', 'uuid', 'queue', 'name', 'exception_summary', 'failed_at']],
        'meta' => ['page', 'per_page', 'total', 'last_page'],
        'filters' => ['exception_classes', 'queues'],
    ]);
    expect($response->json('meta.total'))->toBe(2);
});

it('retries a single failed job via the native mechanism', function () {
    setUpDatabaseQueue();
    $uuid = logFailedJob();

    expect(app('queue.failer')->all())->toHaveCount(1);

    $this->postJson("watchtower/api/queues/failed/{$uuid}/retry")->assertOk();

    // Native retry removes it from failed_jobs and pushes it back onto the queue.
    expect(app('queue.failer')->all())->toHaveCount(0);
    expect(app('db')->table('jobs')->count())->toBe(1);
});

it('deletes a failed job via the native mechanism', function () {
    setUpDatabaseQueue();
    $uuid = logFailedJob();

    $this->deleteJson("watchtower/api/queues/failed/{$uuid}")->assertOk();

    expect(app('queue.failer')->all())->toHaveCount(0);
});

it('bulk-retries all failed jobs', function () {
    setUpDatabaseQueue();
    logFailedJob();
    logFailedJob('App\\Jobs\\ProcessImage', 'media');

    $response = $this->postJson('watchtower/api/queues/failed/retry-bulk', ['mode' => 'all']);

    $response->assertOk()->assertJsonPath('count', 2);
    expect(app('queue.failer')->all())->toHaveCount(0);
    expect(app('db')->table('jobs')->count())->toBe(2);
});

it('bulk-retries by exception type', function () {
    setUpDatabaseQueue();
    logFailedJob();
    logFailedJob('App\\Jobs\\ProcessImage', 'media');

    $response = $this->postJson('watchtower/api/queues/failed/retry-bulk', [
        'mode' => 'exception',
        'exception_class' => 'Exception',
    ]);

    // Both used the same Exception class.
    $response->assertOk()->assertJsonPath('count', 2);
});

function queuedEvent(string $uuid, string $name = 'App\\Jobs\\SendInvoice'): JobQueued
{
    return new JobQueued('redis', 'default', 'id-'.$uuid, $name, json_encode(['uuid' => $uuid, 'displayName' => $name]), null);
}

it('keeps the worker progress when the dispatcher records the job after it', function () {
    // On a fast queue a worker can finish the job before the dispatcher's JobQueued write lands.
    $job = fakeJob('uuid-late');

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));
    event(queuedEvent('uuid-late'));

    expect(JobRecord::where('uuid', 'uuid-late')->get())->toHaveCount(1)
        ->and(JobRecord::where('uuid', 'uuid-late')->value('status'))->toBe('processed')
        ->and(JobRecord::where('uuid', 'uuid-late')->value('queued_at'))->not->toBeNull();
});

it('stores one row per job uuid', function () {
    // Two processes can record the same job at the same instant: only the index makes the second insert lose.
    JobRecord::query()->create(['uuid' => 'uuid-unique', 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'queued']);

    expect(fn () => JobRecord::query()->create(['uuid' => 'uuid-unique', 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'processing']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('collapses existing duplicate rows onto the most advanced one when the unique index is added', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_11_06_000001_make_watchtower_job_records_uuid_unique.php';
    $migration->down();

    JobRecord::query()->insert([
        ['uuid' => 'uuid-dup', 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'processed', 'finished_at' => now()],
        ['uuid' => 'uuid-dup', 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'processing', 'finished_at' => null],
        ['uuid' => 'uuid-other', 'name' => 'App\\Jobs\\SendInvoice', 'status' => 'processing', 'finished_at' => null],
    ]);

    $migration->up();

    expect(JobRecord::where('uuid', 'uuid-dup')->pluck('status')->all())->toBe(['processed'])
        ->and(JobRecord::where('uuid', 'uuid-other')->count())->toBe(1);
});
