<?php

namespace Watchtower\Support;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Watchtower\Models\ScheduleRun;

/**
 * Reads the live scheduler definition from the container and joins it to the
 * recorded run history, computing next/last/missed state for each task.
 */
class ScheduleInspector
{
    /** Seconds of slack before a not-yet-recorded run is considered missed. */
    protected int $grace = 90;

    protected ?Schedule $schedule = null;

    public function __construct(protected Container $container)
    {
    }

    /**
     * Laravel 11+ registers scheduled tasks from bootstrap/app.php's
     * withSchedule() and routes/console.php — both of which only run once the
     * console kernel has booted and the Artisan application has started. In an
     * HTTP request the container would otherwise hand back an empty Schedule,
     * so boot the console side first (idempotent, and a no-op under artisan).
     */
    protected function schedule(): Schedule
    {
        if ($this->schedule) {
            return $this->schedule;
        }

        try {
            $this->container->make(ConsoleKernel::class)->all();
        } catch (\Throwable) {
            // Non-standard kernel: fall back to whatever is in the container.
        }

        return $this->schedule = $this->container->make(Schedule::class);
    }

    /**
     * @return Collection<int, array>
     */
    public function tasks(): Collection
    {
        $events = collect($this->schedule()->events());

        // Latest recorded run per task_key, in one query.
        $keys = $events->map(fn (SchedulingEvent $e) => TaskKey::for($e))->all();

        $latest = ScheduleRun::query()
            ->whereIn('task_key', $keys)
            ->orderByDesc('started_at')
            ->get()
            ->groupBy('task_key')
            ->map(fn ($runs) => $runs->first());

        return $events->map(function (SchedulingEvent $event) use ($latest) {
            $key = TaskKey::for($event);
            $last = $latest->get($key);
            $expression = $event->expression ?? null;
            $timezone = $event->timezone ? (string) $event->timezone : config('app.timezone', 'UTC');

            return [
                'key' => $key,
                'command' => $this->describe($event),
                'expression' => $expression,
                'human' => CronDescriber::describe($expression),
                'timezone' => $timezone,
                'next_run_at' => $this->nextRun($expression, $timezone)?->toIso8601String(),
                'last_run_at' => $last?->started_at?->toIso8601String(),
                'last_status' => $last?->status,
                'last_duration_ms' => $last?->duration_ms,
                'missed' => $this->isMissed($expression, $timezone, $last),
                'without_overlapping' => (bool) ($event->withoutOverlapping ?? false),
            ];
        })->values();
    }

    protected function nextRun(?string $expression, string $timezone): ?Carbon
    {
        if (! $expression || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        try {
            $date = (new CronExpression($expression))->getNextRunDate('now', 0, false, $timezone);

            return Carbon::instance($date);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A task is "missed" when it has run before but its latest recorded run is
     * older than the most recent expected run time (allowing a grace window).
     * This loudly surfaces a scheduler that has silently died.
     */
    protected function isMissed(?string $expression, string $timezone, ?ScheduleRun $last): bool
    {
        if (! $last || ! $expression || ! CronExpression::isValidExpression($expression)) {
            return false;
        }

        try {
            $reference = Carbon::now()->subSeconds($this->grace);
            $prevExpected = (new CronExpression($expression))
                ->getPreviousRunDate($reference, 0, true, $timezone);
        } catch (\Throwable) {
            return false;
        }

        if (! $last->started_at) {
            return true;
        }

        return $last->started_at->lt(Carbon::instance($prevExpected));
    }

    protected function describe(SchedulingEvent $event): string
    {
        return $event->description
            ?? $event->getSummaryForDisplay()
            ?? (string) $event->command;
    }

    /**
     * Find a scheduled event by its task key (for "run now").
     */
    public function find(string $key): ?SchedulingEvent
    {
        foreach ($this->schedule()->events() as $event) {
            if (TaskKey::for($event) === $key) {
                return $event;
            }
        }

        return null;
    }
}
