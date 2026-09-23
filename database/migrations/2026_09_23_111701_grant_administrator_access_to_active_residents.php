<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(true)->change();
        });
        DB::transaction(function () {
            $residents = DB::table('users')->where('active', true)->where('is_admin', false)
                ->whereIn('household_id', DB::table('households')->where('active', true)->select('id'))->lockForUpdate()->pluck('id');
            DB::table('users')->whereIn('id', $residents)->update(['is_admin' => true, 'updated_at' => now()]);
            foreach ($residents as $id) {
                DB::table('audit_events')->insert([
                    'user_id' => null, 'action' => 'resident.administrator_granted', 'subject' => 'user:'.$id,
                    'details' => json_encode(['reason' => 'All current residents share administrator access.']),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * Restore the default without revoking explicitly granted resident access.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->change();
        });
    }
};
