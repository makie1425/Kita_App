<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SeedInitialSuperAdmin extends Command
{
    protected $signature = 'accounts:seed-initial-superadmin';

    protected $description = 'Provision the first Super Admin from private environment values';

    public function handle(): int
    {
        $data = [
            'email' => strtolower(trim((string) env('INITIAL_SUPERADMIN_EMAIL'))),
            'password' => env('INITIAL_SUPERADMIN_PASSWORD'),
        ];

        if (Validator::make($data, [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:72'],
        ])->fails()) {
            $this->error('Set a valid INITIAL_SUPERADMIN_EMAIL and a new INITIAL_SUPERADMIN_PASSWORD of 12–72 characters.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($data): void {
                $counter = DB::table('account_sequences')->where('name', 'users')->lockForUpdate()->first();
                if (! $counter) {
                    throw new RuntimeException('Account sequence is missing. Run migrations first.');
                }
                $existing = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$data['email']])->first();
                if ($existing && $existing->normalizedRole() === 'super_admin' && $existing->isActive()) {
                    return;
                }
                if ($existing) {
                    throw new RuntimeException('This email already belongs to an account. No account was created.');
                }
                if (User::query()->whereIn('role', ['superadmin', 'super_admin'])->where('status', 'Active')->exists()) {
                    throw new RuntimeException('An active Super Admin already exists. No account was created.');
                }

                $id = max($counter->next_id, (int) DB::table('users')->max('id') + 1);
                DB::table('users')->insert([
                    'id' => $id,
                    'role' => 'superadmin',
                    'name' => 'Super Admin',
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'status' => 'Active',
                    'createdBy' => 'Initial setup',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('account_sequences')->where('name', 'users')->update(['next_id' => $id + 1]);
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Initial Super Admin is available. Disable SEED_INITIAL_SUPERADMIN after provisioning.');

        return self::SUCCESS;
    }
}
