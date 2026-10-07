<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->timestamp('created_at', 6)->nullable()->index();
            });
        }
        // Recover known creation dates without inventing dates for imported records.
        foreach (['Created category' => ['categories', 'name'], 'Registered item' => ['products', 'id']] as $action => [$table, $key]) {
            $events = DB::table('audit_logs')->where('action', $action)
                ->select('record')->selectRaw('MIN(ts) as created_at')->groupBy('record')->get();
            foreach ($events as $event) {
                if ($table === 'products' && ! ctype_digit((string) $event->record)) {
                    continue;
                }
                DB::table($table)->where($key, $event->record)->update(['created_at' => $event->created_at]);
            }
        }
    }

    public function down(): void
    {
        foreach (['categories', 'products'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['created_at']);
                $table->dropColumn('created_at');
            });
        }
    }
};
