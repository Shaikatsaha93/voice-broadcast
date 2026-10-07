<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Audit
{
    public static function log(string $action, Model|string $entity, ?int $entityId = null, ?array $old = null, ?array $new = null): void
    {
        $name = $entity instanceof Model ? class_basename($entity) : $entity;
        $request = app()->bound('request') ? request() : null;

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'entity' => $name,
            'entity_id' => $entityId ?? ($entity instanceof Model ? $entity->getKey() : null),
            'old_values' => self::scrub($old),
            'new_values' => self::scrub($new),
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            'created_at' => now(),
        ]);
    }

    private static function scrub(?array $v): ?array
    {
        if ($v === null) {
            return null;
        }
        unset($v['password'], $v['remember_token']);

        return $v;
    }
}
