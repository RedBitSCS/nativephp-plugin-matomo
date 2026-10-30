<?php

namespace Redbit\Matomo\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null configure(string $url, int $siteId, ?int $dispatchInterval = null)
 * @method static bool trackScreen(string $path, ?string $title = null, array $dimensions = [])
 * @method static bool trackEvent(string $category, string $action, ?string $name = null, int|float|null $value = null, array $dimensions = [])
 * @method static bool trackSearch(string $keyword, ?string $category = null, ?int $count = null)
 * @method static bool trackGoal(int $goalId, int|float|null $revenue = null)
 * @method static bool setUserId(?string $userId)
 * @method static bool setDimension(int $id, ?string $value)
 * @method static bool setOptOut(bool $optOut = true)
 * @method static bool optIn()
 * @method static bool|null isOptedOut()
 * @method static bool dispatch()
 * @method static bool startNewSession()
 * @method static array|null getStatus()
 *
 * @see \Redbit\Matomo\Matomo
 */
class Matomo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Redbit\Matomo\Matomo::class;
    }
}
