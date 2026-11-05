<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = $this->manageableRoles($request);
        $accounts = \App\Services\WorkspaceAccess::accounts($request->user())->orderByDesc('created_at')->orderByDesc('id')->get()
            ->filter(fn (User $user) => in_array($user->normalizedRole(), $roles, true));
        $emails = $accounts->map(fn (User $user) => Str::lower((string) $user->email))->all();
        $lastOtpLogins = $emails ? DB::table('login_otps')->whereNotNull('used_at')
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->selectRaw('LOWER(email) as address, MAX(used_at) as last_login')
            ->groupByRaw('LOWER(email)')->pluck('last_login', 'address') : collect();
        $accounts = $accounts
            ->map(fn (User $user) => $this->account($user) + ['lastOtpLogin' => $lastOtpLogins[Str::lower((string) $user->email)] ?? null])->values();

        return response()->json(['accounts' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $roles = $this->manageableRoles($request);
        $workspaceId = config('workspaces.enabled') && $request->user()->normalizedRole() !== 'super_admin'
            ? \App\Services\WorkspaceAccess::requireAccountWorkspace($request->user()) : null;
        $this->normalizeInput($request);
        $data = $request->validate($this->rules(true));
        $data['name'] = $data['first_name'].' '.$data['last_name'];
        abort_unless(in_array($data['role'], $roles, true), 403, 'You cannot create an account with this role.');
        $this->validateEmail($data['email']);

        try {
            $account = DB::transaction(function () use ($data, $request, $workspaceId): User {
                $counter = DB::table('account_sequences')->where('name', 'users')->lockForUpdate()->first();
                $id = max((int) $counter->next_id, ((int) DB::table('users')->max('id')) + 1);
                DB::table('account_sequences')->where('name', 'users')->update(['next_id' => $id + 1]);
                $role = $data['role'] === 'super_admin' ? 'superadmin' : $data['role'];
                DB::table('users')->insert([
                    'id' => $id, 'role' => $role, 'name' => $data['name'],
                    'email' => $data['email'], 'password' => Hash::make(Str::random(32)),
                    'first_name'=>$data['first_name'], 'last_name'=>$data['last_name'], 'must_change_password'=>true,
                    'username' => $data['username'] ?? null, 'status' => 'Active',
                    'refundLimit' => $data['refundLimit'] ?? 300, 'schedule' => $data['schedule'] ?? null,
                    'workspace_id'=>$workspaceId, 'created_by_user_id'=>$request->user()->id, 'created_by_user_role'=>$request->user()->normalizedRole(),
                    'assigned_manager_id' => $request->user()->normalizedRole() === 'manager' ? $request->user()->id : null,
                    'assigned_manager_role' => $request->user()->normalizedRole() === 'manager' ? 'manager' : null,
                    'createdBy' => $request->user()->name, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $user = User::where('role', $role)->where('id', $id)->firstOrFail();
                \App\Services\WorkspaceProvisioning::createdAccount($request->user(), $user);
                \App\Services\AccountCredentials::issue($user);
                $this->audit($request, $user, 'Created account', null, $this->account($user));

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        return response()->json(['message' => 'Account created. Setup credentials were emailed to the registered address.', 'account' => $this->account($account)], 201);
    }

    public function update(Request $request, string $role, string $id): JsonResponse
    {
        $roles = $this->manageableRoles($request);
        abort_unless(ctype_digit($id) && (int) $id > 0, 404, 'Account not found.');
        $role = $this->normalizedRole($role);
        abort_unless(in_array($role, $roles, true), 403, 'You cannot manage this account.');
        $this->normalizeInput($request);
        $data = $request->validate($this->rules(false));
        if (isset($data['first_name'], $data['last_name'])) $data['name']=$data['first_name'].' '.$data['last_name'];
        elseif (isset($data['first_name']) || isset($data['last_name'])) throw ValidationException::withMessages(['first_name'=>'Provide both first and last name.']);
        if (isset($data['role']) && $data['role'] !== $role) {
            throw ValidationException::withMessages(['role' => 'The role of an existing account cannot be changed. Create a separate account for the new role.']);
        }

        try {
            $account = DB::transaction(function () use ($request, $data, $role, $id): User {
                // Serializing super administrator changes also protects the last active account.
                if ($role === 'super_admin') {
                    DB::table('account_sequences')->where('name', 'users')->lockForUpdate()->first();
                    User::query()->whereIn('role', ['superadmin', 'super_admin', 'Super Admin', 'super-admin'])->orderBy('id')->lockForUpdate()->get();
                }
                $aliases = $role === 'super_admin' ? ['superadmin', 'super_admin', 'Super Admin', 'super-admin'] : [$role, ucfirst($role)];
                $user = \App\Services\WorkspaceAccess::accounts($request->user())->where('id', $id)->whereIn('role', $aliases)->lockForUpdate()->firstOrFail();
                if (isset($data['email'])) {
                    $this->validateEmail($data['email'], $user);
                }
                $before = $this->account($user);
                $deactivating = ($data['status'] ?? $user->status) === 'Inactive';
                if ($deactivating && $this->hasOngoingTransaction($user)) {
                    throw ValidationException::withMessages(['status' => 'This account cannot be set to inactive because it has an ongoing transaction.']);
                }
                if ($deactivating && $user->getAuthIdentifier() === $request->user()->getAuthIdentifier()) {
                    throw ValidationException::withMessages(['status' => 'You cannot deactivate your own account.']);
                }
                if ($deactivating && $role === 'super_admin' && User::query()->get()->filter(fn (User $account) => $account->isActive() && $account->normalizedRole() === 'super_admin')->count() <= 1) {
                    throw ValidationException::withMessages(['status' => 'At least one active Super Admin account is required.']);
                }
                // Historical imports identify actors by name. Do not allow a rename to bypass
                // their ongoing-transaction check before those operations are completed.
                foreach (['name', 'email', 'username'] as $field) {
                    if (array_key_exists($field, $data) && $data[$field] !== $user->{$field} && $this->hasOngoingTransaction($user)) {
                        throw ValidationException::withMessages([$field => 'Account details cannot be changed while this account has an ongoing transaction.']);
                    }
                }
                unset($data['role']);
                if (empty($data['password'])) {
                    unset($data['password']);
                }
                if ($deactivating && $user->isActive()) {
                    $user->forceFill(['credential_version'=>(int)$user->credential_version+1, 'remember_token'=>\Illuminate\Support\Str::random(60)]);
                    DB::table('login_otps')->where('email',$user->email)->whereNull('used_at')->update(['used_at'=>now()]);
                }
                $user->fill($data)->save();
                $this->audit($request, $user, 'Updated account', $before, $this->account($user));

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }

        return response()->json(['message' => 'Account updated.', 'account' => $this->account($account)]);
    }

    public function resendSetup(Request $request, string $role, string $id): JsonResponse
    {
        $roles=$this->manageableRoles($request);
        $query = \App\Services\WorkspaceAccess::accounts($request->user());
        $user=$query->where('id',$id)->get()->first(fn($u)=>$u->normalizedRole()===$this->normalizedRole($role));
        abort_unless($user && in_array($user->normalizedRole(),$roles,true),404);
        \App\Services\AccountCredentials::issue($user);
        $this->audit($request,$user,'Reissued setup credentials',null,$this->account($user));
        return response()->json(['message'=>'Setup credentials sent to the registered email.']);
    }

    public function sendReset(Request $request, string $role, string $id): JsonResponse
    {
        $roles=$this->manageableRoles($request);
        $query = \App\Services\WorkspaceAccess::accounts($request->user());
        $user=$query->where('id',$id)->get()->first(fn($u)=>$u->normalizedRole()===$this->normalizedRole($role));
        abort_unless($user && in_array($user->normalizedRole(),$roles,true),404);
        $request->merge(['email'=>$user->email]);
        $response=app(PasswordSetupController::class)->requestCode($request);
        $this->audit($request,$user,'Requested password reset',null,$this->account($user));
        return $response;
    }

    private function manageableRoles(Request $request): array
    {
        abort_unless($request->user() && $request->user()->isActive(), 401);

        return match ($request->user()->normalizedRole()) {
            'super_admin' => ['admin'],
            'admin' => ['cashier', 'manager'],
            'manager' => ['cashier'],
            default => abort(403, 'You cannot manage accounts.'),
        };
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'required', 'string', 'max:60'],
            'last_name' => [$required, 'required', 'string', 'max:60'],
            'email' => [$required, 'required', 'email', 'max:255'],
            'role' => [$required, Rule::in(['cashier', 'manager', 'admin', 'super_admin'])],
            'password' => ['prohibited'],
            'username' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'status' => ['sometimes', Rule::in($creating ? ['Active'] : ['Active', 'Inactive'])],
            'refundLimit' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'schedule' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    private function normalizeInput(Request $request): void
    {
        foreach (['first_name', 'last_name', 'name', 'email', 'username', 'schedule'] as $field) {
            if ($request->has($field) && is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        if ($request->has('email') && is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
        if ($request->has('role') && is_string($request->input('role'))) {
            $request->merge(['role' => $this->normalizedRole($request->input('role'))]);
        }
        if (! $request->has('username') && $request->has('employeeId')) {
            $request->merge(['username' => $request->input('employeeId')]);
        }
    }

    private function normalizedRole(string $role): string
    {
        return (new User(['role' => $role]))->normalizedRole();
    }

    private function validateEmail(string $email, ?User $except = null): void
    {
        $query = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email]);
        if ($except) {
            $query->where(fn ($query) => $query->where('role', '<>', $except->role)->orWhere('id', '<>', $except->id));
        }
        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => 'An account with this email already exists.']);
        }
    }

    private function hasOngoingTransaction(User $user): bool
    {
        $transactions = DB::table('transactions')->whereIn('status', ['Pending Payment', 'Pending', 'Processing']);
        $this->ownedBy($transactions, 'transactions', $user, 'cashierId', 'cashierRole', 'cashier', 'cashierEmail');
        if ($transactions->exists()) {
            return true;
        }

        $requests = DB::table('item_requests')->whereIn('status', ['Pending', 'Pending Approval', 'Proceed to Purchase']);
        $this->ownedBy($requests, 'item_requests', $user, 'requestedById', 'requestedByRole', 'requestedBy');
        if ($requests->exists()) {
            return true;
        }

        $adjustments = DB::table('adjustments')->where('status', 'Pending Admin Approval');
        $this->ownedBy($adjustments, 'adjustments', $user, 'requestedById', 'requestedByRole', 'remarks', null, 'Submitted by ');
        if ($adjustments->exists()) {
            return true;
        }

        $orders = DB::table('purchase_orders')->where('workspace_id', $user->workspace_id ?: -1)
            ->whereNotIn('status', ['Closed', 'Cancelled', 'Completed', 'Fully Received', 'Received with Discrepancy']);
        $orders->where(function (Builder $query) use ($user) {
            if (Schema::hasColumn('purchase_orders', 'createdById')) {
                $query->where(fn (Builder $owner) => $owner->where('createdById', $user->id)->where('createdByRole', $user->role));
            } else {
                $query->whereRaw('1 = 0');
            }
            $query->orWhereIn('itemRequestId', function (Builder $requests) use ($user) {
                $requests->select('id')->from('item_requests');
                $this->ownedBy($requests, 'item_requests', $user, 'requestedById', 'requestedByRole', 'requestedBy');
            });
        });

        return $orders->exists();
    }

    private function ownedBy(Builder $query, string $table, User $user, string $idColumn, string $roleColumn, string $legacyColumn, ?string $emailColumn = null, string $prefix = ''): void
    {
        $hasIdentity = Schema::hasColumn($table, $idColumn);
        $hasEmail = $emailColumn && Schema::hasColumn($table, $emailColumn);
        $identifiers = array_map(fn (string $value) => $prefix.$value, array_filter([$user->name, $user->username, $user->email]));
        $query->where(function (Builder $owner) use ($user, $idColumn, $roleColumn, $legacyColumn, $emailColumn, $hasIdentity, $hasEmail, $identifiers) {
            if ($hasIdentity) {
                $owner->where(fn (Builder $identity) => $identity->where($idColumn, $user->id)->where($roleColumn, $user->role));
            } else {
                $owner->whereRaw('1 = 0');
            }
            $owner->orWhere(function (Builder $legacy) use ($user, $idColumn, $legacyColumn, $emailColumn, $hasIdentity, $hasEmail, $identifiers) {
                $legacy->where('workspace_id', $user->workspace_id ?: -1);
                if ($hasIdentity) {
                    $legacy->whereNull($idColumn);
                }
                $legacy->where(function (Builder $reference) use ($user, $legacyColumn, $emailColumn, $hasEmail, $identifiers) {
                    if ($hasEmail) {
                        $reference->where($emailColumn, $user->email)->orWhere(fn (Builder $byName) => $byName->whereNull($emailColumn)->whereIn($legacyColumn, $identifiers));
                    } else {
                        $reference->whereIn($legacyColumn, $identifiers);
                    }
                });
            });
        });
    }

    private function account(User $user): array
    {
        return [
            'id' => (int) $user->id, 'role' => $user->normalizedRole() === 'super_admin' ? 'superadmin' : $user->normalizedRole(),
            'first_name'=>$user->first_name, 'last_name'=>$user->last_name, 'must_change_password'=>(bool)$user->must_change_password,
            'name' => $user->name, 'email' => $user->email, 'username' => $user->username,
            'status' => $user->isActive() ? 'Active' : 'Inactive', 'refundLimit' => $user->refundLimit === null ? null : (float) $user->refundLimit,
            'schedule' => $user->schedule, 'createdBy' => $user->createdBy,
            'dateCreated' => $user->created_at?->toDateString(),
        ];
    }

    private function audit(Request $request, User $user, string $action, ?array $before, array $after): void
    {
        $statusChanged = $before && $before['status'] !== $after['status'];
        \App\Services\WorkflowNotifications::send([$user, $request->user()], $request->user(), $statusChanged ? 'Account status changed' : $action,
            $user->name.': '.($statusChanged ? 'account is now '.$after['status'] : strtolower($action)).' by '.$request->user()->name.'.', 'account', null, $user->workspace_id ? (int) $user->workspace_id : null);
        DB::table('audit_logs')->insert([
            'ts' => now(), 'user' => Str::limit($request->user()->name, 80, ''), 'action' => $action,
            'record' => 'Account '.$user->role.':'.$user->id,
            'beforeValue' => $before === null ? null : json_encode($before), 'afterValue' => json_encode($after),
            'workspace_id' => $user->workspace_id,
        ]);
    }
}
