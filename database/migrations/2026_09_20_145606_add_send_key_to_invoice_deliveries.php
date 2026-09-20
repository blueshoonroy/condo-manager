<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->string('send_key', 60)->default('automatic');
            $table->unique(['invoice_id', 'user_id', 'send_key'], 'invoice_delivery_send_unique');
        });
        Schema::table('invoice_deliveries', fn (Blueprint $table) => $table->dropUnique(['invoice_id', 'user_id']));
    }

    public function down(): void
    {
        if (DB::table('invoice_deliveries')->select('invoice_id', 'user_id')->groupBy('invoice_id', 'user_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot roll back without losing invoice delivery history.');
        }
        Schema::table('invoice_deliveries', fn (Blueprint $table) => $table->unique(['invoice_id', 'user_id']));
        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->dropUnique('invoice_delivery_send_unique');
            $table->dropColumn('send_key');
        });
    }
};
