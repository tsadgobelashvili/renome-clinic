<?php

namespace Database\Seeders;

use App\Models\LabTechnicianRate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LabTechnicianRateSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            'Alex' => [['zirconia', 'production', 25], ['pmma', 'production', 5], ['custom_abutment', 'additional', 10], ['milling', 'additional', 5]],
            'Ilia Zlobin' => [['zirconia', 'design', 10], ['pmma', 'design', 5], ['titanium_bar_modeling', 'additional', 30], ['custom_abutment', 'additional', 10], ['milling', 'additional', 5]],
            'Mari Shavaeva' => [['zirconia', 'design', 5], ['pmma', 'design', 5], ['milling', 'additional', 5]],
            'Mari Ichukaidze' => [['zirconia', 'design', 3], ['milling', 'additional', 5]],
        ];
        foreach ($definitions as $name => $rates) {
            $user = User::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if (! $user) {
                $user = User::create(['name' => $name, 'email' => Str::slug($name).'.lab@renome.local', 'password' => Hash::make(Str::random(40)), 'role' => User::ROLE_LAB_TECHNICIAN, 'locale' => 'ka']);
            } else {
                $user->update(['role' => User::ROLE_LAB_TECHNICIAN]);
            }
            foreach ($rates as [$work, $component, $rate]) {
                LabTechnicianRate::updateOrCreate(['technician_id' => $user->id, 'work_type' => $work, 'component_type' => $component], ['rate_per_unit' => $rate, 'is_active' => true]);
            }
        }
    }
}
