<?php

namespace Database\Seeders;

use App\Models\PatientGroup;
use Illuminate\Database\Seeder;

class PatientGroupSeeder extends Seeder
{
    public function run(): void
    {
        PatientGroup::query()->updateOrCreate(
            ['slug' => PatientGroup::CLINIC_SLUG],
            ['name' => 'Clinic', 'is_active' => true],
        );
        PatientGroup::query()->updateOrCreate(
            ['slug' => PatientGroup::ISRAEL_PARTNER_SLUG],
            ['name' => 'Israel Partner', 'is_active' => true],
        );
    }
}
