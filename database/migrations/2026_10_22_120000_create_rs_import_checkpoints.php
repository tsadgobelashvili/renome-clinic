<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rs_import_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('temporary');
            $table->string('original_filename')->nullable();
            $table->string('source_path')->nullable();
            $table->string('staged_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('error_report_path')->nullable();
            $table->unsignedBigInteger('total_rows')->default(0);
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->unsignedBigInteger('imported_count')->default(0);
            $table->unsignedBigInteger('duplicate_count')->default(0);
            $table->unsignedBigInteger('rejected_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        // Only reconciliation indexes/counters. Source rows live in private JSONL.
        Schema::create('rs_import_checkpoint_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('checkpoint_id')->constrained('rs_import_checkpoints')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('entry_key', 64);
            $table->text('label')->nullable();
            $table->text('value')->nullable();
            $table->bigInteger('number')->default(0);
            $table->boolean('flag')->default(false);
            $table->unique(['checkpoint_id', 'kind', 'entry_key'], 'rs_checkpoint_entry_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rs_import_checkpoint_entries');
        Schema::dropIfExists('rs_import_checkpoints');
    }
};
