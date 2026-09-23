<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('category')->nullable();
            $table->string('suggested_category')->nullable();
            $table->string('confidence')->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('budget_category_rules', function (Blueprint $table) {
            $table->id();
            $table->string('description_hash', 64)->unique();
            $table->text('description');
            $table->string('category');
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
        Schema::create('budget_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('provider');
            $table->string('model');
            $table->string('status');
            $table->json('snapshot');
            $table->text('error')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
        Schema::create('budget_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('category');
            $table->unsignedBigInteger('annual_cents');
            $table->foreignId('user_id')->constrained();
            $table->unique(['year', 'category']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_targets');
        Schema::dropIfExists('budget_ai_runs');
        Schema::dropIfExists('budget_category_rules');
        Schema::dropIfExists('budget_classifications');
    }
};
