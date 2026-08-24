<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->after('name');
        });

        $knownCategories = [
            'იმპლანტაცია' => 'surgery',
            'ექსტრაქცია' => 'surgery',
            'სინუს ლიფტი' => 'surgery',
            'ძვლის აუგმენტაცია' => 'surgery',
            'ქირურგია' => 'surgery',
            'ცირკონის გვირგვინი' => 'orthopedics',
            'ცირკონის გვირგვინი კბილზე' => 'orthopedics',
            'ცირკონის გვირგვინი იმპლანტზე' => 'orthopedics',
            'ცირკონი / ორთოპედია' => 'orthopedics',
            'ვინირი' => 'orthopedics',
            'დაბჟენა' => 'therapy',
            'არხის მკურნალობა' => 'therapy',
            'თერაპია' => 'therapy',
            'პროფესიული წმენდა' => 'periodontology',
            'წმენდა' => 'periodontology',
            'ბრეკეტები' => 'orthodontics',
            'ორთოდონტია' => 'orthodontics',
        ];

        foreach ($knownCategories as $name => $category) {
            DB::table('treatment_cases')->where('name', $name)->update(['category' => $category]);
        }

        $uncategorizedNames = DB::table('treatment_cases')
            ->whereNull('category')
            ->orderBy('id')
            ->pluck('name');

        if ($uncategorizedNames->isNotEmpty()) {
            throw new RuntimeException(
                'Treatment catalog contains uncategorized records: '.$uncategorizedNames->join(', '),
            );
        }

        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->string('category', 32)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_cases', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
