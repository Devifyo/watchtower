<?php

namespace Watchtower\Actions;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Throwable;
use Watchtower\Models\ScheduleRun;
use Watchtower\Support\TaskKey;

/**
 * Runs a scheduled task on demand and returns its output. Command-backed events
 * are executed via Artisan with a buffered output so we can show what happened;
 * callback events are simply invoked. Either way a row is recorded so the run
 * appears in history alongside scheduler-driven runs.
 */
class RunScheduledTask
{
    public function execute(SchedulingEvent $event): array
    {
        $start = Carbon::now();
        $output = '';
        $exitCode = 0;
        $status = 'success';

        try {
            if ($event instanceof CallbackEvent) {
                $event->run(app());
                $output = '(callback executed)';
            } elseif ($command = $this->artisanCommand($event)) {
                $exitCode = Artisan::call($command);
                $output = trim(Artisan::output());
                $status = $exitCode === 0 ? 'success' : 'failed';
            } else {
                // Generic shell event — run it through the framework.
                $event->run(app());
                $output = '(command executed)';
            }
        } catch (Throwable $e) {
            $status = 'failed';
            $exitCode = 1;
            $output = $e->getMessage();
        }

        $finish = Carbon::now();

        $run = ScheduleRun::query()->create([
            'task_key' => TaskKey::for($event),
            'command' => $event->description ?? $event->getSummaryForDisplay() ?? (string) $event->command,
            'expression' => $event->expression ?? null,
            'timezone' => $event->timezone ? (string) $event->timezone : null,
            'started_at' => $start,
            'finished_at' => $finish,
            'duration_ms' => (int) abs($finish->diffInMilliseconds($start)),
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => $this->cap($output),
            'host' => gethostname() ?: null,
        ]);

        return [
            'status' => $status,
            'exit_code' => $exitCode,
            'output' => $run->output,
            'duration_ms' => $run->duration_ms,
        ];
    }

    /**
     * Extract the artisan sub-command from a command event's CLI string, e.g.
     * "'/usr/bin/php' 'artisan' queue:work --tries=3" => "queue:work --tries=3".
     */
    protected function artisanCommand(SchedulingEvent $event): ?string
    {
        $command = (string) ($event->command ?? '');

        if ($command === '' || ! str_contains($command, 'artisan')) {
            return null;
        }

        $position = strpos($command, 'artisan');
        $remainder = trim(substr($command, $position + strlen('artisan')));

        // Strip the leading quote that wrapped the artisan path token.
        $remainder = ltrim($remainder, "'\" ");

        return $remainder !== '' ? $remainder : null;
    }

    protected function cap(string $output): string
    {
        $cap = (int) config('watchtower.limits.output', 8192);

        return strlen($output) > $cap
            ? substr($output, 0, $cap)."\n… [truncated by Watchtower]"
            : $output;
    }
}
