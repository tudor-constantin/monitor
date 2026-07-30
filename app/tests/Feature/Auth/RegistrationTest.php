<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        putenv('REGISTRATION_ENABLED=true');
        $_ENV['REGISTRATION_ENABLED'] = 'true';
        $_SERVER['REGISTRATION_ENABLED'] = 'true';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('REGISTRATION_ENABLED');
        unset($_ENV['REGISTRATION_ENABLED'], $_SERVER['REGISTRATION_ENABLED']);
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post(route('register.store'), [
            'name' => 'John Doe',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }
}
