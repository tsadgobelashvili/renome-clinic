<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        foreach (['ბარათის ჩარიცხვა', 'მომწოდებელი / მასალები', 'ქირა', 'კომუნალური', 'ხელფასი', 'ხელფასის გადასახადები', 'გადასახადები', 'ბანკის საკომისიო', 'ნაღდის შეტანა', 'შიდა გადარიცხვა', 'მოწყობილობა', 'სხვა'] as $order => $name) {
            DB::table('bank_categories')->insert(['name' => $name, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
        }
        Schema::create('bank_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 20)->default('BOG');
            $table->string('account_identifier')->nullable();
            $table->string('currency', 3)->nullable();
            $table->json('accounts');
            $table->json('currencies');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('opening_balance', 18, 2)->nullable();
            $table->decimal('closing_balance', 18, 2)->nullable();
            $table->decimal('reported_balance', 18, 2)->nullable();
            $table->dateTime('balance_as_of')->nullable();
            $table->string('balance_origin')->nullable();
            $table->string('source_file');
            $table->string('stored_path')->nullable();
            $table->char('file_hash', 64);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('errors')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('imported_at');
            $table->timestamps();
            $table->index(['bank', 'account_identifier', 'currency', 'balance_as_of'], 'bank_batch_balance_index');
        });
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('bank', 20)->default('BOG');
            $table->string('account_identifier')->nullable();
            $table->dateTime('transaction_date')->index();
            $table->date('value_date')->nullable();
            $table->string('direction', 10);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('operation_type')->nullable()->index();
            $table->string('operation_id')->nullable()->index();
            $table->string('reference')->nullable()->index();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_account')->nullable();
            $table->text('description')->nullable();
            $table->decimal('bank_fee', 18, 2)->default(0);
            $table->decimal('gross_amount', 18, 2)->nullable();
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->foreignId('bank_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source', 10)->default('import');
            $table->string('source_file')->nullable();
            $table->foreignId('import_batch_id')->nullable()->constrained('bank_import_batches')->restrictOnDelete();
            $table->json('raw_data')->nullable();
            $table->char('fingerprint', 64);
            $table->char('deduplication_key', 64)->unique();
            $table->timestamps();
            $table->index(['currency', 'direction', 'transaction_date'], 'bank_transaction_filter_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_import_batches');
        Schema::dropIfExists('bank_categories');
    }
};
