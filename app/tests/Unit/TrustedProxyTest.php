<?php

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Route::get('/_tests/request-url', fn () => [
        'secure' => request()->secure(),
        'url' => url('/build/app.css'),
    ]);
});

test('generates secure URLs from trusted proxy headers', function () {
    $this->withHeaders([
        'Host' => 'app:8080',
        'X-Forwarded-Host' => 'monitor.example.com',
        'X-Forwarded-Port' => '443',
        'X-Forwarded-Proto' => 'https',
    ])->get('/_tests/request-url')
        ->assertOk()
        ->assertJson([
            'secure' => true,
            'url' => 'https://monitor.example.com/build/app.css',
        ]);
});

test('keeps direct local requests on HTTP', function () {
    $this->get('/_tests/request-url')
        ->assertOk()
        ->assertJson([
            'secure' => false,
            'url' => 'http://localhost/build/app.css',
        ]);
});
