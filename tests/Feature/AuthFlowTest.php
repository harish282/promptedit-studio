<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_generate_page_redirects_guests_to_login(): void
    {
        $this->get('/generate')->assertRedirect('/login');
    }

    public function test_register_creates_user_with_starting_balance(): void
    {
        $response = $this->post('/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'correct horse battery staple',
            'password_confirmation' => 'correct horse battery staple',
        ]);

        $response->assertRedirect('/generate');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'is_admin' => false,
            'balance_nano' => 10_000_000_000,
        ]);
    }

    public function test_login_with_valid_credentials_redirects_to_generate(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('correct horse battery staple'),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/generate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_wrong_password_shows_email_error(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => bcrypt('correct horse battery staple'),
        ]);

        $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'not the password',
        ])->assertRedirect('/login')->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
