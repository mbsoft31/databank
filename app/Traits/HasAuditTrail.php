<?php
// app/Traits/HasAuditTrail.php
namespace App\Traits;

use App\Models\AuditLog;

trait HasAuditTrail
{
    public static function bootHasAuditTrail(): void
    {
        static::created(function ($model) {
            $model->auditAction('created', [], $model->getAttributes());
        });

        static::updated(function ($model) {
            $model->auditAction('updated', $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function ($model) {
            $model->auditAction('deleted', $model->getAttributes(), []);
        });
    }

    protected function auditAction($action, $oldValues, $newValues): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
