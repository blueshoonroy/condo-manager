<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('number')->unique();
            $table->timestamps();
        });
        Schema::create('households', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained();
            $table->string('client_name')->unique();
            $table->boolean('active')->default(true);
            $table->date('move_in')->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('household_id')->nullable()->constrained();
            $table->boolean('is_admin')->default(false);
            $table->boolean('active')->default(true);
            $table->string('phone')->nullable();
        });
        Schema::create('dues_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained();
            $table->date('effective_on');
            $table->unsignedInteger('amount_cents');
            $table->unique(['unit_id', 'effective_on']);
            $table->timestamps();
        });
        Schema::create('login_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('filename');
            $table->string('checksum', 64);
            $table->string('path')->nullable();
            $table->string('status')->default('preview');
            $table->json('summary')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained();
            $table->foreignId('unit_id')->constrained();
            $table->string('number')->unique();
            $table->string('source')->default('portal');
            $table->string('billing_key')->nullable()->unique();
            $table->date('issued_on');
            $table->date('due_on');
            $table->date('paid_on')->nullable();
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('historical_paid_cents')->default(0);
            $table->string('source_status')->nullable();
            $table->json('items');
            $table->text('void_reason')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained();
            $table->timestamps();
        });
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->date('posted_on')->index();
            $table->text('description');
            $table->bigInteger('amount_cents');
            $table->foreignId('import_batch_id')->constrained();
            $table->timestamps();
        });
        Schema::create('balance_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->date('as_of')->unique();
            $table->bigInteger('amount_cents');
            $table->text('note');
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained();
            $table->foreignId('bank_transaction_id')->nullable()->unique()->constrained();
            $table->uuid('request_key')->unique();
            $table->unsignedInteger('amount_cents');
            $table->date('paid_on');
            $table->text('note');
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
        });
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained();
            $table->foreignId('invoice_id')->constrained();
            $table->unsignedInteger('amount_cents');
            $table->unique(['payment_id', 'invoice_id']);
        });
        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id', 'user_id']);
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('action');
            $table->string('subject');
            $table->json('details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'invoice_deliveries', 'payment_allocations', 'payments', 'balance_checkpoints', 'bank_transactions', 'invoices', 'import_batches', 'login_challenges', 'dues_rates'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('household_id');
            $table->dropColumn(['is_admin', 'active', 'phone']);
        });
        Schema::dropIfExists('households');
        Schema::dropIfExists('units');
    }
};
