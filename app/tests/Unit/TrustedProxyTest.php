<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config()->set('trustedproxy.proxies', ['172.20.0.0/16']);

    Route::get('/_tests/request-url', fn () => [
        'ip' => request()->ip(),
        'secure' => request()->secure(),
        'url' => url('/build/app.css'),
    ]);
});

test('generates secure URLs from trusted proxy headers', function () {
    $this->withServerVariables([
        'REMOTE_ADDR' => '172.20.0.10',
    ]);

    $this->withHeaders([
        'X-Forwarded-For' => '203.0.113.10',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Proto' => 'https',
    ])->get('/_tests/request-url')
        ->assertOk()
        ->assertJson([
            'ip' => '203.0.113.10',
            'secure' => true,
            'url' => 'https://localhost/build/app.css',
        ]);
});

test('ignores forwarded headers from untrusted clients', function () {
    $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.10',
    ]);

    $this->withHeaders([
        'X-Forwarded-For' => '203.0.113.99',
        'X-Forwarded-Host' => 'attacker.example',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Proto' => 'https',
    ])->get('/_tests/request-url')
        ->assertOk()
        ->assertJson([
            'ip' => '198.51.100.10',
            'secure' => false,
            'url' => 'http://localhost/build/app.css',
        ]);
});

test('trusts no forwarded headers when proxies are not configured', function () {
    config()->set('trustedproxy.proxies', []);

    $this->withServerVariables([
        'REMOTE_ADDR' => '172.20.0.10',
    ]);

    $this->withHeaders([
        'X-Forwarded-For' => '203.0.113.99',
        'X-Forwarded-Proto' => 'https',
    ])->get('/_tests/request-url')
        ->assertOk()
        ->assertJson([
            'ip' => '172.20.0.10',
            'secure' => false,
            'url' => 'http://localhost/build/app.css',
        ]);
});

test('keeps direct local requests on HTTP', function () {
    $this->get('/_tests/request-url')
        ->assertOk()
        ->assertJsonPath('secure', false)
        ->assertJsonPath('url', 'http://localhost/build/app.css');
});

test('rejects a wildcard trusted proxy configuration', function () {
    $hadEnvironmentValue = array_key_exists('TRUSTED_PROXIES', $_ENV);
    $hadServerValue = array_key_exists('TRUSTED_PROXIES', $_SERVER);
    $previousEnvironmentValue = $_ENV['TRUSTED_PROXIES'] ?? null;
    $previousServerValue = $_SERVER['TRUSTED_PROXIES'] ?? null;
    $previousProcessValue = getenv('TRUSTED_PROXIES');

    $_ENV['TRUSTED_PROXIES'] = '*';
    $_SERVER['TRUSTED_PROXIES'] = '*';
    putenv('TRUSTED_PROXIES=*');

    try {
        expect(fn () => require config_path('trustedproxy.php'))
            ->toThrow(InvalidArgumentException::class, 'TRUSTED_PROXIES cannot contain wildcard entries.');
    } finally {
        if ($hadEnvironmentValue) {
            $_ENV['TRUSTED_PROXIES'] = $previousEnvironmentValue;
        } else {
            unset($_ENV['TRUSTED_PROXIES']);
        }

        if ($hadServerValue) {
            $_SERVER['TRUSTED_PROXIES'] = $previousServerValue;
        } else {
            unset($_SERVER['TRUSTED_PROXIES']);
        }

        putenv($previousProcessValue === false ? 'TRUSTED_PROXIES' : "TRUSTED_PROXIES={$previousProcessValue}");
    }
});
