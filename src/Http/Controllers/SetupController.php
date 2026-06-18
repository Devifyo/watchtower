<?php

namespace Watchtower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Powers the in-dashboard "Set up database" screen. Lets an authorized user
 * (the viewWatchtower gate already guards every route) create Watchtower's
 * tables without dropping to a terminal — handy on first run and the common
 * multi-tenant footgun where the tables live on a different connection.
 *
 * migrate() runs ONLY this package's migration files, never the host app's.
 */
class SetupController
{
    /** The un-prefixed Watchtower tables that must exist. */
    protected array $tables = ['schedule_runs', 'job_records', 'exceptions'];

    public function status(): JsonResponse
    {
        return response()->json($this->state());
    }

    public function migrate(): JsonResponse
    {
        $connection = config('watchtower.connection') ?: config('database.default');
        $path = realpath(__DIR__.'/../../../database/migrations');

        if ($path === false) {
            return response()->json(['ok' => false, 'message' => 'Could not locate Watchtower migrations.'], 500);
        }

        try {
            Artisan::call('migrate', [
                '--path' => $path,
                '--realpath' => true,
                '--database' => $connection,
                '--force' => true,
            ]);
            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'connection' => $connection,
                'message' => $e->getMessage(),
            ], 500);
        }

        $state = $this->state();

        return response()->json(array_merge([
            'ok' => $state['installed'],
            'output' => $output,
            'message' => $state['installed']
                ? 'Watchtower tables created successfully.'
                : 'Migrations ran but the tables still are not visible on this connection.',
        ], $state));
    }

    protected function state(): array
    {
        $connection = config('watchtower.connection') ?: null;
        $prefix = config('watchtower.table_prefix', 'watchtower_');

        $present = [];
        $installed = true;

        foreach ($this->tables as $table) {
            try {
                $has = Schema::connection($connection)->hasTable($prefix.$table);
            } catch (Throwable) {
                $has = false;
            }
            $present[$table] = $has;
            $installed = $installed && $has;
        }

        return [
            'installed' => $installed,
            'connection' => $connection ?: config('database.default'),
            'tables' => $present,
        ];
    }
}
