<?php

namespace Redbit\Matomo;

class Matomo
{
    /**
     * Override the build-time MATOMO_URL / MATOMO_SITE_ID until the app restarts.
     *
     * @param  int|null  $dispatchInterval  Seconds between automatic dispatches
     */
    public function configure(string $url, int $siteId, ?int $dispatchInterval = null): ?array
    {
        return $this->call('Matomo.Configure', array_filter([
            'url' => $url,
            'site_id' => $siteId,
            'dispatch_interval' => $dispatchInterval,
        ], fn ($value) => $value !== null));
    }

    /**
     * Track a screen view.
     *
     * @param  string  $path  Screen path, e.g. "/articles/42"
     * @param  array<int, string>  $dimensions  Dimension index => value, for this hit only
     */
    public function trackScreen(string $path, ?string $title = null, array $dimensions = []): bool
    {
        return $this->tracked($this->call('Matomo.TrackScreen', [
            'path' => '/'.ltrim($path, '/'),
            'title' => $title,
            'dimensions' => $this->dimensions($dimensions),
        ]));
    }

    /**
     * Track a custom event.
     *
     * @param  array<int, string>  $dimensions  Dimension index => value, for this hit only
     */
    public function trackEvent(
        string $category,
        string $action,
        ?string $name = null,
        int|float|null $value = null,
        array $dimensions = [],
    ): bool {
        return $this->tracked($this->call('Matomo.TrackEvent', [
            'category' => $category,
            'action' => $action,
            'name' => $name,
            'value' => $value,
            'dimensions' => $this->dimensions($dimensions),
        ]));
    }

    /**
     * Track an in-app search.
     */
    public function trackSearch(string $keyword, ?string $category = null, ?int $count = null): bool
    {
        return $this->tracked($this->call('Matomo.TrackSearch', [
            'keyword' => $keyword,
            'category' => $category,
            'count' => $count,
        ]));
    }

    /**
     * Track a goal conversion.
     */
    public function trackGoal(int $goalId, int|float|null $revenue = null): bool
    {
        return $this->tracked($this->call('Matomo.TrackGoal', [
            'goal_id' => $goalId,
            'revenue' => $revenue,
        ]));
    }

    /**
     * Set the user ID sent with every following hit, or null to clear it.
     */
    public function setUserId(?string $userId): bool
    {
        return $this->call('Matomo.SetUserId', ['user_id' => $userId]) !== null;
    }

    /**
     * Set a custom dimension on every following hit, or null to remove it.
     */
    public function setDimension(int $id, ?string $value): bool
    {
        return $this->call('Matomo.SetDimension', ['id' => $id, 'value' => $value]) !== null;
    }

    /**
     * Opt the user out of (or back into) tracking. The SDK persists this across launches.
     */
    public function setOptOut(bool $optOut = true): bool
    {
        return $this->call('Matomo.SetOptOut', ['opt_out' => $optOut]) !== null;
    }

    public function optIn(): bool
    {
        return $this->setOptOut(false);
    }

    public function isOptedOut(): ?bool
    {
        $status = $this->getStatus();

        return $status === null ? null : (bool) ($status['optedOut'] ?? false);
    }

    /**
     * Send queued hits now instead of waiting for the dispatch interval.
     */
    public function dispatch(): bool
    {
        return $this->call('Matomo.Dispatch') !== null;
    }

    /**
     * Start a new visit with the next hit, e.g. after logging out.
     */
    public function startNewSession(): bool
    {
        return $this->call('Matomo.StartNewSession') !== null;
    }

    /**
     * @return array{configured: bool, url: string, siteId: int, optedOut: bool, userId: string}|null
     */
    public function getStatus(): ?array
    {
        return $this->call('Matomo.GetStatus');
    }

    protected function call(string $method, array $parameters = []): ?array
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call($method, json_encode((object) $parameters));

        if (! $result) {
            return null;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded) || ($decoded['status'] ?? null) === 'error') {
            return null;
        }

        return $decoded['data'] ?? $decoded;
    }

    protected function tracked(?array $response): bool
    {
        return (bool) ($response['tracked'] ?? false);
    }

    /**
     * Encode as a JSON object keyed by dimension index, dropping empty values.
     */
    protected function dimensions(array $dimensions): object
    {
        return (object) array_filter(
            array_map(fn ($value) => $value === null ? null : (string) $value, $dimensions),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
