<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

it('disables public registration by default', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse()
        ->and(Route::has('register'))->toBeFalse()
        ->and(Route::has('register.store'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register')->assertNotFound();

    $this->get('/')
        ->assertSuccessful()
        ->assertDontSee('Create an account')
        ->assertDontSee('Get started');

    $this->get('/login')
        ->assertSuccessful()
        ->assertDontSee('Sign up');
});
