<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

final class GroupedLedgerRows
{
    public static function paginate($query, string $keySql, string $dateColumn, string $pageName = 'page')
    {
        $keyed = (clone $query)->reorder()->selectRaw($keySql.' AS display_group');
        $groups = DB::query()->fromSub($keyed, 'grouped_rows')->select('display_group', 'currency')
            ->selectRaw('COUNT(*) AS row_count, SUM(amount) AS total, MAX('.$dateColumn.') AS latest_date')
            ->groupBy('display_group', 'currency')->orderByDesc('latest_date')->orderBy('display_group')
            ->paginate(25, pageName: $pageName);
        $members = $groups->isEmpty() ? collect() : DB::query()->fromSub($keyed, 'grouped_rows')
            ->whereIn('display_group', $groups->pluck('display_group'))->orderByDesc($dateColumn)->get()->groupBy('display_group');
        $groups->getCollection()->transform(function ($group) use ($members) {
            $group->rows = ($members->get($group->display_group) ?? collect())->where('currency', $group->currency)->values();
            return $group;
        });
        return $groups;
    }
}
