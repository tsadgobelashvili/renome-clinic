<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('expense_categories')->insertOrIgnore([
            'name' => 'სხვა', 'classification_dimension' => 'direction', 'classification_code' => 'other',
            'active' => true, 'sort_order' => 70, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep the shared registry entry: historical mappings may already reference it.
    }
};
