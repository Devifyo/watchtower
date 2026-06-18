<?php

use Illuminate\Support\Facades\Route;
use Watchtower\Http\Controllers\DashboardController;
use Watchtower\Http\Controllers\ExceptionController;
use Watchtower\Http\Controllers\OverviewController;
use Watchtower\Http\Controllers\QueueController;
use Watchtower\Http\Controllers\ScheduleController;
use Watchtower\Http\Controllers\SetupController;

/*
|--------------------------------------------------------------------------
| Watchtower Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed (config: watchtower.path) and run behind the
| configured middleware stack plus the Authorize middleware (viewWatchtower
| gate). The "/{view?}" catch-all lets the Vue SPA own client-side routing.
|
*/

// JSON API ------------------------------------------------------------------
Route::prefix('api')->name('api.')->group(function () {
    Route::get('overview', [OverviewController::class, 'index'])->name('overview');

    // First-run / multi-tenant setup helper.
    Route::get('setup/status', [SetupController::class, 'status'])->name('setup.status');
    Route::post('setup/migrate', [SetupController::class, 'migrate'])->name('setup.migrate');

    // Schedule
    Route::get('schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('schedule/history', [ScheduleController::class, 'history'])->name('schedule.history');
    Route::post('schedule/run', [ScheduleController::class, 'run'])->name('schedule.run');

    // Queues & jobs
    Route::get('queues/metrics', [QueueController::class, 'metrics'])->name('queues.metrics');
    Route::get('queues/failed', [QueueController::class, 'failed'])->name('queues.failed');
    Route::post('queues/failed/{uuid}/retry', [QueueController::class, 'retry'])->name('queues.retry');
    Route::delete('queues/failed/{uuid}', [QueueController::class, 'destroy'])->name('queues.destroy');
    Route::post('queues/failed/retry-bulk', [QueueController::class, 'bulkRetry'])->name('queues.bulkRetry');

    // Exceptions
    Route::get('exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
    Route::get('exceptions/{id}', [ExceptionController::class, 'show'])->name('exceptions.show');
    Route::post('exceptions/{id}/resolve', [ExceptionController::class, 'resolve'])->name('exceptions.resolve');
    Route::post('exceptions/{id}/reopen', [ExceptionController::class, 'reopen'])->name('exceptions.reopen');
});

// Compiled SPA assets, served straight from the package's dist/ directory so a
// plain `composer require` works with no vendor:publish step.
Route::get('assets/app.js', [DashboardController::class, 'js'])->name('assets.js');
Route::get('assets/app.css', [DashboardController::class, 'css'])->name('assets.css');

// SPA shell — must be last so it doesn't shadow the API/asset routes.
Route::get('/{view?}', [DashboardController::class, 'index'])
    ->where('view', '(.*)')
    ->name('dashboard');
