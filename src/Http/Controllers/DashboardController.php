<?php

namespace Watchtower\Http\Controllers;

use Illuminate\Http\Response;
use Watchtower\Watchtower;

class DashboardController
{
    /**
     * Render the SPA shell. All client-side routes (#/schedule, #/queues, ...)
     * resolve to this single Blade view; Vue takes over from there.
     */
    public function index()
    {
        return response()->view('watchtower::dashboard', [
            'config' => [
                'path' => config('watchtower.path', 'watchtower'),
                'version' => Watchtower::VERSION,
                'pollingInterval' => (int) config('watchtower.dashboard.polling_interval', 5000),
                'perPage' => (int) config('watchtower.dashboard.per_page', 25),
                'recording' => config('watchtower.recording', []),
                'tablePrefix' => config('watchtower.table_prefix', 'watchtower_'),
            ],
        ]);
    }

    /**
     * Serve the compiled JS bundle directly from the package's dist/ dir.
     */
    public function js(): Response
    {
        return $this->asset(__DIR__.'/../../../dist/app.js', 'application/javascript');
    }

    /**
     * Serve the compiled CSS bundle.
     */
    public function css(): Response
    {
        return $this->asset(__DIR__.'/../../../dist/app.css', 'text/css');
    }

    protected function asset(string $path, string $contentType): Response
    {
        abort_unless(is_file($path), 404);

        $contents = (string) file_get_contents($path);
        $lastModified = (int) filemtime($path);

        return response($contents, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified).' GMT',
        ]);
    }
}
