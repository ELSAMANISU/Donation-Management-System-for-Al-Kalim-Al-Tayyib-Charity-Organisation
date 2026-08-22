<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'actor_id' => null,
            'actor_name' => null,
            'actor_role' => null,
            'action' => 'system.tested',
            'subject_type' => null,
            'subject_id' => null,
            'old_values' => ['status' => 'before'],
            'new_values' => ['status' => 'after'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'AuditLogFactory/1.0',
            'created_at' => '2026-08-23 00:00:00',
        ];
    }
}
