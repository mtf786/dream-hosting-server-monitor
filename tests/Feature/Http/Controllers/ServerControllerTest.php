<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Server;
use App\Models\ServerEnrollmentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServerControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_registration_redirects_with_success_and_stores_a_hashed_expiring_token(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->from(route('servers.create'))
            ->post(route('servers.store'), [
                'name' => 'Production Web',
                'platform' => 'windows',
            ]);

        $server = Server::query()->where('user_id', $admin->id)->sole();
        $sessionTokenKey = 'server_enrollment_token_'.$server->uuid;
        $plainToken = Crypt::decryptString(session($sessionTokenKey));

        $response->assertRedirectToRoute('servers.enrollment', ['server' => $server->uuid]);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertSessionHas('status', 'Server registered. Copy the one-time enrollment token from this page within 30 minutes.');
        $response->assertSessionHas($sessionTokenKey);
        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'user_id' => $admin->id,
            'name' => 'Production Web',
            'platform' => 'windows',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('server_enrollment_tokens', [
            'server_id' => $server->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => '2026-11-11 10:30:00',
            'consumed_at' => null,
            'revoked_at' => null,
        ]);
    }

    public function test_invalid_registration_redirects_back_with_errors_and_creates_nothing(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->from(route('servers.create'))
            ->followingRedirects()
            ->post(route('servers.store'), [
                'name' => '',
                'platform' => 'macos',
            ]);

        $response->assertOk();
        $response->assertSee('Please correct the following errors:');
        $response->assertSee('The name field is required.');
        $response->assertSee('The selected platform is invalid.');
        $this->assertDatabaseCount('servers', 0);
        $this->assertDatabaseCount('server_enrollment_tokens', 0);
    }

    public function test_non_admin_cannot_register_a_server(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('servers.store'), [
            'name' => 'Blocked Server',
            'platform' => 'linux',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('servers', 0);
        $this->assertDatabaseCount('server_enrollment_tokens', 0);
    }

    public function test_enrollment_page_reveals_the_encrypted_session_token_once_without_caching(): void
    {
        $admin = $this->createAdmin();
        $server = $this->createServer($admin);
        $plainToken = str_repeat('a', 64);

        $response = $this->actingAs($admin)
            ->withSession([
                'server_enrollment_token_'.$server->uuid => Crypt::encryptString($plainToken),
            ])
            ->get(route('servers.enrollment', $server));

        $response->assertOk();
        $response->assertSee($plainToken);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertSessionMissing('server_enrollment_token_'.$server->uuid);
    }

    public function test_owner_admin_can_reissue_a_pending_servers_token_once_and_revoke_previous_tokens(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00', 'UTC'));
        $admin = $this->createAdmin();
        $server = $this->createServer($admin);
        $oldPlainToken = bin2hex(random_bytes(32));
        $oldToken = ServerEnrollmentToken::create([
            'server_id' => $server->id,
            'created_by' => $admin->id,
            'token_hash' => hash('sha256', $oldPlainToken),
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($admin)
            ->post(route('servers.enrollment.reissue', $server));

        $sessionTokenKey = 'server_enrollment_token_'.$server->uuid;
        $newPlainToken = Crypt::decryptString(session($sessionTokenKey));

        $response->assertRedirectToRoute('servers.enrollment', ['server' => $server->uuid]);
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertDontSee($newPlainToken);
        $response->assertSessionHas($sessionTokenKey);
        $this->assertNotSame($newPlainToken, session($sessionTokenKey));
        $this->assertNotSame($oldPlainToken, $newPlainToken);
        $this->assertDatabaseHas('server_enrollment_tokens', [
            'id' => $oldToken->id,
            'revoked_at' => '2026-11-11 10:00:00',
        ]);

        $newTokenRecord = ServerEnrollmentToken::query()
            ->where('token_hash', hash('sha256', $newPlainToken))
            ->sole();
        $this->assertSame($admin->id, $newTokenRecord->created_by);
        $this->assertSame('2026-11-11 10:30:00', $newTokenRecord->expires_at->toDateTimeString());

        $enrollmentResponse = $this->get(route('servers.enrollment', $server));
        $enrollmentResponse->assertOk()
            ->assertSee($newPlainToken)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionMissing($sessionTokenKey);
    }

    public function test_admin_cannot_reissue_a_token_for_another_admins_server(): void
    {
        $owner = $this->createAdmin();
        $otherAdmin = $this->createAdmin();
        $server = $this->createServer($owner);

        $this->actingAs($otherAdmin)
            ->post(route('servers.enrollment.reissue', $server))
            ->assertNotFound();

        $this->assertDatabaseCount('server_enrollment_tokens', 0);
    }

    public function test_non_admin_cannot_reissue_an_enrollment_token(): void
    {
        $user = User::factory()->create();
        $owner = $this->createAdmin();
        $server = $this->createServer($owner);

        $this->actingAs($user)
            ->post(route('servers.enrollment.reissue', $server))
            ->assertForbidden();

        $this->assertDatabaseCount('server_enrollment_tokens', 0);
    }

    public function test_an_online_server_cannot_receive_a_replacement_enrollment_token(): void
    {
        $admin = $this->createAdmin();
        $server = $this->createServer($admin);
        $server->forceFill(['status' => 'online'])->save();

        $this->actingAs($admin)
            ->post(route('servers.enrollment.reissue', $server))
            ->assertStatus(409);

        $this->assertDatabaseCount('server_enrollment_tokens', 0);
    }

    public function test_pending_server_detail_exposes_reissue_action_but_online_server_does_not(): void
    {
        $admin = $this->createAdmin();
        $server = $this->createServer($admin);

        $this->actingAs($admin)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertSee('Reissue enrollment token');

        $server->forceFill(['status' => 'online'])->save();

        $this->actingAs($admin)
            ->get(route('servers.show', $server))
            ->assertOk()
            ->assertDontSee('Reissue enrollment token');
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin->refresh();
    }

    private function createServer(User $owner): Server
    {
        return Server::create([
            'user_id' => $owner->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Server for enrollment test',
            'platform' => 'linux',
            'status' => 'pending',
        ]);
    }
}
