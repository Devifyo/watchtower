<?php

namespace Watchtower\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Support\Carbon;
use Watchtower\Models\ScheduleRun;
use Watchtower\Storage\MetricRepository;
use Watchtower\Support\TaskKey;

/**
 * Records scheduled-task run history from the framework's scheduler events.
 * One row per run, opened on "starting" and closed on finished/failed/skipped.
 */
class ScheduleListener
{
    public function __construct(protected MetricRepository $repository)
    {
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        if (! $this->shouldRecord($event->task)) {
            return;
        }

        $task = $event->task;
        $key = TaskKey::for($task);

        $this->repository->write(function () use ($task, $key) {
            ScheduleRun::query()->create([
                'task_key' => $key,
                'command' => $this->describe($task),
                'expression' => $task->expression ?? null,
                'timezone' => $this->timezone($task),
                'started_at' => Carbon::now(),
                'status' => 'running',
                'host' => gethostname() ?: null,
            ]);
        }, force: true);
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        if (! $this->shouldRecord($event->task)) {
            return;
        }

        $exitCode = $event->task->exitCode ?? 0;
        $this->close($event->task, $exitCode === 0 ? 'success' : 'failed', $exitCode, $event->runtime ?? null);
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        if (! $this->shouldRecord($event->task)) {
            return;
        }

        $this->close(
            $event->task,
            'failed',
            $event->task->exitCode ?? 1,
            null,
            method_exists($event, 'exception') ? null : ($event->exception?->getMessage() ?? null)
        );
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        if (! $this->shouldRecord($event->task)) {
            return;
        }

        $key = TaskKey::for($event->task);

        $this->repository->write(function () use ($event, $key) {
            ScheduleRun::query()->create([
                'task_key' => $key,
                'command' => $this->describe($event->task),
                'expression' => $event->task->expression ?? null,
                'timezone' => $this->timezone($event->task),
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
                'duration_ms' => 0,
                'status' => 'skipped',
                'host' => gethostname() ?: null,
            ]);
        }, force: true);
    }

    /**
     * Close the most recent open ("running") run for this task, or insert a
     * completed row if none was opened (e.g. starting event missed).
     */
    protected function close(SchedulingEvent $task, string $status, ?int $exitCode, ?float $runtime, ?string $output = null): void
    {
        $key = TaskKey::for($task);

        $this->repository->write(function () use ($task, $key, $status, $exitCode, $runtime, $output) {
            $run = ScheduleRun::query()
                ->where('task_key', $key)
                ->where('status', 'running')
                ->latest('started_at')
                ->first();

            $now = Carbon::now();

            if ($run) {
                $duration = $runtime !== null
                    ? (int) round($runtime * 1000)
                    : ($run->started_at ? (int) abs($now->diffInMilliseconds($run->started_at)) : null);

                $run->update([
                    'finished_at' => $now,
                    'duration_ms' => $duration,
                    'status' => $status,
                    'exit_code' => $exitCode,
                    'output' => $this->repository->truncate($output, 'output'),
                ]);

                return;
            }

            ScheduleRun::query()->create([
                'task_key' => $key,
                'command' => $this->describe($task),
                'expression' => $task->expression ?? null,
                'timezone' => $this->timezone($task),
                'started_at' => $now,
                'finished_at' => $now,
                'duration_ms' => $runtime !== null ? (int) round($runtime * 1000) : null,
                'status' => $status,
                'exit_code' => $exitCode,
                'output' => $this->repository->truncate($output, 'output'),
                'host' => gethostname() ?: null,
            ]);
        }, force: true);
    }

    protected function shouldRecord(SchedulingEvent $task): bool
    {
        if (! $this->repository->recording('schedule')) {
            return false;
        }

        return ! $this->repository->ignored('commands', $this->describe($task));
    }

    protected function describe(SchedulingEvent $task): string
    {
        return $task->description
            ?? $task->getSummaryForDisplay()
            ?? (string) $task->command;
    }

    protected function timezone(SchedulingEvent $task): ?string
    {
        $tz = $task->timezone ?? null;

        return $tz ? (string) $tz : null;
    }
}
