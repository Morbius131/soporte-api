<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_es_publico(): void
    {
        $this->getJson('/api/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_registro_devuelve_usuario_y_token(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secreto1234',
        ])
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role'], 'token'])
            ->assertJsonPath('user.role', 'customer');

        $this->assertDatabaseHas('users', ['email' => 'ana@example.com']);
    }

    public function test_el_registro_ignora_el_rol_enviado_por_el_cliente(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'secreto1234', 'role' => 'admin',
        ])->assertCreated()->assertJsonPath('user.role', 'customer');
    }

    public function test_registro_rechaza_email_duplicado(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Dup', 'email' => 'dup@example.com', 'password' => 'secreto1234',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    }

    public function test_registro_rechaza_contrasena_corta(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Corta', 'email' => 'corta@example.com', 'password' => '123',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_login_y_acceso_con_token_bearer(): void
    {
        User::factory()->create(['email' => 'a@example.com']); // contraseña: "password"

        $token = $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'a@example.com');
    }

    public function test_login_con_contrasena_incorrecta_devuelve_401(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'incorrecta'])
            ->assertUnauthorized();
    }

    public function test_login_con_email_inexistente_devuelve_401(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'nadie@example.com', 'password' => 'password'])
            ->assertUnauthorized();
    }

    public function test_me_sin_token_devuelve_401(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_logout_revoca_el_token(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        $token = $this->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password'])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        app('auth')->forgetGuards(); // fuerza a resolver de nuevo el usuario desde el token
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
