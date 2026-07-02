<?php

namespace App\Models\Concerns;

use App\Models\ChangeLog;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

trait RecordsChanges
{
    protected static function bootRecordsChanges(): void
    {
        static::created(fn ($model) => $model->writeChangeLog('created', null, $model->getAttributes()));
        static::updated(fn ($model) => $model->writeChangeLog(
            'updated',
            array_intersect_key($model->getOriginal(), $model->getChanges()),
            $model->getChanges(),
        ));
        static::deleted(fn ($model) => $model->writeChangeLog('deleted', $model->getOriginal(), null));
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(fn ($model) => $model->writeChangeLog('restored', null, $model->getAttributes()));
        }
    }

    private function writeChangeLog(string $action, ?array $before, ?array $after): void
    {
        if (! Schema::hasTable('change_logs')) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();
        $telegramUser = $request?->attributes->get('telegramUser');
        $userId = Auth::id()
            ?: $request?->attributes->get('familyUser')?->id
            ?: $telegramUser?->user_id;

        ChangeLog::query()->create([
            'tree_id' => $this->tree_id ?? null,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => static::class,
            'subject_id' => $this->getKey(),
            'before' => $this->withoutAuditNoise($before),
            'after' => $this->withoutAuditNoise($after),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    private function withoutAuditNoise(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return array_diff_key($data, array_flip([
            'password',
            'remember_token',
            'created_at',
            'updated_at',
        ]));
    }
}
