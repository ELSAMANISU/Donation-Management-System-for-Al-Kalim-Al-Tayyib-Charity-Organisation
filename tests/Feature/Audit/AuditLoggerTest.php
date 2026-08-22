<?php

namespace Tests\Feature\Audit;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_actor_subject_context_values_and_timestamp_with_casts(): void
    {
        $actor = User::factory()->superAdmin()->create(['name' => 'Audit Administrator']);
        $subject = User::factory()->user()->create();
        $request = Request::create('/admin/users', 'POST', server: [
            'REMOTE_ADDR' => '2001:db8::10',
            'HTTP_USER_AGENT' => 'Audit Browser/1.0',
        ]);

        $auditLog = app(AuditLogger::class)->log(
            action: 'user.disabled',
            actor: $actor,
            subject: $subject,
            oldValues: ['is_active' => true],
            newValues: ['is_active' => false],
            request: $request,
        );

        $this->assertSame('user.disabled', $auditLog->action);
        $this->assertSame($actor->id, $auditLog->actor_id);
        $this->assertSame('Audit Administrator', $auditLog->actor_name);
        $this->assertSame(UserRole::SuperAdmin->value, $auditLog->actor_role);
        $this->assertSame($subject->getMorphClass(), $auditLog->subject_type);
        $this->assertSame($subject->id, $auditLog->subject_id);
        $this->assertSame(['is_active' => true], $auditLog->old_values);
        $this->assertSame(['is_active' => false], $auditLog->new_values);
        $this->assertSame('2001:db8::10', $auditLog->ip_address);
        $this->assertSame('Audit Browser/1.0', $auditLog->user_agent);
        $this->assertInstanceOf(CarbonImmutable::class, $auditLog->created_at);
        $this->assertTrue($auditLog->actor->is($actor));
        $this->assertTrue($auditLog->subject->is($subject));
    }

    public function test_system_entry_can_be_created_without_an_actor(): void
    {
        $auditLog = app(AuditLogger::class)->log('system.started');

        $this->assertNull($auditLog->actor_id);
        $this->assertNull($auditLog->actor_name);
        $this->assertNull($auditLog->actor_role);
        $this->assertNull($auditLog->actor);
    }

    public function test_deleting_actor_preserves_entry_and_snapshots_while_nulling_actor_id(): void
    {
        $actor = User::factory()->admin()->create(['name' => 'Deleted Administrator']);
        $auditLog = app(AuditLogger::class)->log('user.reviewed', actor: $actor);

        $actor->delete();
        $auditLog->refresh();

        $this->assertNull($auditLog->actor_id);
        $this->assertSame('Deleted Administrator', $auditLog->actor_name);
        $this->assertSame(UserRole::Admin->value, $auditLog->actor_role);
        $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
    }

    public function test_nested_sensitive_keys_are_removed_case_insensitively_while_safe_values_remain(): void
    {
        $auditLog = app(AuditLogger::class)->log('user.reviewed', newValues: [
            'display_name' => 'Safe Name',
            'PASSWORD' => 'hidden-password',
            'nested' => [
                'Api-Key' => 'hidden-api-key',
                'currentPassword' => 'hidden-current-password',
                'deeper' => [
                    'ToKeN' => 'hidden-token',
                    'account_identifier' => 'hidden-account-id',
                    'safe_status' => 'approved',
                ],
            ],
            'credentials' => ['username' => 'hidden-credential'],
            'session_identifier' => 'hidden-session',
            'uploaded_file' => 'hidden-file-contents',
        ]);

        $this->assertSame([
            'display_name' => 'Safe Name',
            'nested' => [
                'deeper' => [
                    'safe_status' => 'approved',
                ],
            ],
        ], $auditLog->new_values);

        $storedJson = (string) DB::table('audit_logs')->where('id', $auditLog->id)->value('new_values');

        foreach (['hidden-password', 'hidden-api-key', 'hidden-current-password', 'hidden-token', 'hidden-account-id', 'hidden-credential', 'hidden-session', 'hidden-file-contents'] as $secret) {
            $this->assertStringNotContainsString($secret, $storedJson);
        }
    }

    public function test_overlong_user_agent_is_truncated_safely(): void
    {
        $userAgent = str_repeat('é', 1100);
        $request = Request::create('/', 'GET', server: ['HTTP_USER_AGENT' => $userAgent]);

        $auditLog = app(AuditLogger::class)->log('system.requested', request: $request);

        $this->assertSame(1024, mb_strlen($auditLog->user_agent));
        $this->assertSame(str_repeat('é', 1024), $auditLog->user_agent);
    }

    public function test_empty_or_malformed_actions_are_rejected_without_creating_records(): void
    {
        foreach (['', 'user', 'User.disabled', 'user disabled', 'user..disabled', 'user_disabled', str_repeat('a', 101).'.done'] as $action) {
            try {
                app(AuditLogger::class)->log($action);
                $this->fail("Action [{$action}] should have been rejected.");
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('audit_logs', 0);
            }
        }
    }

    public function test_existing_entry_cannot_be_updated_through_an_eloquent_model(): void
    {
        $auditLog = AuditLog::factory()->create();
        $auditLog->action = 'system.changed';

        try {
            $auditLog->saveQuietly();
            $this->fail('Updating an audit entry should have failed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_logs', [
                'id' => $auditLog->id,
                'action' => 'system.tested',
            ]);
        }
    }

    public function test_existing_entry_cannot_be_deleted_through_an_eloquent_model(): void
    {
        $auditLog = AuditLog::factory()->create();

        try {
            $auditLog->deleteQuietly();
            $this->fail('Deleting an audit entry should have failed.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $auditLog->id]);
        }
    }
}
