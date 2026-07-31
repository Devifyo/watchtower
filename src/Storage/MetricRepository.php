<?php

namespace Watchtower\Storage;

use Illuminate\Support\Str;
use Throwable;

/**
 * The single funnel for every Watchtower write and the home of its
 * production-safety guarantees:
 *
 *   - enabled flag        — a hard off-switch
 *   - ignore lists        — skip noisy jobs / commands / exceptions
 *   - sampling            — store only a fraction of routine records
 *   - after-response      — defer writes to terminating() so latency is untouched
 *   - truncation          — bound every stored blob to a byte cap
 *   - swallowed failures  — a monitoring write must never break the app
 *
 * Listeners hand this class small closures; it decides when and whether to run
 * them. Reads go straight through the Eloquent models.
 */
class MetricRepository
{
    public function __construct(protected array $config)
    {
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function recording(string $feature): bool
    {
        return $this->enabled() && (bool) ($this->config['recording'][$feature] ?? true);
    }

    /**
     * Run a write closure according to the configured strategy. Critical writes
     * (failures, schedule runs) bypass sampling via $force.
     *
     * @param  callable():void  $callback
     */
    public function write(callable $callback, bool $force = false): void
    {
        if (! $this->enabled()) {
            return;
        }

        if (! $force && ! $this->passesSampling()) {
            return;
        }

        if ($this->deferred() && function_exists('app') && app()->bound('events')) {
            app()->terminating(fn () => $this->safely($callback));

            return;
        }

        $this->safely($callback);
    }

    /**
     * A monitoring write must never bubble an exception into the host app.
     */
    protected function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            // Intentionally swallowed: Watchtower observes, it never breaks.
            if (function_exists('logger')) {
                logger()->debug('Watchtower write failed: '.$e->getMessage());
            }
        }
    }

    protected function deferred(): bool
    {
        if (! ($this->config['writes']['after_response'] ?? true)) {
            return false;
        }

        // terminating() only fires when the process ends. In a long-running
        // queue worker that means job records would sit at "processing" until
        // the worker is restarted, so console contexts always write inline.
        return ! (function_exists('app') && app()->runningInConsole());
    }

    protected function passesSampling(): bool
    {
        $rate = (float) ($this->config['sampling']['rate'] ?? 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) <= $rate;
    }

    /**
     * Truncate a value to the configured byte cap for the given limit key.
     */
    public function truncate(?string $value, string $limitKey): ?string
    {
        if ($value === null) {
            return null;
        }

        $cap = (int) ($this->config['limits'][$limitKey] ?? 8192);

        if (strlen($value) <= $cap) {
            return $value;
        }

        return substr($value, 0, $cap)."\n… [truncated by Watchtower]";
    }

    public function shouldStorePayload(): bool
    {
        return (bool) ($this->config['limits']['store_payload'] ?? true);
    }

    /**
     * Is the given class/command on an ignore list?
     */
    public function ignored(string $type, string $value): bool
    {
        foreach ((array) ($this->config['ignore'][$type] ?? []) as $needle) {
            if ($needle === $value || Str::is($needle, $value)) {
                return true;
            }
        }

        return false;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
