<?php

namespace Redbit\Matomo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Redbit\Matomo\Matomo;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tracks every successful full-page GET as a Matomo screen view.
 * The route name (when present) is used as the screen title.
 */
class TrackScreenView
{
    public function __construct(protected Matomo $matomo) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldTrack($request, $response)) {
            $this->matomo->trackScreen($request->path(), $request->route()?->getName());
        }

        return $response;
    }

    protected function shouldTrack(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && ! $request->ajax()
            && ! $request->is('_native/*')
            && $response->isSuccessful()
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
