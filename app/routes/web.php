<?php

use App\Http\Controllers\ConfirmStatusPageSubscriptionController;
use App\Http\Controllers\UnsubscribeStatusPageController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Anonymous, and the only route that renders the daily uptime history, so it is
// throttled against scripted hammering. Livewire's own polling posts to
// /livewire/update rather than here; the history cache is what bounds that cost.
Route::livewire('status/{statusPage:slug}', 'pages::status-pages.public-show')
    ->middleware('throttle:120,1')
    ->name('status-pages.public');

Route::get(
    'status-subscriptions/{subscription}/confirm/{token}',
    ConfirmStatusPageSubscriptionController::class,
)
    ->middleware('signed')
    ->name('status-page-subscriptions.confirm');

Route::get(
    'status-subscriptions/{subscription}/unsubscribe',
    UnsubscribeStatusPageController::class,
)
    ->middleware('signed')
    ->name('status-page-subscriptions.unsubscribe');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('notifications', 'pages::settings.notifications')->name('notifications.edit');

    Route::livewire('websites', 'pages::monitors.index')->name('monitors.index');
    Route::livewire('websites/create', 'pages::monitors.create')->name('monitors.create');
    Route::livewire('websites/{monitor}', 'pages::monitors.show')->name('monitors.show');
    Route::livewire('websites/{monitor}/edit', 'pages::monitors.edit')->name('monitors.edit');

    Route::livewire('status-pages', 'pages::status-pages.index')->name('status-pages.index');
    Route::livewire('status-pages/create', 'pages::status-pages.create')->name('status-pages.create');
    Route::livewire('status-pages/{statusPage}/edit', 'pages::status-pages.edit')->name('status-pages.edit');
});

require __DIR__.'/settings.php';
