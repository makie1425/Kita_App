<?php

namespace App\Console\Commands;

use App\Services\WorkspaceTables;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WorkspaceOwnershipAudit extends Command
{
    protected $signature = 'workspaces:audit-ownership {--sample=10 : Maximum IDs shown per finding (0-50)}';

    protected $description = 'Report unresolved workspace and account ownership without changing data';

    public function handle(): int
    {
        $sample = filter_var($this->option('sample'), FILTER_VALIDATE_INT);
        if ($sample === false || $sample < 0 || $sample > 50) {
            $this->error('The --sample value must be an integer from 0 to 50.');
            return self::FAILURE;
        }

        $this->info('Workspace ownership audit (read only)');
        $this->line('Unassigned workspace rows:');
        foreach (array_merge(['users', 'notifications'], WorkspaceTables::BUSINESS) as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
                $this->warn($table.': workspace_id unavailable; cannot audit.');
                continue;
            }
            $this->report($table, DB::table($table)->whereNull('workspace_id'), $this->identityColumns($table), $sample);
        }

        $managers = DB::table('users')->whereRaw('LOWER(role) = ?', ['manager'])
            ->whereNotNull('workspace_id')->select('workspace_id')
            ->groupBy('workspace_id')->havingRaw('COUNT(*) > 1');
        $this->report('Managers sharing workspace', DB::table('users')->whereRaw('LOWER(role) = ?', ['manager'])
            ->whereIn('workspace_id', $managers)->orderBy('workspace_id'), ['id', 'workspace_id'], $sample);

        $cashiers = DB::table('users')->whereRaw('LOWER(role) = ?', ['cashier']);
        $this->report('Cashiers missing manager assignment', (clone $cashiers)
            ->where(fn (Builder $query) => $query->whereNull('assigned_manager_id')->orWhere('assigned_manager_role', '<>', 'manager')->orWhereNull('assigned_manager_role')),
            ['id', 'email'], $sample);
        $this->report('Cashiers with invalid manager assignment', (clone $cashiers)
            ->whereNotNull('assigned_manager_id')->where('assigned_manager_role', 'manager')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('users as manager')
                ->whereColumn('manager.id', 'users.assigned_manager_id')->whereRaw('LOWER(manager.role) = ?', ['manager'])),
            ['id', 'email'], $sample);
        $this->report('Cashiers with conflicting creator/manager', (clone $cashiers)
            ->where('created_by_user_role', 'manager')->whereNotNull('created_by_user_id')
            ->whereNotNull('assigned_manager_id')->whereColumn('created_by_user_id', '<>', 'assigned_manager_id'),
            ['id', 'email'], $sample);
        $this->report('Cashiers with invalid manager creator', (clone $cashiers)
            ->where('created_by_user_role', 'manager')->whereNotNull('created_by_user_id')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('users as creator')
                ->whereColumn('creator.id', 'users.created_by_user_id')->whereRaw('LOWER(creator.role) = ?', ['manager'])),
            ['id', 'email'], $sample);
        $this->report('Cashiers missing creator link', (clone $cashiers)
            ->where(fn (Builder $query) => $query->whereNull('created_by_user_id')->orWhereNull('created_by_user_role')),
            ['id', 'email'], $sample);
        $this->report('Cashiers with invalid creator link', (clone $cashiers)
            ->whereNotNull('created_by_user_id')->whereNotNull('created_by_user_role')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('users as creator')
                ->whereColumn('creator.id', 'users.created_by_user_id')
                ->whereRaw("LOWER(REPLACE(creator.role, '_', '')) = LOWER(REPLACE(users.created_by_user_role, '_', ''))")),
            ['id', 'email'], $sample);

        $this->report('Cashiers outside assigned manager workspace', (clone $cashiers)
            ->whereNotNull('assigned_manager_id')->where('assigned_manager_role', 'manager')
            ->whereExists(fn (Builder $query) => $query->selectRaw('1')->from('users as manager')
                ->whereColumn('manager.id', 'users.assigned_manager_id')->whereRaw('LOWER(manager.role) = ?', ['manager'])
                ->where(fn (Builder $mismatch) => $mismatch->whereNull('manager.workspace_id')
                    ->orWhereColumn('manager.workspace_id', '<>', 'users.workspace_id'))),
            ['id', 'email'], $sample);
        $this->report('Managers with inconsistent creator workspace', DB::table('users as child')
            ->join('users as creator', fn ($join) => $join->on('creator.id', '=', 'child.created_by_user_id')
                ->on('creator.role', '=', 'child.created_by_user_role'))
            ->join('workspaces as child_scope', 'child_scope.id', '=', 'child.workspace_id')
            ->join('workspaces as parent_scope', 'parent_scope.id', '=', 'creator.workspace_id')
            ->whereRaw('LOWER(child.role) = ?', ['manager'])
            ->whereIn('child.created_by_user_role', ['admin', 'superadmin', 'super_admin'])
            ->where(fn (Builder $query) => $query->whereColumn('child_scope.organization_id', '<>', 'parent_scope.organization_id')
                ->orWhereColumn('child_scope.id', '=', 'parent_scope.id')),
            ['child.id', 'child.email'], $sample);
        $this->report('Manager child workspaces without creator grant', DB::table('users as child')
            ->whereRaw('LOWER(child.role) = ?', ['manager'])
            ->whereNotNull('child.workspace_id')->whereNotNull('child.created_by_user_id')
            ->whereIn('child.created_by_user_role', ['admin', 'superadmin', 'super_admin'])
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('workspace_memberships as membership')
                ->whereColumn('membership.workspace_id', 'child.workspace_id')
                ->whereColumn('membership.user_id', 'child.created_by_user_id')
                ->whereColumn('membership.user_role', 'child.created_by_user_role')
                ->where('membership.active', true)->where('membership.can_manage_accounts', true)),
            ['child.id', 'child.email'], $sample);
        $this->report('Accounts without matching workspace membership', DB::table('users')
            ->whereNotNull('workspace_id')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('workspace_memberships as membership')
                ->whereColumn('membership.workspace_id', 'users.workspace_id')
                ->whereColumn('membership.user_id', 'users.id')
                ->whereRaw("LOWER(REPLACE(membership.user_role, '_', '')) = LOWER(REPLACE(users.role, '_', ''))")
                ->where('membership.active', true)),
            ['id', 'email'], $sample);

        $this->warn('No records changed. Review each finding before assigning ownership.');
        return self::SUCCESS;
    }

    private function identityColumns(string $table): array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['primary']) {
                return $index['columns'];
            }
        }
        return [];
    }

    private function report(string $label, Builder $query, array $columns, int $sample): void
    {
        $count = (clone $query)->reorder()->count();
        $ids = $sample && $columns ? (clone $query)->reorder()->select($columns)->limit($sample)->get()
            ->map(fn ($row) => implode('/', array_values((array) $row)))->all() : [];
        $this->line($label.': '.$count.($ids ? ' | sample IDs: '.implode(', ', $ids) : ''));
    }
}
