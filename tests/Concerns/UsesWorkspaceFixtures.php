<?php
namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Explicit fixture ownership for single-team workflow tests; never changes production defaults. */
trait UsesWorkspaceFixtures
{
    protected int $fixtureWorkspace;
    protected User $fixtureManager;
    private array $fixtureCatalog = [];

    protected function assignWorkspace(User $manager, User ...$members): void
    {
        $organization = DB::table('organizations')->insertGetId(['name' => 'Test organization']);
        $this->fixtureWorkspace = DB::table('workspaces')->insertGetId(['organization_id' => $organization, 'name' => 'Workflow workspace']);
        $this->fixtureManager = $manager;
        foreach ([$manager, ...$members] as $member) $this->assignMember($member);
    }

    protected function assignMember(User $user): User
    {
        $values = ['workspace_id' => $this->fixtureWorkspace];
        if ($user->normalizedRole() === 'cashier') $values += ['assigned_manager_id' => $this->fixtureManager->id, 'assigned_manager_role' => 'manager'];
        $user->forceFill($values)->save();
        DB::table('workspace_memberships')->insert(['workspace_id' => $this->fixtureWorkspace, 'user_id' => $user->id, 'user_role' => $user->normalizedRole(), 'active' => true, 'can_manage_accounts' => $user->normalizedRole() !== 'cashier']);
        return $user;
    }

    protected function insertFixture(string $table, array $values): void
    {
        $rows = array_is_list($values) ? $values : [$values];
        DB::table($table)->insert(array_map(fn ($row) => $row + ['workspace_id' => $this->fixtureWorkspace], $rows));
    }

    protected function catalogFields(string $category): array
    {
        if (isset($this->fixtureCatalog[$category])) return $this->fixtureCatalog[$category];
        $brand = DB::table('brands')->insertGetId(['name' => 'Fixture brand '.$category, 'status' => 'Active', 'workspace_id' => $this->fixtureWorkspace]);
        $sub = DB::table('subcategories')->insertGetId(['name' => 'Fixture type', 'category' => $category, 'status' => 'Active', 'workspace_id' => $this->fixtureWorkspace]);
        return $this->fixtureCatalog[$category] = ['manufacturer' => 'Fixture manufacturer', 'brandId' => $brand, 'subcategoryId' => $sub, 'size' => 1, 'sizeUnit' => 'Piece', 'minStock' => 0, 'status' => 'Active'];
    }
}
