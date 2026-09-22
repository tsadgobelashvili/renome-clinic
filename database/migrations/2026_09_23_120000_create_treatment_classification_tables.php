<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('treatment_categories', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::create('treatment_statistics_groups', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('category_id');
            $table->string('name');
            $table->string('legacy_key', 32)->nullable();
            $table->timestamps();
            $table->foreign('category_id')->references('id')->on('treatment_categories')->restrictOnDelete();
            $table->unique(['category_id', 'name']);
            $table->unique(['id', 'category_id']);
        });
        // Stable legacy IDs retain operational category semantics. Labels are now editable data.
        $categories = ['other' => 'სხვა', 'surgery' => 'ქირურგია', 'orthopedics' => 'ორთოპედია',
            'therapy' => 'თერაპია', 'periodontology' => 'პაროდონტოლოგია', 'orthodontics' => 'ორთოდონტია',
            'consultation' => 'კონსულტაცია', 'tomography' => 'ტომოგრაფია', 'pediatric_dentistry' => 'ბავშვთა'];
        foreach (DB::table('treatment_cases')->whereNotNull('category')->distinct()->pluck('category') as $category) {
            if (trim($category) !== '') {
                $categories[$category] ??= $category;
            }
        }
        foreach ($categories as $id => $name) {
            DB::table('treatment_categories')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $groups = ['filling' => ['therapy', 'დაბჟენა'], 'endodontics' => ['therapy', 'ენდოდონტია'],
            'cleaning' => ['therapy', 'წმენდა'], 'whitening' => ['therapy', 'გათეთრება'], 'medication' => ['therapy', 'წამლის მოთავსება'],
            'extraction' => ['surgery', 'ექსტრაქცია'], 'implantation' => ['surgery', 'იმპლანტაცია'],
            'augmentation' => ['surgery', 'აუგმენტაცია'], 'sinus_lift' => ['surgery', 'სინუს ლიფტინგი'],
            'zircon' => ['orthopedics', 'ცირკონი'], 'pmma' => ['orthopedics', 'PMMA / დროებითი გვირგვინი'],
            'prosthesis' => ['orthopedics', 'პროთეზი'], 'other' => ['other', 'სხვა']];
        foreach ($groups as $id => [$category, $name]) {
            DB::table('treatment_statistics_groups')->insert(['id' => $id, 'category_id' => $category, 'name' => $name,
                'legacy_key' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $pairs = DB::table('treatment_cases')->whereNotNull('statistics_group')->select('category', 'statistics_group')->distinct()->get();
        foreach ($pairs as $pair) {
            $category = trim((string) $pair->category) === '' ? 'other' : $pair->category;
            $key = $pair->statistics_group;
            if (isset($groups[$key]) && $groups[$key][0] === $category) {
                continue;
            }
            $id = substr(hash('sha256', $category.'|'.$key), 0, 32);
            DB::table('treatment_statistics_groups')->insert(['id' => $id, 'category_id' => $category,
                'name' => $groups[$key][1] ?? $key, 'legacy_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('treatment_cases')->where('category', $pair->category)->where('statistics_group', $key)->update(['statistics_group' => $id]);
        }
        // Existing blank/unclassified category values are preserved. Group deletion is database-protected.
        Schema::table('treatment_cases', function (Blueprint $table) {
            $table->foreign('statistics_group')->references('id')->on('treatment_statistics_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('treatment_cases', fn (Blueprint $table) => $table->dropForeign(['statistics_group']));
        foreach (DB::table('treatment_statistics_groups')->whereNotNull('legacy_key')->get() as $group) {
            DB::table('treatment_cases')->where('statistics_group', $group->id)->update(['statistics_group' => $group->legacy_key]);
        }
        Schema::dropIfExists('treatment_statistics_groups');
        Schema::dropIfExists('treatment_categories');
    }
};
