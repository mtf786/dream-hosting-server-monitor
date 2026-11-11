<?php

namespace Tests\Feature;

use App\Models\MetricBatch;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerCredential;
use App\Models\ServerEnrollmentToken;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentMonitoringApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_windows_agent_enrollment_consumes_the_one_time_token_and_stores_os_details(): void
    {
        $server = $this->createPendingWindowsServer();
        $enrollmentToken = bin2hex(random_bytes(32));
        $tokenRecord = $this->createEnrollmentToken($server, $enrollmentToken);

        $response = $this->postJson(route('api.v1.agent.enroll'), [
            'enrollment_token' => $enrollmentToken,
            'hostname' => 'DREAMHOSTING-WEB',
            'platform' => 'windows',
            'os_version' => 'Windows Server 2022 (10.0.20348)',
            'agent_version' => '0.2.0',
        ]);

        $response->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('server_id', $server->uuid)
            ->assertJsonPath('sample_interval_seconds', 60)
            ->assertJsonMissingPath('enrollment_token');

        $credential = $response->json('credential');

        $this->assertIsString($credential);
        $this->assertSame(64, strlen($credential));
        $this->assertNotSame($enrollmentToken, $credential);
        $this->assertSame(hash('sha256', $credential), ServerCredential::query()->sole()->token_hash);
        $this->assertNotNull($tokenRecord->fresh()->consumed_at);
        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'status' => 'online',
            'hostname' => 'DREAMHOSTING-WEB',
            'os_version' => 'Windows Server 2022 (10.0.20348)',
            'agent_version' => '0.2.0',
        ]);

        $this->postJson(route('api.v1.agent.enroll'), [
            'enrollment_token' => $enrollmentToken,
            'hostname' => 'DREAMHOSTING-WEB',
            'platform' => 'windows',
            'agent_version' => '0.2.0',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('server_credentials', 1);
    }

    public function test_expired_enrollment_token_does_not_enroll_or_create_a_credential(): void
    {
        $server = $this->createPendingWindowsServer();
        $enrollmentToken = bin2hex(random_bytes(32));
        $this->createEnrollmentToken($server, $enrollmentToken, now()->subSecond());

        $this->postJson(route('api.v1.agent.enroll'), [
            'enrollment_token' => $enrollmentToken,
            'hostname' => 'DREAMHOSTING-WEB',
            'platform' => 'windows',
            'agent_version' => '0.2.0',
        ])->assertUnauthorized();

        $this->assertSame('pending', $server->fresh()->status);
        $this->assertDatabaseCount('server_credentials', 0);
    }

    public function test_heartbeat_authentication_updates_status_and_os_version(): void
    {
        $server = $this->createPendingWindowsServer();
        $credential = $this->createCredential($server);

        $this->withToken($credential)
            ->postJson(route('api.v1.agent.heartbeat'), [
                'agent_version' => '0.2.0',
                'os_version' => 'Windows Server 2022 (10.0.20348)',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'online')
            ->assertJsonPath('server_id', $server->uuid);

        $this->assertDatabaseHas('servers', [
            'id' => $server->id,
            'status' => 'online',
            'agent_version' => '0.2.0',
            'os_version' => 'Windows Server 2022 (10.0.20348)',
        ]);
    }

    public function test_metric_batch_can_be_retried_without_duplicate_samples(): void
    {
        $server = $this->createPendingWindowsServer();
        $credential = $this->createCredential($server);
        $batchUuid = (string) Str::uuid();
        $payload = [
            'batch_uuid' => $batchUuid,
            'agent_version' => '0.2.0',
            'samples' => [[
                'collected_at' => now()->toISOString(),
                'cpu_percent' => 12.5,
                'memory_total_bytes' => 16000000000,
                'memory_used_bytes' => 8000000000,
                'disks' => [[
                    'filesystem' => 'C:',
                    'total_bytes' => 512000000000,
                    'used_bytes' => 256000000000,
                ]],
            ]],
        ];

        $this->withToken($credential)
            ->postJson(route('api.v1.agent.metrics'), $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'stored');

        $this->withToken($credential)
            ->postJson(route('api.v1.agent.metrics'), $payload)
            ->assertOk()
            ->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, MetricBatch::query()->count());
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertDatabaseHas('servers', ['id' => $server->id, 'status' => 'online']);
    }

    private function createPendingWindowsServer(): Server
    {
        $owner = User::factory()->create();

        return Server::create([
            'user_id' => $owner->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Windows Test Node',
            'platform' => 'windows',
            'status' => 'pending',
        ]);
    }

    private function createEnrollmentToken(Server $server, string $plainToken, ?Carbon $expiresAt = null): ServerEnrollmentToken
    {
        return ServerEnrollmentToken::create([
            'server_id' => $server->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt ?? now()->addMinutes(30),
        ]);
    }

    private function createCredential(Server $server): string
    {
        $plainCredential = bin2hex(random_bytes(32));
        ServerCredential::create([
            'server_id' => $server->id,
            'key_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $plainCredential),
        ]);

        return $plainCredential;
    }
}
