<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_deliveries', function (Blueprint $table) {
            $table->json('payload')->nullable();
            $table->timestamp('first_attempt_at')->nullable();
            $table->string('status')->default('pending');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_deliveries', fn (Blueprint $table) => $table->dropColumn(['payload', 'first_attempt_at', 'status']));
    }
};
