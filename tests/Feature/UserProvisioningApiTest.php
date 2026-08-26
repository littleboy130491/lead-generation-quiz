<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProvisioningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AdminRoleSeeder::class);
        config()->set('quiz_api.token', 'test-api-token');
    }

    public function test_user_provisioning_endpoint_requires_a_bearer_token(): void
    {
        $this->postJson('/api/v1/users', $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_authorized_client_can_create_a_user_with_allowlisted_roles(): void
    {
        $this->withToken('test-api-token')
            ->postJson('/api/v1/users', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.email', 'ops-admin@example.test')
            ->assertJsonPath('data.roles', ['admin'])
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'ops-admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(password_verify('replace-with-a-strong-password', $user->password));
    }

    public function test_user_provisioning_rejects_duplicate_email_and_unknown_roles(): void
    {
        User::factory()->create(['email' => 'ops-admin@example.test']);

        $this->withToken('test-api-token')
            ->postJson('/api/v1/users', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->withToken('test-api-token')
            ->postJson('/api/v1/users', $this->payload([
                'email' => 'other@example.test',
                'roles' => ['not_a_real_role'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Ops Admin',
            'email' => 'ops-admin@example.test',
            'password' => 'replace-with-a-strong-password',
            'roles' => ['admin'],
            'email_verified' => true,
        ], $overrides);
    }
}
