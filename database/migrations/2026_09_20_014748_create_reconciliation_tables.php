<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->text('api_key')->nullable();
            $table->boolean('selected')->default(false);
            $table->timestamps();
        });
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('provider');
            $table->string('model');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('status')->default('queued');
            $table->json('snapshot');
            $table->text('error')->nullable();
            $table->unsignedInteger('skipped')->default(0);
            $table->timestamps();
        });
        Schema::create('reconciliation_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('reconciliation_runs');
            $table->foreignId('bank_transaction_id')->constrained();
            $table->foreignId('household_id')->constrained();
            $table->json('allocations');
            $table->string('confidence');
            $table->text('reason');
            $table->string('status')->default('pending');
            $table->uuid('request_key')->unique();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['run_id', 'bank_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_suggestions');
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('ai_settings');
    }
};
