<?php

beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifest = json_decode(file_get_contents($this->pluginPath.'/nativephp.json'), true);
});

describe('Plugin Manifest', function () {
    it('is valid JSON with the required fields', function () {
        expect($this->manifest)->toBeArray()->toHaveKeys(['namespace', 'bridge_functions']);
    });

    it('maps every bridge function to android and ios', function () {
        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'android', 'ios']);
        }
    });

    it('implements every bridge function natively', function () {
        $kotlin = file_get_contents($this->pluginPath.'/resources/android/MatomoFunctions.kt');
        $swift = file_get_contents($this->pluginPath.'/resources/ios/MatomoFunctions.swift');

        foreach ($this->manifest['bridge_functions'] as $function) {
            $class = substr($function['name'], strlen('Matomo.'));

            expect($kotlin)->toContain("class {$class}(");
            expect($swift)->toContain("class {$class}: BridgeFunction");
        }
    });

    it('declares the build-time secrets used by the config assets', function () {
        expect($this->manifest['secrets'])->toHaveKeys(['MATOMO_URL', 'MATOMO_SITE_ID']);

        foreach (array_keys($this->manifest['assets']['android']) as $source) {
            expect(file_exists($this->pluginPath.'/resources/'.$source))->toBeTrue();
        }
    });
});

describe('PHP API', function () {
    it('is a no-op outside a native build', function () {
        $matomo = new Redbit\Matomo\Matomo;

        expect($matomo->trackScreen('/home'))->toBeFalse();
        expect($matomo->trackEvent('Quiz', 'Completed'))->toBeFalse();
        expect($matomo->getStatus())->toBeNull();
        expect($matomo->isOptedOut())->toBeNull();
    });
});

describe('Composer Configuration', function () {
    it('is a nativephp plugin', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect($composer['type'])->toBe('nativephp-plugin');
    });
});
