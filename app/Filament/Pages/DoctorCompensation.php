<?php

namespace App\Filament\Pages;

use App\Models\Doctor;
use App\Models\SalarySettlement;
use App\Services\DoctorCompensationCalculator;
use App\Services\SalarySettlementService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use UnitEnum;

class DoctorCompensation extends Page
{
    protected string $view = 'filament.pages.doctor-compensation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'ექიმის ანაზღაურება';

    protected static ?string $title = 'ექიმის ანაზღაურება';

    protected static ?int $navigationSort = 45;

    public ?int $doctorId = null;

    public bool $doctorLocked = false;

    public string $from;

    public string $until;

    public ?float $percentage = null;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->until = today()->toDateString();
        $doctorId = request()->integer('doctor');
        if ($doctorId && Doctor::query()->whereKey($doctorId)->exists()) {
            $this->doctorId = $doctorId;
            $this->doctorLocked = true;
            $this->percentage = (float) (Doctor::query()->find($doctorId)?->compensation_percentage ?? 0);
        }
    }

    public function updatedDoctorId(mixed $doctorId): void
    {
        $this->percentage = filled($doctorId) ? (float) (Doctor::query()->find($doctorId)?->compensation_percentage ?? 0) : null;
        $this->report = null;
    }

    public function calculate(DoctorCompensationCalculator $calculator): void
    {
        $data = $this->validatedData();
        $this->report = $calculator->calculate((int) $data['doctorId'], $data['from'], $data['until'], (float) $data['percentage']);
    }

    public function confirmSettlement(SalarySettlementService $service): void
    {
        $data = $this->validatedData();
        $service->settle((int) $data['doctorId'], $data['from'], $data['until'], (float) $data['percentage'], auth()->id());
        $this->report = null;
        Notification::make()->success()->title('ხელფასი დაფიქსირდა.')->send();
    }

    /** @return array<string, mixed> */
    private function validatedData(): array
    {
        return $this->validate([
            'doctorId' => ['required', Rule::exists('doctors', 'id')],
            'from' => ['required', 'date'],
            'until' => ['required', 'date', 'after_or_equal:from'],
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ], ['doctorId.required' => 'აირჩიეთ ექიმი.', 'until.after_or_equal' => 'საბოლოო თარიღი საწყისზე ადრე ვერ იქნება.',
            'percentage.required' => 'მიუთითეთ ექიმის პროცენტი.']);
    }

    protected function getViewData(): array
    {
        return [
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('first_name')->orderBy('last_name')->get(),
            'settlements' => filled($this->doctorId) ? SalarySettlement::query()->where('doctor_id', $this->doctorId)
                ->with(['items.visit.patient', 'items.visitTreatmentCase.treatmentCase'])
                ->latest('settled_at')->get() : collect(),
        ];
    }
}
