<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Models\Doctor;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Services\FullDiscountStatistics as Statistics;
use App\Services\ProcedureClassification;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use UnitEnum;

class FullDiscountStatistics extends Page
{
    use AuthorizesPageAccess;

    protected string $view = 'filament.pages.full-discount-statistics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?int $navigationSort = 33;

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public bool $embedded = false;

    public ?string $dateFrom = null;

    public ?string $dateUntil = null;

    public string $period = '14_days';

    public string $source = 'all';

    public string $currency = 'GEL';

    public ?string $doctor = null;

    public ?string $category = null;

    #[Locked]
    public ?array $detailsScope = null;

    #[Locked]
    public ?array $expandedScope = null;

    #[Locked]
    public int $detailsPage = 1;

    public static function getNavigationLabel(): string
    {
        return __('discount-statistics.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getView(): string
    {
        return $this->embedded ? 'filament.pages.partials.full-discount-statistics-content' : parent::getView();
    }

    public function mount(bool $embedded = false): void
    {
        $this->embedded = $embedded;
        $this->applyPeriod('14_days');
    }

    public function applyPeriod(string $period): void
    {
        if (! in_array($period, ['14_days', '1_month', '6_months', '1_year', 'all'], true)) {
            return;
        }
        $this->period = $period;
        $this->dateUntil = $period === 'all' ? null : today()->toDateString();
        $this->dateFrom = match ($period) {
            '14_days' => today()->subDays(13)->toDateString(),
            '1_month' => today()->subMonth()->addDay()->toDateString(),
            '6_months' => today()->subMonths(6)->addDay()->toDateString(),
            '1_year' => today()->subYear()->addDay()->toDateString(),
            default => null,
        };
        $this->resetDetails();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['dateFrom', 'dateUntil'], true)) {
            $this->period = 'custom';
        }
        $this->resetDetails();
    }

    private function resetDetails(): void
    {
        $this->detailsScope = null;
        $this->expandedScope = null;
        $this->detailsPage = 1;
    }

    public function openDetails(array $scope = []): void
    {
        $this->detailsScope = $this->safeScope($scope);
        $this->detailsPage = 1;
    }

    public function closeDetails(): void
    {
        $this->detailsScope = null;
    }

    public function changeDetailsPage(int $page): void
    {
        $this->detailsPage = max(1, $page);
    }

    public function expandServices(array $scope): void
    {
        $scope = $this->safeScope($scope);
        $this->expandedScope = $this->expandedScope === $scope ? null : $scope;
    }

    private function safeScope(array $scope): array
    {
        abort_unless(static::canAccess(), 403);

        return array_filter(array_intersect_key($scope, array_flip([
            'doctor_id', 'category_key', 'group_key', 'group_type', 'reason', 'service_name', 'salary_status',
        ])), fn ($value): bool => is_scalar($value) || $value === null);
    }

    public function filters(): array
    {
        $this->validate([
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateUntil' => ['nullable', 'date_format:Y-m-d', ...($this->dateFrom ? ['after_or_equal:dateFrom'] : [])],
            'source' => ['in:all,'.PatientGroup::CLINIC_SLUG.','.PatientGroup::ISRAEL_PARTNER_SLUG],
            'currency' => ['in:GEL,USD'], 'doctor' => ['nullable', 'integer'],
            'category' => ['nullable', Rule::in(array_keys(TreatmentCase::CATEGORIES + ['uncategorized' => '']))],
        ]);

        return ['from' => $this->dateFrom, 'until' => $this->dateUntil, 'source' => $this->source,
            'currency' => $this->currency, 'doctor' => $this->doctor, 'category' => $this->category];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $filters = $this->filters();
        $statistics = app(Statistics::class);

        return [
            'report' => $statistics->report($filters),
            'doctorOptions' => Doctor::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name']),
            'categoryOptions' => TreatmentCase::CATEGORIES + ['uncategorized' => ProcedureClassification::label('uncategorized')],
            'expandedServices' => $this->expandedScope === null ? null : $statistics->services($filters, $this->expandedScope),
            'detailRows' => $this->detailsScope === null ? null : $statistics->details($filters, $this->detailsScope, $this->detailsPage),
        ];
    }
}
