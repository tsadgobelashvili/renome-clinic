<?php

namespace App\Filament\Pages;

use App\Models\Doctor;
use App\Models\PatientGroup;
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

    public string $patientGroup = DoctorCompensationCalculator::GROUP_ALL;

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
        $data = $this->previewData();
        $this->report = $calculator->calculate(
            (int) $data['doctorId'],
            $data['from'],
            $data['until'],
            is_numeric($data['percentage']) ? (float) $data['percentage'] : 0.0,
            null,
            $data['patientGroup'],
        );
    }

    public function confirmSettlement(SalarySettlementService $service): void
    {
        $data = $this->validatedData();
        $service->settle((int) $data['doctorId'], $data['from'], $data['until'], (float) $data['percentage'], auth()->id(), null, $data['patientGroup']);
        $this->report = null;
        Notification::make()->success()->title('ხელფასი დაფიქსირდა.')->send();
    }

    public function undoSettlement(int $settlementId, SalarySettlementService $service): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);

        if (blank($this->doctorId)) {
            return;
        }

        $undone = $service->undo($settlementId, $this->doctorId);
        $this->report = null;

        if ($undone) {
            $doctor = Doctor::query()->find($this->doctorId);
            $doctor?->clearCompensationSummaryCache();
            $this->from = app(DoctorCompensationCalculator::class)->defaultPeriodStart($this->doctorId, $this->patientGroup);
        }

        $this->dispatch('$refresh');

        Notification::make()
            ->status($undone ? 'success' : 'warning')
            ->title($undone ? 'ხელფასის დაფიქსირება გაუქმდა.' : 'ჩანაწერი უკვე გაუქმებულია ან აღარ არსებობს.')
            ->send();
    }

    /** @return array<string, mixed> */
    private function validatedData(): array
    {
        return $this->validate([
            'doctorId' => ['required', Rule::exists('doctors', 'id')],
            'from' => ['required', 'date'],
            'until' => ['required', 'date', 'after_or_equal:from'],
            'percentage' => ['required', 'numeric', 'gt:0', 'max:100'],
            'patientGroup' => ['required', Rule::in([
                DoctorCompensationCalculator::GROUP_ALL,
                PatientGroup::CLINIC_SLUG,
                PatientGroup::ISRAEL_PARTNER_SLUG,
            ])],
        ], ['doctorId.required' => 'აირჩიეთ ექიმი.', 'until.after_or_equal' => 'საბოლოო თარიღი საწყისზე ადრე ვერ იქნება.',
            'percentage.required' => 'მიუთითეთ ექიმის პროცენტი.']);
    }

    /** @return array<string, mixed> */
    private function previewData(): array
    {
        return $this->validate([
            'doctorId' => ['required', Rule::exists('doctors', 'id')],
            'from' => ['required', 'date'],
            'until' => ['required', 'date', 'after_or_equal:from'],
            'percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'patientGroup' => ['required', Rule::in([
                DoctorCompensationCalculator::GROUP_ALL,
                PatientGroup::CLINIC_SLUG,
                PatientGroup::ISRAEL_PARTNER_SLUG,
            ])],
        ]);
    }

    protected function getViewData(): array
    {
        $settlements = auth()->user()?->isOwner() && filled($this->doctorId) ? SalarySettlement::query()->where('doctor_id', $this->doctorId)
            ->with(['items.visit.patient', 'items.visitTreatmentCase.treatmentCase'])
            ->latest('settled_at')->latest('id')->get() : collect();

        return [
            'doctors' => Doctor::query()->where('is_active', true)->orderBy('first_name')->orderBy('last_name')->get(),
            'settlements' => $settlements
                ->groupBy(fn (SalarySettlement $settlement): string => implode('|', [
                    $settlement->doctor_id,
                    $settlement->period_start->toDateString(),
                    $settlement->period_end->toDateString(),
                    $settlement->patient_group_slug,
                    $settlement->currency,
                ]))
                ->map(function ($records): SalarySettlement {
                    /** @var SalarySettlement $display */
                    $display = clone $records->first();
                    foreach ([
                        'performed_total', 'paid_amount', 'outstanding_amount', 'direct_expense_total', 'base_total',
                        'normal_salary_total', 'owner_split_received_total', 'salary_total',
                    ] as $field) {
                        $display->setAttribute($field, round((float) $records->sum($field), 2));
                    }
                    $display->setAttribute('settled_at', $records->max('settled_at'));
                    $display->setRelation('items', $records->flatMap->items->values());
                    $display->setRelation('historyRecords', $records->values());

                    return $display;
                })
                ->sortByDesc(fn (SalarySettlement $settlement) => $settlement->settled_at)
                ->values(),
        ];
    }
}
