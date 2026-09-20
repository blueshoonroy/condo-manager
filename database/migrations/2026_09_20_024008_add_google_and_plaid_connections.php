<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
        });
        Schema::create('plaid_connections', function (Blueprint $table) {
            $table->id();
            $table->string('environment')->unique();
            $table->string('item_id')->nullable();
            $table->text('access_token')->nullable();
            $table->string('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->string('mask')->nullable();
            $table->string('status')->default('select_account');
            $table->date('starts_on')->nullable();
            $table->text('cursor')->nullable();
            $table->bigInteger('balance_cents')->nullable();
            $table->bigInteger('available_cents')->nullable();
            $table->timestamp('balance_fetched_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('source')->default('csv');
            $table->timestamp('removed_at')->nullable();
            $table->boolean('review_required')->default(false);
        });
        Schema::create('plaid_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('plaid_connections');
            $table->string('transaction_id');
            $table->date('posted_on');
            $table->text('description');
            $table->bigInteger('amount_cents');
            $table->boolean('pending')->default(false);
            $table->boolean('removed')->default(false);
            $table->foreignId('bank_transaction_id')->nullable()->constrained();
            $table->timestamps();
            $table->unique(['connection_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plaid_transactions');
        Schema::dropIfExists('plaid_connections');
        Schema::table('bank_transactions', fn (Blueprint $table) => $table->dropColumn(['source', 'removed_at', 'review_required']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('google_id'));
    }
};
