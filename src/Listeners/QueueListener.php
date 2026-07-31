<?php

namespace Watchtower\Listeners;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Carbon;
use Watchtower\Models\JobRecord;
use Watchtower\Storage\MetricRepository;

/**
 * Records job lifecycle from Laravel's portable queue events (never Redis
 * internals), so it works identically on the database, redis and sqs drivers.
 * Rows are upserted keyed by the job UUID.
 */
class QueueListener
{
    public function __construct(protected MetricRepository $repository)
    {
    }

    /**
     * JobQueued fires on push for drivers that support it. The "job" here is the
     * original job object/string, not a resolved Job instance.
     */
    public function queued(JobQueued $event): void
    {
        if (! $this->repository->recording('queue')) {
            return;
        }

        $name = $this->classOf($event->job);

        if ($this->repository->ignored('jobs', $name)) {
            return;
        }

        $uuid = $this->uuidFromQueuedEvent($event);
        $payload = $this->payloadFromQueuedEvent($event);

        $this->repository->write(function () use ($event, $uuid, $name, $payload) {
            $this->upsert($uuid, [
                'connection' => $event->connectionName ?? null,
                'queue' => $event->queue ?? $this->queueFromPayload($payload),
                'name' => $name,
                'status' => 'queued',
                'queued_at' => Carbon::now(),
                'payload' => $this->payloadColumn($payload),
            ]);
        });
    }

    public function processing(JobProcessing $event): void
    {
        $this->touchFromJob($event->job, function (JobContract $job) {
            return [
                'status' => 'processing',
                'started_at' => Carbon::now(),
                'attempts' => $job->attempts(),
            ];
        });
    }

    public function processed(JobProcessed $event): void
    {
        $this->touchFromJob($event->job, function (JobContract $job) {
            $now = Carbon::now();
            $record = $this->find($job->uuid());

            return [
                'status' => 'processed',
                'finished_at' => $now,
                'attempts' => $job->attempts(),
                'duration_ms' => $this->durationFor($record, $now),
            ];
        });
    }

    public function failed(JobFailed $event): void
    {
        // Failures are always recorded — bypass sampling.
        $this->touchFromJob($event->job, function (JobContract $job) {
            $now = Carbon::now();
            $record = $this->find($job->uuid());

            return [
                'status' => 'failed',
                'finished_at' => $now,
                'attempts' => $job->attempts(),
                'duration_ms' => $this->durationFor($record, $now),
            ];
        }, force: true);
    }

    public function released(JobReleasedAfterException $event): void
    {
        $this->touchFromJob($event->job, function (JobContract $job) {
            return [
                'status' => 'released',
                'attempts' => $job->attempts(),
            ];
        }, force: true);
    }

    // ── internals ──────────────────────────────────────────────────────────

    /**
     * @param  callable(JobContract):array  $attributes
     */
    protected function touchFromJob($job, callable $attributes, bool $force = false): void
    {
        if (! $this->repository->recording('queue') || ! $job instanceof JobContract) {
            return;
        }

        $name = $job->resolveName();

        if ($this->repository->ignored('jobs', $name)) {
            return;
        }

        $uuid = $job->uuid();
        $base = [
            'connection' => $job->getConnectionName(),
            'queue' => $job->getQueue(),
            'name' => $name,
            'payload' => $this->payloadColumn($job->payload()),
        ];

        $this->repository->write(function () use ($uuid, $base, $attributes, $job) {
            $this->upsert($uuid, array_merge($base, $attributes($job)));
        }, force: $force);
    }

    protected function upsert(?string $uuid, array $attributes): void
    {
        if ($uuid) {
            $record = JobRecord::query()->firstOrNew(['uuid' => $uuid]);
            // Don't let a payload re-write null over a stored value, and never
            // regress a terminal status back to an earlier one on a late event.
            if ($record->exists && ($attributes['payload'] ?? null) === null) {
                unset($attributes['payload']);
            }
            $record->fill($attributes)->save();

            return;
        }

        JobRecord::query()->create($attributes);
    }

    protected function find(?string $uuid): ?JobRecord
    {
        return $uuid ? JobRecord::query()->where('uuid', $uuid)->first() : null;
    }

    protected function durationFor(?JobRecord $record, Carbon $now): ?int
    {
        if ($record && $record->started_at) {
            return (int) abs($now->diffInMilliseconds($record->started_at));
        }

        return null;
    }

    protected function payloadColumn(array|string|null $payload): ?string
    {
        if (! $this->repository->shouldStorePayload() || $payload === null) {
            return null;
        }

        $json = is_string($payload) ? $payload : json_encode($payload);

        return $this->repository->truncate($json ?: null, 'payload');
    }

    protected function classOf($job): string
    {
        return is_object($job) ? get_class($job) : (string) $job;
    }

    protected function uuidFromQueuedEvent(JobQueued $event): ?string
    {
        $payload = $this->payloadFromQueuedEvent($event);

        return $payload['uuid'] ?? (is_object($event->job) && method_exists($event->job, 'uuid') ? $event->job->uuid() : null);
    }

    protected function payloadFromQueuedEvent(JobQueued $event): ?array
    {
        if (method_exists($event, 'payload')) {
            $raw = $event->payload();
            if (is_string($raw)) {
                return json_decode($raw, true) ?: null;
            }
            if (is_array($raw)) {
                return $raw;
            }
        }

        return null;
    }

    protected function queueFromPayload(?array $payload): ?string
    {
        return $payload['queue'] ?? null;
    }
}
