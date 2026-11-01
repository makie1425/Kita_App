<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

/** Ownership is resolved from the authenticated membership, never submitted input. */
class WorkspaceWrite
{
    public static function requireId(): ?int
    {
        if (!config('workspaces.enabled')) return null;
        $id = WorkspaceRead::id();
        abort_unless($id, 403, 'An active workspace assignment is required.');
        return $id;
    }

    public static function owned(array $values): array
    {
        unset($values['workspace_id']);
        if (config('workspaces.enabled')) $values['workspace_id'] = self::requireId();
        return $values;
    }

    public static function scopeValidation(Builder $query): void
    {
        if (config('workspaces.enabled')) $query->where('workspace_id', self::requireId());
    }
}
