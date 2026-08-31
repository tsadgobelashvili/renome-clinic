<?php

namespace App\Filament\Pages;

use App\Models\LabSalarySettlement;
use App\Models\User;
use App\Services\LabSalaryService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class LabSalaries extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.lab-salaries';

    public ?int $technicianId = null;

    public string $periodStart = '';

    public string $periodEnd = '';

    public array $report = ['items' => [], 'total' => 0];

    public static function getNavigationLabel(): string
    {
        return __('lab.navigation.salary');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lab.navigation.group');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public function mount(): void
    {
        $this->periodStart = now()->startOfMonth()->toDateString();
        $this->periodEnd = now()->toDateString();
    }

    public function calculate(): void
    {
        $this->validate(['technicianId' => ['required', 'exists:users,id'], 'periodStart' => ['required', 'date'], 'periodEnd' => ['required', 'date', 'after_or_equal:periodStart']]);
        $value = app(LabSalaryService::class)->calculate($this->technicianId, $this->periodStart, $this->periodEnd);
        $this->report = ['items' => $value['items']->map(fn ($item) => ['id' => $item->id, 'date' => $item->work_date->format('d.m.Y'), 'patient' => $item->labCase->patient->full_name, 'work' => $item->work_type, 'component' => $item->component_type, 'quantity' => $item->quantity, 'rate' => (float) $item->rate_snapshot, 'salary' => (float) $item->salary_amount])->all(), 'total' => $value['total']];
    }

    public function confirm(): void
    {
        $this->calculate();
        app(LabSalaryService::class)->settle($this->technicianId, $this->periodStart, $this->periodEnd, auth()->id());
        $this->calculate();
        Notification::make()->success()->title('Salary settled')->send();
    }

    public function undo(int $settlementId): void
    {
        $settlement = LabSalarySettlement::findOrFail($settlementId);
        app(LabSalaryService::class)->undo($settlement);
        $this->calculate();
    }

    public function technicians(): array
    {
        return User::query()->where('role', User::ROLE_LAB_TECHNICIAN)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function settlements()
    {
        return LabSalarySettlement::query()->with('technician')->latest('settled_at')->limit(50)->get();
    }
}
