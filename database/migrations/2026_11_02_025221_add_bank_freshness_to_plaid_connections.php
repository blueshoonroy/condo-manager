<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plaid_connections', function (Blueprint $table) {
            $table->timestamp('data_updated_at')->nullable();
            $table->string('live_balance_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plaid_connections', fn (Blueprint $table) => $table->dropColumn(['data_updated_at', 'live_balance_error']));
    }
};
