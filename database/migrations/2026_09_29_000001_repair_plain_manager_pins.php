<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    public function up(): void
    {
        // Imported accounts may contain plaintext PINs after the original migration ran.
        foreach (DB::table('users')->whereRaw('LOWER(role) = ?', ['manager'])->get() as $manager) {
            if (preg_match('/^[0-9]{4}$/D', (string) $manager->approval_pin)) {
                DB::table('users')->where('email', $manager->email)->where('role', $manager->role)
                    ->where('approval_pin', $manager->approval_pin)
                    ->update(['approval_pin' => Hash::make($manager->approval_pin)]);
            }
        }
    }

    public function down(): void {}
};
