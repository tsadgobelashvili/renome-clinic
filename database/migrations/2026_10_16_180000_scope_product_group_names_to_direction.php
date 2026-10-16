<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $groups = DB::table('purchase_product_groups')->orderBy('id')->get();
        $legacyDirections = DB::table('purchase_products')->whereNotNull('purchase_product_group_id')->whereNotNull('expense_direction_id')
            ->select('purchase_product_group_id')->selectRaw('MIN(expense_direction_id) AS direction_id, COUNT(DISTINCT expense_direction_id) AS directions_count')
            ->groupBy('purchase_product_group_id')->get()->keyBy('purchase_product_group_id');
        $seen = [];
        foreach ($groups as $group) {
            $legacy = $legacyDirections->get($group->id);
            $direction = $group->expense_direction_id ?? ($legacy?->directions_count == 1 ? $legacy->direction_id : null);
            if ($direction === null) {
                continue;
            }
            $key = $direction.'|'.$this->normalize($group->name);
            if (isset($seen[$key])) {
                throw new RuntimeException("Conflicting Product Groups {$seen[$key]} and {$group->id} in Direction {$direction}. Resolve explicitly before migrating; no groups were merged or reassigned.");
            }
            $seen[$key] = $group->id;
        }

        // Add analytical metadata only. Names, Directions, product links and timestamps are untouched.
        Schema::table('purchase_product_groups', function (Blueprint $table): void {
            $table->string('normalized_name')->default('');
        });
        foreach ($groups as $group) {
            DB::table('purchase_product_groups')->where('id', $group->id)->update(['normalized_name' => $this->normalize($group->name)]);
        }
        Schema::table('purchase_product_groups', function (Blueprint $table): void {
            $table->unique(['expense_direction_id', 'normalized_name'], 'purchase_groups_direction_name_unique');
            $table->dropUnique('purchase_product_groups_name_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('purchase_product_groups')->select('name')->groupBy('name')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore global Product Group uniqueness while same-named groups exist in different Directions. No groups were changed.');
        }
        Schema::table('purchase_product_groups', function (Blueprint $table): void {
            $table->unique('name', 'purchase_product_groups_name_unique');
            $table->dropUnique('purchase_groups_direction_name_unique');
            $table->dropColumn('normalized_name');
        });
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
};
