<?php

namespace Watchtower;

use Closure;
use Illuminate\Support\Facades\Gate;

/**
 * The main Watchtower entry point.
 *
 * Holds the dashboard authorization callback and a handful of static helpers.
 * Modelled on Laravel\Horizon\Horizon so the registration ergonomics feel
 * familiar: call Watchtower::auth() from a service provider to control access.
 */
class Watchtower
{
    /**
     * The package version.
     */
    public const VERSION = '1.0.2';

    /**
     * The callback that authorizes dashboard access.
     *
     * @var (Closure(\Illuminate\Http\Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * Register the callback used to authorize viewing the dashboard.
     *
     * @param  Closure(\Illuminate\Http\Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Determine whether the given request may view the dashboard.
     */
    public static function check($request): bool
    {
        if (static::$authUsing !== null) {
            return (bool) call_user_func(static::$authUsing, $request);
        }

        return Gate::check('viewWatchtower', [$request->user()]);
    }
}
