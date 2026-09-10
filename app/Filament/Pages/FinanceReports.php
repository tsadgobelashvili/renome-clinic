<?php

namespace App\Filament\Pages;

use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\ProductSale;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Support\Currency;
use App\Support\ExpenseCategoryForm;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class FinanceReports extends Finance
{
    protected string $view = 'filament.pages.finance-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'ანგარიშები / სტატისტიკა';

    protected static ?string $title = 'ანგარიშები / სტატისტიკა';

    protected static ?int $navigationSort = 32;

    public string $reportTab = 'income';

    public string $sectionTab = 'finance';

    public ?int $selectedDoctorId = null;

    public bool $showNotStartedPatients = false;

    public string $doctorDynamicsCurrency = Currency::DEFAULT;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function selectReportTab(string $tab): void
    {
        if (in_array($tab, ['income', 'expense', 'cash_out'], true)) {
            $this->reportTab = $tab;
        }
    }

    public function selectSectionTab(string $tab): void
    {
        if (in_array($tab, ['finance', 'dynamics', 'doctors', 'full_discounts'], true)) {
            if (in_array($this->sectionTab, ['finance', 'dynamics'], true) && ! in_array($tab, ['finance', 'dynamics'], true) && $this->period === 'all' && $this->dateFrom === '') {
                // Other report sections retain their existing All preset behavior.
                $this->applyDoctorsDatePreset('all');
            }
            $this->sectionTab = $tab;

            if ($tab !== 'doctors') {
                $this->selectedDoctorId = null;
                $this->showNotStartedPatients = false;
            }
        }
    }

    public function applyDoctorsDatePreset(string $preset): void
    {
        if (! in_array($preset, ['14_days', '1_month', '6_months', '1_year', 'all'], true)) {
            return;
        }

        $until = today();
        $this->period = $preset;
        $this->dateUntil = $until->toDateString();
        $this->dateFrom = match ($preset) {
            '14_days' => $until->copy()->subDays(13)->toDateString(),
            '6_months' => $until->copy()->subMonths(6)->addDay()->toDateString(),
            '1_year' => $until->copy()->subYear()->addDay()->toDateString(),
            'all' => '2000-01-01',
            default => $until->copy()->subMonth()->addDay()->toDateString(),
        };
        $this->selectedDoctorId = null;
        $this->showNotStartedPatients = false;
    }

    protected function restrictReportDates(): bool
    {
        return $this->period !== 'all';
    }

    public function applyFinanceDatePreset(string $preset): void
    {
        if ($preset === '1_year') {
            $this->period = $preset;
            $this->dateUntil = today()->toDateString();
            $this->dateFrom = today()->subYearNoOverflow()->addDay()->toDateString();

            return;
        }

        $this->applyDynamicsDatePreset($preset);
    }

    public function applyDynamicsDatePreset(string $preset): void
    {
        if (! in_array($preset, ['14_days', '1_month', '3_months', '6_months', 'all'], true)) {
            return;
        }

        $this->period = $preset;
        $this->dateUntil = $preset === 'all' ? '' : today()->toDateString();
        $this->dateFrom = match ($preset) {
            '14_days' => today()->subDays(13)->toDateString(),
            '1_month' => today()->subMonthNoOverflow()->addDay()->toDateString(),
            '3_months' => today()->subMonthsNoOverflow(3)->addDay()->toDateString(),
            '6_months' => today()->subMonthsNoOverflow(6)->addDay()->toDateString(),
            default => '',
        };
    }

    public function toggleNotStartedPatients(): void
    {
        if ($this->sectionTab === 'doctors') {
            $this->showNotStartedPatients = ! $this->showNotStartedPatients;
        }
    }

    public function toggleDoctor(int $doctorId): void
    {
        if ($this->selectedDoctorId === $doctorId) {
            $this->selectedDoctorId = null;

            return;
        }

        $this->selectedDoctorId = $doctorId;
        $this->doctorDynamicsCurrency = $this->currency;
    }

    public function updatedDoctorDynamicsCurrency(string $currency): void
    {
        if (! in_array($currency, [...array_keys(Currency::OPTIONS), 'both'], true)) {
            $this->doctorDynamicsCurrency = Currency::DEFAULT;
        }
    }

    public function updatedSource(): void
    {
        $this->selectedDoctorId = null;
        $this->showNotStartedPatients = false;
    }

    public function updatedCurrency(): void
    {
        $this->selectedDoctorId = null;
        $this->showNotStartedPatients = false;
    }

    public function updatedDateFrom(): void
    {
        parent::updatedDateFrom();
        $this->selectedDoctorId = null;
        $this->showNotStartedPatients = false;
    }

    public function updatedDateUntil(): void
    {
        parent::updatedDateUntil();
        $this->selectedDoctorId = null;
        $this->showNotStartedPatients = false;
    }

    protected function getViewData(): array
    {
        $this->historyMode = 'overview';

        if ($this->sectionTab === 'full_discounts') {
            // The existing child component owns its filters and aggregate queries.
            return [];
        }

        if ($this->sectionTab !== 'finance') {
            return [
                'currencyOptions' => Currency::OPTIONS,
                'sourceOptions' => ['all' => 'ყველა', 'clinic' => 'კლინიკა', 'partner' => 'ისრაელი'],
                'dynamics' => $this->sectionTab === 'dynamics' ? $this->dynamics() : null,
                'doctorStatistics' => $this->sectionTab === 'doctors' ? $this->doctorStatistics() : null,
            ];
        }

        $finance = parent::getViewData();
        $rows = $this->breakdown($this->reportTab);
        $total = round((float) match ($this->reportTab) {
            'expense' => $finance['totalsByCurrency'][$this->currency]['expense'],
            'cash_out' => $finance['cashOutByCurrency'][$this->currency],
            default => $finance['totalsByCurrency'][$this->currency]['income'],
        }, 2);

        $rows = collect($rows)
            ->filter(fn (array $row): bool => $row['amount'] > 0.005)
            ->sortByDesc('amount')
            ->values()
            ->map(function (array $row) use ($total): array {
                $row['percentage'] = $total > 0 ? round(($row['amount'] / $total) * 100, 1) : 0;

                return $row;
            })->all();

        $chartRows = count($rows) > 5
            ? array_merge(array_slice($rows, 0, 4), [[
                'key' => 'others',
                'label' => 'სხვა',
                'amount' => round((float) collect(array_slice($rows, 4))->sum('amount'), 2),
                'count' => (int) collect(array_slice($rows, 4))->sum('count'),
                'percentage' => round((float) collect(array_slice($rows, 4))->sum('percentage'), 1),
            ]])
            : $rows;

        $colors = match ($this->reportTab) {
            'expense' => ['#f43f5e', '#fbbf24', '#a78bfa', '#60a5fa', '#94a3b8'],
            'cash_out' => ['#3b82f6', '#a78bfa', '#fbbf24', '#fb7185', '#94a3b8'],
            default => ['#10b981', '#60a5fa', '#a78bfa', '#fbbf24', '#94a3b8'],
        };
        foreach ($chartRows as $index => &$row) {
            $row['color'] = $colors[$index] ?? '#94a3b8';
        }
        unset($row);

        return $finance + [
            'reportRows' => $rows,
            'chartRows' => $chartRows,
            'reportTotal' => $total,
            'breakdownDetails' => $this->breakdownDetails(),
            'breakdownDescriptions' => $this->breakdownDescriptions(),
            'dynamics' => null,
            'doctorStatistics' => null,
        ];
    }

    /** @return array{labels: array<int, string>, income: array<int, float>, expense: array<int, float>, incomeTotal: float, expenseTotal: float} */
    private function dynamics(): array
    {
        [$from, $until] = $this->range();
        $all = $this->period === 'all';
        $monthly = $all || $from->diffInDays($until) > 92;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $labelFormat = $monthly ? 'm.Y' : 'd.m';
        $buckets = [];
        $cursor = $from->copy()->startOfDay();

        while (! $all && $cursor->lte($until)) {
            $key = $cursor->format($format);
            $buckets[$key] ??= ['label' => $cursor->format($labelFormat), 'income' => 0.0, 'expense' => 0.0];
            $cursor = $monthly ? $cursor->addMonth()->startOfMonth() : $cursor->addDay();
        }

        if ($this->includesSource('clinic')) {
            $paymentPeriod = $this->periodExpression('payments.payment_date', $monthly);
            $this->addPeriodTotals($buckets, PaymentSplit::query()
                ->join('payments', 'payments.id', '=', 'payment_splits.payment_id')
                ->whereNull('payments.deleted_at')
                ->where('payment_splits.currency', $this->currency)
                ->when(! $all, fn ($query) => $query->whereBetween('payments.payment_date', [$from, $until]))
                ->selectRaw("{$paymentPeriod} as period_key, SUM(payment_splits.amount) as total")
                ->groupByRaw($paymentPeriod)->get(), 'income', $all);

            $salesPeriod = $this->periodExpression('sold_at', $monthly);
            $this->addPeriodTotals($buckets, ProductSale::query()
                ->when(! $all, fn ($query) => $query->whereBetween('sold_at', [$from, $until]))->where('currency', $this->currency)
                ->selectRaw("{$salesPeriod} as period_key, SUM(total) as total")
                ->groupByRaw($salesPeriod)->get(), 'income', $all);

            $financePeriod = $this->periodExpression('transaction_date', $monthly);
            $this->addPeriodTotals($buckets, FinanceTransaction::query()
                ->when(! $all, fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))->where('type', 'income')->where('currency', $this->currency)
                ->selectRaw("{$financePeriod} as period_key, SUM(amount) as total")
                ->groupByRaw($financePeriod)->get(), 'income', $all);
        }

        if ($this->includesSource('partner')) {
            $partnerPaymentPeriod = $this->periodExpression('paid_at', $monthly);
            $this->addPeriodTotals($buckets, PartnerPatientPayment::query()
                ->when(! $all, fn ($query) => $query->whereBetween('paid_at', [$from, $until]))->where('currency', $this->currency)
                ->selectRaw("{$partnerPaymentPeriod} as period_key, SUM(amount) as total")
                ->groupByRaw($partnerPaymentPeriod)->get(), 'income', $all);

            $partnerFinancePeriod = $this->periodExpression('transacted_at', $monthly);
            $this->addPeriodTotals($buckets, PartnerFinanceTransaction::query()->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->when(! $all, fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))->where('currency', $this->currency)
                ->selectRaw("{$partnerFinancePeriod} as period_key, SUM(amount) as total")
                ->groupByRaw($partnerFinancePeriod)->get(), 'expense', $all);
        }

        $expenseExpression = match ($this->source) {
            'clinic' => 'COALESCE(clinic_cash_gel, amount)',
            'partner' => $this->currency === 'GEL' ? 'COALESCE(israeli_cash_gel, 0)' : '0',
            default => 'amount',
        };
        $financeExpensePeriod = $this->periodExpression('transaction_date', $monthly);
        $this->addPeriodTotals($buckets, FinanceTransaction::query()
            ->when(! $all, fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))->where('type', 'expense')->where('currency', $this->currency)
            ->selectRaw("{$financeExpensePeriod} as period_key, SUM({$expenseExpression}) as total")
            ->groupByRaw($financeExpensePeriod)->get(), 'expense', $all);

        if ($all && $buckets !== []) {
            // Fill chart gaps from aggregate keys; never fetch raw transactions or MIN/MAX dates.
            ksort($buckets);
            $cursor = Carbon::createFromFormat('!Y-m', array_key_first($buckets));
            $last = array_key_last($buckets);
            while ($cursor->format('Y-m') <= $last) {
                $buckets[$cursor->format('Y-m')] ??= ['label' => $cursor->format('m.Y'), 'income' => 0.0, 'expense' => 0.0];
                $cursor->addMonth();
            }
            ksort($buckets);
        }

        return [
            'labels' => array_column($buckets, 'label'),
            'income' => array_column($buckets, 'income'),
            'expense' => array_column($buckets, 'expense'),
            'incomeTotal' => round((float) collect($buckets)->sum('income'), 2),
            'expenseTotal' => round((float) collect($buckets)->sum('expense'), 2),
        ];
    }

    /** @return array{totalPatients: int, totalRevenue: float, categories: array, doctors: array, details: array} */
    private function doctorStatistics(): array
    {
        [$from, $until] = $this->range();
        $includesIsraeliLab = $this->includesSource('partner');
        $israeliGroupId = $includesIsraeliLab ? PatientGroup::israelPartnerId() : null;
        $labStatistics = $this->israeliOrthopedicStatistics($from, $until);
        $serviceStatistics = $this->serviceStatistics($from, $until, $israeliGroupId);
        $treatmentGroups = $this->mergeIsraeliLabTreatmentGroups($serviceStatistics['groups'], $labStatistics['materials']);
        $consultationStatistics = $this->consultationStatistics($from, $until);
        $consultationStatistics['notStartedPatients'] = $this->showNotStartedPatients
            ? $this->notStartedConsultationPatients($from, $until)
            : [];

        $visits = Visit::query()->with(['doctor', 'treatmentCaseItems.treatmentCase'])
            ->join('patients as statistics_patients', 'statistics_patients.id', '=', 'visits.patient_id')
            ->select('visits.*')
            ->addSelect('statistics_patients.patient_group_id as statistics_patient_group_id')
            ->where('visits.currency', $this->currency)
            ->whereNotNull('visits.doctor_id')
            ->whereNotNull('visits.total_price')
            ->whereBetween('visits.visit_date', [$from, $until]);

        if ($this->source !== 'all') {
            $groupId = $this->source === 'partner' ? $israeliGroupId : PatientGroup::clinicId();
            $visits->where('statistics_patients.patient_group_id', $groupId);
        }

        $visits = $visits->get();
        $totalRevenue = 0.0;
        $categories = [];
        $details = [];
        $doctorRevenue = [];
        $eligiblePatients = [];
        $doctorEligiblePatients = [];

        foreach ($visits as $visit) {
            $items = $visit->treatmentCaseItems;
            $itemGross = (float) $items->sum(fn ($item): float => $item->manipulation_total);
            $visitRevenue = (float) ($visit->net_amount ?? 0);
            $buildDetail = $this->selectedDoctorId === (int) $visit->doctor_id;
            if ($buildDetail) {
                $details[$visit->doctor_id] ??= ['categories' => [], 'procedures' => []];
            }

            if ($items->isEmpty() && $visit->visit_type !== 'consultation') {
                $this->addDoctorCategory($categories, 'other', 'სხვა', $visit, 1, $visitRevenue);
                $totalRevenue = round($totalRevenue + $visitRevenue, 2);
                $doctorRevenue[$visit->doctor_id] = round(($doctorRevenue[$visit->doctor_id] ?? 0) + $visitRevenue, 2);
                $eligiblePatients[$visit->patient_id] = true;
                $doctorEligiblePatients[$visit->doctor_id][$visit->patient_id] = true;
                if ($buildDetail) {
                    $this->addDoctorCategory($details[$visit->doctor_id]['categories'], 'other', 'სხვა', $visit, 1, $visitRevenue);
                }
            }

            foreach ($items as $item) {
                $category = (string) ($item->treatmentCase?->category ?: 'other');
                if ($visit->visit_type === 'consultation' || in_array($category, ['consultation', 'tomography'], true)) {
                    continue;
                }

                $label = TreatmentCase::CATEGORIES[$category] ?? ($category === 'other' ? 'სხვა' : $category);
                $quantity = max(1, (int) $item->quantity);
                $revenue = $itemGross > 0 ? $visitRevenue * ($item->manipulation_total / $itemGross) : 0.0;
                $isIsraeliOrthopedics = $category === 'orthopedics'
                    && (int) $visit->statistics_patient_group_id === $israeliGroupId;
                $totalRevenue = round($totalRevenue + $revenue, 2);
                $doctorRevenue[$visit->doctor_id] = round(($doctorRevenue[$visit->doctor_id] ?? 0) + $revenue, 2);
                if (! $isIsraeliOrthopedics) {
                    $eligiblePatients[$visit->patient_id] = true;
                    $doctorEligiblePatients[$visit->doctor_id][$visit->patient_id] = true;
                }
                $this->addDoctorCategory($categories, $category, $label, $visit, $quantity, $revenue, ! $isIsraeliOrthopedics);
                if ($buildDetail) {
                    $this->addDoctorCategory($details[$visit->doctor_id]['categories'], $category, $label, $visit, $quantity, $revenue, ! $isIsraeliOrthopedics);
                    if (! $isIsraeliOrthopedics) {
                        $procedure = $item->display_name;
                        $details[$visit->doctor_id]['procedures'][$procedure] ??= ['name' => $procedure, 'count' => 0, 'revenue' => 0.0];
                        $details[$visit->doctor_id]['procedures'][$procedure]['count'] += $quantity;
                        $details[$visit->doctor_id]['procedures'][$procedure]['revenue'] = round($details[$visit->doctor_id]['procedures'][$procedure]['revenue'] + $revenue, 2);
                    }
                }
            }
        }

        if ($labStatistics['quantity'] > 0) {
            $categories['orthopedics'] ??= $this->emptyDoctorCategory('orthopedics');
            $categories['orthopedics']['procedures'] += $labStatistics['quantity'];
            $categories['orthopedics']['additional_patients'] += $labStatistics['patients'];
            $categories['orthopedics']['work_breakdown'] = $labStatistics['materials'];
        }

        if ($this->selectedDoctorId !== null && isset($labStatistics['doctors'][$this->selectedDoctorId])) {
            $doctorLab = $labStatistics['doctors'][$this->selectedDoctorId];
            $details[$this->selectedDoctorId] ??= ['categories' => [], 'procedures' => []];
            $details[$this->selectedDoctorId]['categories']['orthopedics'] ??= $this->emptyDoctorCategory('orthopedics');
            $details[$this->selectedDoctorId]['categories']['orthopedics']['procedures'] += $doctorLab['quantity'];
            $details[$this->selectedDoctorId]['categories']['orthopedics']['additional_patients'] += $doctorLab['patients'];
            $details[$this->selectedDoctorId]['categories']['orthopedics']['work_breakdown'] = $doctorLab['materials'];

            foreach ($doctorLab['materials'] as $material) {
                $details[$this->selectedDoctorId]['procedures']['israeli:'.$material['key']] = [
                    'name' => $material['label'],
                    'count' => $material['quantity'],
                    'revenue' => 0.0,
                    'quantity_only' => true,
                    'source' => 'israeli',
                ];
            }
        }

        $patientCounts = $this->doctorPatientCounts($from, $until, $israeliGroupId);

        $rows = $visits->groupBy('doctor_id')
            ->map(function ($doctorVisits) use ($totalRevenue, $labStatistics, $israeliGroupId, $patientCounts, $doctorRevenue, $doctorEligiblePatients): array {
                $doctorId = (int) $doctorVisits->first()->doctor_id;
                $revenue = (float) ($doctorRevenue[$doctorId] ?? 0);
                $visitProcedures = (int) $doctorVisits->sum(function (Visit $visit) use ($israeliGroupId): int {
                    if ($visit->visit_type === 'consultation') {
                        return 0;
                    }

                    if ($visit->treatmentCaseItems->isEmpty()) {
                        return 1;
                    }

                    return (int) $visit->treatmentCaseItems->sum(function ($item) use ($visit, $israeliGroupId): int {
                        $category = (string) ($item->treatmentCase?->category ?: 'other');
                        if (in_array($category, ['consultation', 'tomography'], true)) {
                            return 0;
                        }

                        $isIsraeliOrthopedics = $category === 'orthopedics'
                            && (int) $visit->statistics_patient_group_id === $israeliGroupId;

                        return $isIsraeliOrthopedics ? 0 : max(1, (int) $item->quantity);
                    });
                });
                $labQuantity = (int) ($labStatistics['doctors'][$doctorId]['quantity'] ?? 0);

                return [
                    'id' => $doctorId,
                    'name' => $doctorVisits->first()->doctor->full_name,
                    'revenue' => $revenue,
                    'procedures' => $visitProcedures + $labQuantity,
                    'patients' => (int) ($patientCounts['doctors'][$doctorId] ?? count($doctorEligiblePatients[$doctorId] ?? [])),
                    'percentage' => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 1) : 0.0,
                ];
            })->filter(fn (array $doctor): bool => $doctor['procedures'] > 0 || $doctor['revenue'] > 0.005);

        foreach ($labStatistics['doctors'] as $doctorId => $doctorLab) {
            if ($rows->contains('id', $doctorId)) {
                continue;
            }

            $rows->push([
                'id' => $doctorId,
                'name' => $doctorLab['name'],
                'revenue' => 0.0,
                'procedures' => $doctorLab['quantity'],
                'patients' => (int) ($patientCounts['doctors'][$doctorId] ?? $doctorLab['patients']),
                'percentage' => 0.0,
            ]);
        }
        $rows = $rows->sortByDesc('revenue')->values();

        foreach ($details as &$doctorDetail) {
            $doctorDetail['categories'] = $this->finalizeDoctorCategories($doctorDetail['categories'], (float) collect($doctorDetail['categories'])->sum('revenue'));
            $doctorDetail['procedures'] = collect($doctorDetail['procedures'] ?? [])->sortByDesc('revenue')->values()->all();
        }
        unset($doctorDetail);

        if ($this->selectedDoctorId !== null && isset($details[$this->selectedDoctorId])) {
            $details[$this->selectedDoctorId]['dynamics'] = $this->doctorDynamics($this->selectedDoctorId);
        }

        return [
            'totalPatients' => $patientCounts['total'] ?? count($eligiblePatients),
            'totalRevenue' => $totalRevenue,
            'categories' => $this->finalizeDoctorCategories($categories, $totalRevenue),
            'doctors' => $rows->all(),
            'details' => $details,
            'treatmentGroups' => $treatmentGroups,
            'treatmentHierarchy' => $this->treatmentGroupHierarchy($treatmentGroups, $serviceStatistics['categoryTotals'], $labStatistics),
            'consultations' => $consultationStatistics,
            'tomography' => $serviceStatistics['tomography'],
        ];
    }

    /** @return array{labels: array<int, string>, series: array<int, array{label: string, data: array<int, float>, color: string, backgroundColor: string}>, hasData: bool} */
    private function doctorDynamics(int $doctorId): array
    {
        [$from, $until] = $this->range();
        $monthly = $from->diffInDays($until) > 92;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $labelFormat = $monthly ? 'm.Y' : 'd.m';
        $currencies = $this->doctorDynamicsCurrency === 'both'
            ? array_keys(Currency::OPTIONS)
            : [$this->doctorDynamicsCurrency];
        $buckets = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($until)) {
            $key = $cursor->format($format);
            $buckets[$key] ??= [
                'label' => $cursor->format($labelFormat),
                ...array_fill_keys($currencies, 0.0),
            ];
            $cursor = $monthly ? $cursor->addMonth()->startOfMonth() : $cursor->addDay();
        }

        $periodExpression = $this->periodExpression('visit_date', $monthly);
        $visits = Visit::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('currency', $currencies)
            ->whereNotNull('total_price')
            ->whereBetween('visit_date', [$from, $until]);

        if ($this->source !== 'all') {
            $groupId = $this->source === 'partner' ? PatientGroup::israelPartnerId() : PatientGroup::clinicId();
            $visits->whereHas('patient', fn (Builder $query): Builder => $query->where('patient_group_id', $groupId));
        }

        foreach ($visits->selectRaw("{$periodExpression} as period_key, currency, SUM(COALESCE(total_price, 0) - COALESCE(discount_amount, 0)) as total")
            ->groupByRaw("{$periodExpression}, currency")->get() as $visit) {
            $key = (string) $visit->period_key;
            $visitCurrency = (string) $visit->currency;

            if (isset($buckets[$key][$visitCurrency])) {
                $buckets[$key][$visitCurrency] = round($buckets[$key][$visitCurrency] + (float) $visit->total, 2);
            }
        }

        $palette = [
            'GEL' => ['color' => '#34d399', 'backgroundColor' => 'rgba(52, 211, 153, 0.14)'],
            'USD' => ['color' => '#60a5fa', 'backgroundColor' => 'rgba(96, 165, 250, 0.12)'],
        ];
        $series = collect($currencies)->map(fn (string $currency): array => [
            'label' => 'შემოსავალი ('.$currency.')',
            'data' => array_map('floatval', array_column($buckets, $currency)),
            ...$palette[$currency],
        ])->values()->all();

        return [
            'labels' => array_column($buckets, 'label'),
            'series' => $series,
            'hasData' => collect($series)->sum(fn (array $item): float => (float) collect($item['data'])->sum()) > 0,
        ];
    }

    private function addDoctorCategory(array &$categories, string $key, string $label, Visit $visit, int $count, float $revenue, bool $includeActivity = true): void
    {
        $categories[$key] ??= ['label' => $label, 'patients' => [], 'additional_patients' => 0, 'procedures' => 0, 'revenue' => 0.0, 'work_breakdown' => []];
        if ($includeActivity) {
            $categories[$key]['patients'][$visit->patient_id] = true;
            $categories[$key]['procedures'] += $count;
        }
        $categories[$key]['revenue'] = round($categories[$key]['revenue'] + $revenue, 2);
    }

    private function finalizeDoctorCategories(array $categories, float $totalRevenue): array
    {
        return collect($categories)->map(function (array $row, string $key) use ($totalRevenue): array {
            $row['key'] = $key;
            $row['patients'] = count($row['patients']) + (int) ($row['additional_patients'] ?? 0);
            unset($row['additional_patients']);
            $row['percentage'] = $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 1) : 0.0;

            return $row;
        })->sortByDesc('revenue')->values()->all();
    }

    /** @return array{label: string, patients: array, additional_patients: int, procedures: int, revenue: float, work_breakdown: array} */
    private function emptyDoctorCategory(string $key): array
    {
        return [
            'label' => TreatmentCase::CATEGORIES[$key] ?? $key,
            'patients' => [],
            'additional_patients' => 0,
            'procedures' => 0,
            'revenue' => 0.0,
            'work_breakdown' => [],
        ];
    }

    /** @return array{quantity: int, patients: int, materials: array, doctors: array<int, array{name: string, quantity: int, patients: int, materials: array}>} */
    private function israeliOrthopedicStatistics(Carbon $from, Carbon $until): array
    {
        $empty = ['quantity' => 0, 'patients' => 0, 'materials' => [], 'doctors' => []];
        if (! $this->includesSource('partner')) {
            return $empty;
        }

        $base = DB::table('lab_main_works')
            ->join('lab_cases', 'lab_cases.id', '=', 'lab_main_works.lab_case_id')
            ->join('doctors', 'doctors.id', '=', 'lab_cases.doctor_id')
            ->where('lab_cases.source', 'israeli')
            ->whereNotNull('lab_cases.doctor_id')
            ->whereBetween('lab_cases.case_date', [$from, $until]);

        $materials = (clone $base)
            ->selectRaw("'material' as row_type, lab_cases.doctor_id, doctors.first_name, doctors.last_name, lab_main_works.material, SUM(lab_main_works.quantity) as quantity, COUNT(DISTINCT lab_cases.patient_id) as patients")
            ->groupBy('lab_cases.doctor_id', 'doctors.first_name', 'doctors.last_name', 'lab_main_works.material');
        $doctorTotals = (clone $base)
            ->selectRaw("'doctor' as row_type, lab_cases.doctor_id, doctors.first_name, doctors.last_name, NULL as material, SUM(lab_main_works.quantity) as quantity, COUNT(DISTINCT lab_cases.patient_id) as patients")
            ->groupBy('lab_cases.doctor_id', 'doctors.first_name', 'doctors.last_name');
        $globalMaterials = (clone $base)
            ->selectRaw("'global_material' as row_type, NULL as doctor_id, NULL as first_name, NULL as last_name, lab_main_works.material, SUM(lab_main_works.quantity) as quantity, COUNT(DISTINCT lab_cases.patient_id) as patients")
            ->groupBy('lab_main_works.material');
        $globalTotal = (clone $base)
            ->selectRaw("'global' as row_type, NULL as doctor_id, NULL as first_name, NULL as last_name, NULL as material, SUM(lab_main_works.quantity) as quantity, COUNT(DISTINCT lab_cases.patient_id) as patients");

        $rows = $materials->unionAll($doctorTotals)->unionAll($globalMaterials)->unionAll($globalTotal)->get();
        $statistics = $empty;

        foreach ($rows as $row) {
            if ($row->row_type === 'global') {
                $statistics['quantity'] = (int) $row->quantity;
                $statistics['patients'] = (int) $row->patients;

                continue;
            }

            if ($row->row_type === 'global_material') {
                $material = (string) $row->material;
                $statistics['materials'][] = [
                    'key' => $material,
                    'label' => $this->labMaterialLabel($material),
                    'quantity' => (int) $row->quantity,
                    'patients' => (int) $row->patients,
                    'source' => 'israeli',
                ];

                continue;
            }

            $doctorId = (int) $row->doctor_id;
            $statistics['doctors'][$doctorId] ??= [
                'name' => trim($row->first_name.' '.$row->last_name),
                'quantity' => 0,
                'patients' => 0,
                'materials' => [],
            ];

            if ($row->row_type === 'doctor') {
                $statistics['doctors'][$doctorId]['quantity'] = (int) $row->quantity;
                $statistics['doctors'][$doctorId]['patients'] = (int) $row->patients;

                continue;
            }

            $material = [
                'key' => (string) $row->material,
                'label' => $this->labMaterialLabel((string) $row->material),
                'quantity' => (int) $row->quantity,
                'source' => 'israeli',
            ];
            $statistics['doctors'][$doctorId]['materials'][] = $material;
        }

        $statistics['materials'] = collect($statistics['materials'])->sortByDesc('quantity')->values()->all();
        foreach ($statistics['doctors'] as &$doctor) {
            $doctor['materials'] = collect($doctor['materials'])->sortByDesc('quantity')->values()->all();
        }
        unset($doctor);

        return $statistics;
    }

    /** @return array{total: int, doctors: array<int, int>} */
    private function doctorPatientCounts(Carbon $from, Carbon $until, ?int $israeliGroupId): array
    {
        if (! $this->includesSource('partner')) {
            return [];
        }

        $visitBase = DB::table('visits as statistics_visits')
            ->join('patients as statistics_patients', 'statistics_patients.id', '=', 'statistics_visits.patient_id')
            ->whereNull('statistics_visits.cancelled_at')
            ->where('statistics_visits.currency', $this->currency)
            ->where('statistics_visits.visit_type', 'treatment')
            ->whereNotNull('statistics_visits.doctor_id')
            ->whereNotNull('statistics_visits.total_price')
            ->whereBetween('statistics_visits.visit_date', [$from, $until])
            ->when($this->source === 'partner', fn ($query) => $query
                ->where('statistics_patients.patient_group_id', $israeliGroupId));

        $visitPairs = (clone $visitBase)
            ->join('visit_treatment_cases as statistics_items', 'statistics_items.visit_id', '=', 'statistics_visits.id')
            ->leftJoin('treatment_cases as statistics_treatments', 'statistics_treatments.id', '=', 'statistics_items.treatment_case_id')
            ->where(function ($query): void {
                $query->whereNull('statistics_treatments.category')
                    ->orWhereNotIn('statistics_treatments.category', ['consultation', 'tomography']);
            })
            ->when($this->source === 'partner', fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('statistics_treatments.category')
                    ->orWhere('statistics_treatments.category', '!=', 'orthopedics')))
            ->when($this->source === 'all', fn ($query) => $query
                ->where(function ($query) use ($israeliGroupId): void {
                    $query->whereNull('statistics_patients.patient_group_id')
                        ->orWhere('statistics_patients.patient_group_id', '!=', $israeliGroupId)
                        ->orWhere(fn ($query) => $query
                            ->whereNull('statistics_treatments.category')
                            ->orWhere('statistics_treatments.category', '!=', 'orthopedics'));
                }))
            ->select('statistics_visits.doctor_id', 'statistics_visits.patient_id');
        $emptyTreatmentPairs = (clone $visitBase)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('visit_treatment_cases as empty_check_items')
                ->whereColumn('empty_check_items.visit_id', 'statistics_visits.id'))
            ->select('statistics_visits.doctor_id', 'statistics_visits.patient_id');
        $labPairs = DB::table('lab_cases')
            ->join('lab_main_works', 'lab_main_works.lab_case_id', '=', 'lab_cases.id')
            ->where('lab_cases.source', 'israeli')
            ->whereNotNull('lab_cases.doctor_id')
            ->whereBetween('lab_cases.case_date', [$from, $until])
            ->select('lab_cases.doctor_id', 'lab_cases.patient_id');
        $pairs = $visitPairs->union($emptyTreatmentPairs)->union($labPairs);

        $doctorCounts = DB::query()->fromSub(clone $pairs, 'doctor_patient_pairs')
            ->selectRaw("'doctor' as row_type, doctor_id, COUNT(DISTINCT patient_id) as patients")
            ->groupBy('doctor_id');
        $globalCount = DB::query()->fromSub(clone $pairs, 'doctor_patient_pairs')
            ->selectRaw("'global' as row_type, NULL as doctor_id, COUNT(DISTINCT patient_id) as patients");

        $rows = $doctorCounts->unionAll($globalCount)->get();

        return [
            'total' => (int) ($rows->firstWhere('row_type', 'global')?->patients ?? 0),
            'doctors' => $rows->where('row_type', 'doctor')->mapWithKeys(
                fn ($row): array => [(int) $row->doctor_id => (int) $row->patients],
            )->all(),
        ];
    }

    private function labMaterialLabel(string $material): string
    {
        return match ($material) {
            'zircon' => 'Zirconia crown',
            'pmma' => 'PMMA',
            default => LabCase::MATERIALS[$material] ?? $material,
        };
    }

    /** @return array{groups: array<int, array{key: string, label: string, patients: int, quantity: int, amount: float, breakdown?: array}>, categoryTotals: array<string, array{patients: int, quantity: int, amount: float}>, tomography: array{ct: array{patients: int, quantity: int}, panorama: array{patients: int, quantity: int}}} */
    private function serviceStatistics(Carbon $from, Carbon $until, ?int $israeliGroupId): array
    {
        $base = DB::table('visit_treatment_cases as analytics_items')
            ->join('visits as analytics_visits', 'analytics_visits.id', '=', 'analytics_items.visit_id')
            ->join('patients as analytics_patients', 'analytics_patients.id', '=', 'analytics_visits.patient_id')
            ->leftJoin('treatment_cases as analytics_treatments', 'analytics_treatments.id', '=', 'analytics_items.treatment_case_id')
            ->whereNull('analytics_visits.cancelled_at')
            ->where('analytics_visits.currency', $this->currency)
            ->whereBetween('analytics_visits.visit_date', [$from, $until]);

        if ($this->source !== 'all') {
            $groupId = $this->source === 'partner' ? $israeliGroupId : PatientGroup::clinicId();
            $base->where('analytics_patients.patient_group_id', $groupId);
        }

        $serviceNameExpression = "COALESCE(analytics_treatments.name, analytics_items.custom_service_name, '')";
        $groupExpression = "CASE WHEN analytics_treatments.id IS NULL THEN 'other' ELSE analytics_treatments.statistics_group END";
        $statisticsKeyExpression = "CASE WHEN analytics_treatments.id IS NULL THEN 'other' WHEN analytics_treatments.statistics_group IS NULL THEN {$serviceNameExpression} ELSE analytics_treatments.statistics_group END";
        $rowTypeExpression = "CASE WHEN analytics_treatments.id IS NOT NULL AND analytics_treatments.statistics_group IS NULL THEN 'direct' ELSE 'group' END";
        $categoryExpression = "CASE WHEN analytics_treatments.category IN ('therapy', 'surgery', 'orthopedics') THEN analytics_treatments.category ELSE 'other' END";
        $amountExpression = 'analytics_items.quantity * analytics_items.unit_price * CASE WHEN COALESCE(analytics_items.currency, analytics_visits.currency) = analytics_visits.currency THEN 1 ELSE COALESCE(analytics_items.exchange_rate, 0) END';
        $eligibleTreatments = (clone $base)
            ->where('analytics_visits.visit_type', 'treatment')
            ->where(function ($query): void {
                $query->whereNull('analytics_treatments.category')
                    ->orWhereNotIn('analytics_treatments.category', ['consultation', 'tomography']);
            })
            ->when($this->source === 'partner', fn ($query) => $query
                ->where(fn ($query) => $query->whereNull('analytics_treatments.category')
                    ->orWhere('analytics_treatments.category', '!=', 'orthopedics')))
            ->when($this->source === 'all', fn ($query) => $query
                ->where(function ($query) use ($israeliGroupId): void {
                    $query->whereNull('analytics_patients.patient_group_id')
                        ->orWhere('analytics_patients.patient_group_id', '!=', $israeliGroupId)
                        ->orWhere(fn ($query) => $query
                            ->whereNull('analytics_treatments.category')
                            ->orWhere('analytics_treatments.category', '!=', 'orthopedics'));
                }));
        $groups = (clone $eligibleTreatments)
            ->selectRaw("{$rowTypeExpression} as row_type, {$categoryExpression} as category_key, {$statisticsKeyExpression} as statistics_key, COUNT(DISTINCT analytics_visits.patient_id) as patients, SUM(analytics_items.quantity) as quantity, SUM({$amountExpression}) as amount")
            ->groupByRaw("{$rowTypeExpression}, {$categoryExpression}, {$statisticsKeyExpression}");
        $categoryTotals = (clone $eligibleTreatments)
            ->selectRaw("'category' as row_type, {$categoryExpression} as category_key, {$categoryExpression} as statistics_key, COUNT(DISTINCT analytics_visits.patient_id) as patients, SUM(analytics_items.quantity) as quantity, SUM({$amountExpression}) as amount")
            ->groupByRaw($categoryExpression);

        $implantBrands = (clone $eligibleTreatments)
            ->whereRaw("{$groupExpression} = 'implantation'")
            ->selectRaw("'implant_brand' as row_type, 'surgery' as category_key, {$serviceNameExpression} as statistics_key, 0 as patients, SUM(analytics_items.quantity) as quantity, 0 as amount")
            ->groupByRaw($serviceNameExpression);

        $tomographyKind = "CASE WHEN LOWER(COALESCE(analytics_treatments.name, analytics_items.custom_service_name, '')) LIKE '%panoram%' OR LOWER(COALESCE(analytics_treatments.name, analytics_items.custom_service_name, '')) LIKE '%პანორამ%' THEN 'panorama' ELSE 'ct' END";
        $tomography = (clone $base)
            ->where('analytics_treatments.category', 'tomography')
            ->selectRaw("'tomography' as row_type, 'tomography' as category_key, {$tomographyKind} as statistics_key, COUNT(DISTINCT analytics_visits.patient_id) as patients, SUM(analytics_items.quantity) as quantity, 0 as amount")
            ->groupByRaw($tomographyKind);

        $rows = $groups->unionAll($categoryTotals)->unionAll($tomography)->unionAll($implantBrands)->get();
        $result = [
            'groups' => [],
            'categoryTotals' => [],
            'implantBrands' => [],
            'tomography' => [
                'ct' => ['patients' => 0, 'quantity' => 0],
                'panorama' => ['patients' => 0, 'quantity' => 0],
            ],
        ];

        foreach ($rows as $row) {
            $key = (string) $row->statistics_key;
            if ($row->row_type === 'implant_brand') {
                $brand = $this->implantBrandLabel($key);
                $brandKey = mb_strtolower($brand);
                $result['implantBrands'][$brandKey] ??= ['label' => $brand, 'quantity' => 0];
                $result['implantBrands'][$brandKey]['quantity'] += (int) $row->quantity;

                continue;
            }

            if ($row->row_type === 'category') {
                $result['categoryTotals'][(string) $row->category_key] = [
                    'patients' => (int) $row->patients,
                    'quantity' => (int) $row->quantity,
                    'amount' => round((float) $row->amount, 2),
                ];

                continue;
            }

            if ($row->row_type === 'tomography') {
                $result['tomography'][$key] = [
                    'patients' => (int) $row->patients,
                    'quantity' => (int) $row->quantity,
                ];

                continue;
            }

            $result['groups'][] = [
                'key' => $row->row_type === 'direct' ? 'direct:'.sha1($key) : $key,
                'label' => $row->row_type === 'direct' ? $key : (TreatmentCase::STATISTICS_GROUPS[$key] ?? TreatmentCase::STATISTICS_GROUPS['other']),
                'category' => (string) $row->category_key,
                'direct' => $row->row_type === 'direct',
                'patients' => (int) $row->patients,
                'quantity' => (int) $row->quantity,
                'amount' => round((float) $row->amount, 2),
            ];
        }

        $implantBrands = collect($result['implantBrands'])->sortByDesc('quantity')->values()->all();
        foreach ($result['groups'] as &$group) {
            if ($group['key'] === 'implantation') {
                $group['breakdown'] = $implantBrands;
            }
        }
        unset($group, $result['implantBrands']);

        $result['groups'] = collect($result['groups'])->sortByDesc('amount')->values()->all();

        return $result;
    }

    private function implantBrandLabel(string $serviceName): string
    {
        $brand = preg_replace('/^.*?(?:იმპლანტაცია|implantation)\s*(?:[-–—:]\s*)?/iu', '', trim($serviceName));

        return filled($brand) ? trim($brand) : 'ბრენდი უცნობია';
    }

    private function mergeIsraeliLabTreatmentGroups(array $groups, array $materials): array
    {
        foreach ($materials as $material) {
            $key = match ($material['key']) {
                'zircon' => 'zircon',
                'pmma' => 'pmma',
                default => 'other',
            };
            $index = collect($groups)->search(fn (array $group): bool => $group['category'] === 'orthopedics' && $group['key'] === $key);
            if ($index === false) {
                $groups[] = [
                    'key' => $key,
                    'label' => TreatmentCase::STATISTICS_GROUPS[$key] ?? TreatmentCase::STATISTICS_GROUPS['other'],
                    'category' => 'orthopedics',
                    'patients' => 0,
                    'quantity' => 0,
                    'amount' => 0.0,
                ];
                $index = array_key_last($groups);
            }

            $groups[$index]['patients'] += (int) ($material['patients'] ?? 0);
            $groups[$index]['quantity'] += (int) $material['quantity'];
            $groups[$index]['israeliQuantity'] = (int) ($groups[$index]['israeliQuantity'] ?? 0) + (int) $material['quantity'];
        }

        return $groups;
    }

    private function treatmentGroupHierarchy(array $groups, array $categoryTotals, array $labStatistics): array
    {
        $categoryOrder = ['therapy', 'surgery', 'orthopedics', 'other'];
        $groupOrder = [
            'filling', 'cleaning', 'endodontics', 'whitening', 'medication',
            'implantation', 'extraction', 'sinus_lift', 'augmentation',
            'zircon', 'pmma', 'prosthesis', 'other',
        ];

        return collect($groups)
            ->groupBy('category')
            ->map(function ($categoryGroups, string $category) use ($categoryTotals, $groupOrder, $labStatistics): array {
                $groups = $categoryGroups->sortBy(function (array $group) use ($groupOrder): int {
                    $position = array_search($group['key'], $groupOrder, true);

                    return $position === false ? PHP_INT_MAX : $position;
                })->values();
                $totals = $categoryTotals[$category] ?? ['patients' => 0, 'quantity' => 0, 'amount' => 0.0];
                if ($category === 'orthopedics' && $labStatistics['quantity'] > 0) {
                    $totals['patients'] += (int) $labStatistics['patients'];
                }

                return [
                    'key' => $category,
                    'label' => TreatmentCase::CATEGORIES[$category] ?? TreatmentCase::STATISTICS_GROUPS['other'],
                    'patients' => (int) $totals['patients'],
                    'quantity' => (int) $groups->sum('quantity'),
                    'amount' => round((float) $groups->sum('amount'), 2),
                    'groups' => $groups->all(),
                ];
            })
            ->sortBy(fn (array $category): int => (int) array_search($category['key'], $categoryOrder, true))
            ->values()
            ->all();
    }

    /** @return array{total: int, started: int, pending: int, notStarted: int, conversion: float} */
    private function consultationStatistics(Carbon $from, Carbon $until): array
    {
        $consultationPatients = $this->consultationClassifications($from, $until);
        $totals = DB::query()->fromSub($consultationPatients, 'consultation_conversion')
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN consultation_status = 'started' THEN 1 ELSE 0 END), 0) as started, COALESCE(SUM(CASE WHEN consultation_status = 'pending' THEN 1 ELSE 0 END), 0) as pending, COALESCE(SUM(CASE WHEN consultation_status = 'not_started' THEN 1 ELSE 0 END), 0) as not_started")
            ->first();
        $total = (int) ($totals->total ?? 0);
        $started = (int) ($totals->started ?? 0);
        $pending = (int) ($totals->pending ?? 0);
        $notStarted = (int) ($totals->not_started ?? 0);
        $matured = $started + $notStarted;

        return [
            'total' => $total,
            'started' => $started,
            'pending' => $pending,
            'notStarted' => $notStarted,
            'conversion' => $matured > 0 ? round($started / $matured * 100, 1) : 0.0,
        ];
    }

    private function consultationClassifications(Carbon $from, Carbon $until)
    {
        $cohorts = $this->consultationCohorts($from, $until);
        $realTreatmentExists = DB::table('visits as conversion_treatments')
            ->selectRaw('1')
            ->whereColumn('conversion_treatments.patient_id', 'consultation_cohorts.patient_id')
            ->whereNull('conversion_treatments.cancelled_at')
            ->where('conversion_treatments.visit_type', 'treatment')
            ->where(function ($query): void {
                $query->whereColumn('conversion_treatments.visit_date', '>', 'consultation_cohorts.consultation_date')
                    ->orWhere(function ($query): void {
                        $query->whereColumn('conversion_treatments.visit_date', 'consultation_cohorts.consultation_date')
                            ->whereColumn('conversion_treatments.id', '>', 'consultation_cohorts.consultation_id');
                    });
            })
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('visit_treatment_cases as conversion_items')
                ->leftJoin('treatment_cases as conversion_services', 'conversion_services.id', '=', 'conversion_items.treatment_case_id')
                ->whereColumn('conversion_items.visit_id', 'conversion_treatments.id')
                ->where(fn ($query) => $query->whereNull('conversion_services.category')
                    ->orWhereNotIn('conversion_services.category', ['consultation', 'tomography'])));

        return DB::query()->fromSub($cohorts, 'consultation_cohorts')
            ->select('consultation_cohorts.*')
            ->selectRaw(
                "CASE WHEN EXISTS ({$realTreatmentExists->toSql()}) THEN 'started' WHEN consultation_cohorts.consultation_date <= ? THEN 'not_started' ELSE 'pending' END as consultation_status",
                [...$realTreatmentExists->getBindings(), today()->subDays(7)->endOfDay()],
            );
    }

    private function consultationCohorts(Carbon $from, Carbon $until)
    {
        $firstDates = DB::table('visits as consultations')
            ->join('patients as consultation_patients', 'consultation_patients.id', '=', 'consultations.patient_id')
            ->whereNull('consultations.cancelled_at')
            ->where('consultations.visit_type', 'consultation')
            ->where('consultations.currency', $this->currency)
            ->whereBetween('consultations.visit_date', [$from, $until]);

        if ($this->source !== 'all') {
            $groupId = $this->source === 'partner' ? PatientGroup::israelPartnerId() : PatientGroup::clinicId();
            $firstDates->where('consultation_patients.patient_group_id', $groupId);
        }

        $firstDates = $firstDates
            ->select('consultations.patient_id')
            ->selectRaw('MIN(consultations.visit_date) as consultation_date')
            ->groupBy('consultations.patient_id');

        return DB::query()->fromSub($firstDates, 'consultation_dates')
            ->join('visits as anchor_consultations', function ($join): void {
                $join->on('anchor_consultations.patient_id', '=', 'consultation_dates.patient_id')
                    ->on('anchor_consultations.visit_date', '=', 'consultation_dates.consultation_date');
            })
            ->whereNull('anchor_consultations.cancelled_at')
            ->where('anchor_consultations.visit_type', 'consultation')
            ->select('consultation_dates.patient_id', 'consultation_dates.consultation_date')
            ->selectRaw('MIN(anchor_consultations.id) as consultation_id')
            ->groupBy('consultation_dates.patient_id', 'consultation_dates.consultation_date');
    }

    /** @return array<int, array{id: int, patient: string, phone: string, consultationDate: string, doctor: string, days: int}> */
    private function notStartedConsultationPatients(Carbon $from, Carbon $until): array
    {
        $classified = $this->consultationClassifications($from, $until);
        $daysSql = match (DB::connection()->getDriverName()) {
            'pgsql' => 'CAST(? AS date) - CAST(classified_consultations.consultation_date AS date)',
            'mysql' => 'DATEDIFF(?, classified_consultations.consultation_date)',
            default => 'CAST(julianday(?) - julianday(classified_consultations.consultation_date) AS INTEGER)',
        };

        return DB::query()->fromSub($classified, 'classified_consultations')
            ->join('patients as consultation_detail_patients', 'consultation_detail_patients.id', '=', 'classified_consultations.patient_id')
            ->join('visits as consultation_detail_visits', 'consultation_detail_visits.id', '=', 'classified_consultations.consultation_id')
            ->leftJoin('doctors as consultation_detail_doctors', 'consultation_detail_doctors.id', '=', 'consultation_detail_visits.doctor_id')
            ->where('classified_consultations.consultation_status', 'not_started')
            ->select([
                'classified_consultations.patient_id',
                'classified_consultations.consultation_date',
                'consultation_detail_patients.first_name',
                'consultation_detail_patients.last_name',
                'consultation_detail_patients.phone',
                'consultation_detail_doctors.first_name as doctor_first_name',
                'consultation_detail_doctors.last_name as doctor_last_name',
            ])
            ->selectRaw("{$daysSql} as days_since_consultation", [today()->toDateString()])
            ->orderBy('classified_consultations.consultation_date')
            ->get()
            ->map(fn ($row): array => [
                'id' => (int) $row->patient_id,
                'patient' => trim($row->first_name.' '.$row->last_name),
                'phone' => filled($row->phone) ? $row->phone : '—',
                'consultationDate' => Carbon::parse($row->consultation_date)->format('d.m.Y'),
                'doctor' => trim(($row->doctor_first_name ?? '').' '.($row->doctor_last_name ?? '')) ?: '—',
                'days' => (int) $row->days_since_consultation,
            ])
            ->all();
    }

    private function addPeriodTotals(array &$buckets, iterable $rows, string $type, bool $discoverMonths = false): void
    {
        foreach ($rows as $row) {
            $key = (string) $row->period_key;

            if ($discoverMonths && $key !== '') {
                $buckets[$key] ??= ['label' => Carbon::createFromFormat('!Y-m', $key)->format('m.Y'), 'income' => 0.0, 'expense' => 0.0];
            }

            if (isset($buckets[$key])) {
                $buckets[$key][$type] = round($buckets[$key][$type] + (float) $row->total, 2);
            }
        }
    }

    private function periodExpression(string $column, bool $monthly): string
    {
        $format = $monthly ? 'YYYY-MM' : 'YYYY-MM-DD';

        return match (DB::connection()->getDriverName()) {
            'pgsql' => "TO_CHAR({$column}, '{$format}')",
            'sqlite' => "strftime('".($monthly ? '%Y-%m' : '%Y-%m-%d')."', {$column})",
            default => "DATE_FORMAT({$column}, '".($monthly ? '%Y-%m' : '%Y-%m-%d')."')",
        };
    }

    /** @return array<string, array<int, string>> */
    private function breakdownDescriptions(): array
    {
        $descriptions = [];
        [$from, $until] = $this->range();

        if ($this->reportTab === 'income') {
            if ($this->includesSource('clinic')) {
                Payment::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('payment_date', [$this->dateFrom, $this->dateUntil]))
                    ->whereHas('splits', fn (Builder $query): Builder => $query->where('currency', $this->currency))
                    ->pluck('comment')->each(function ($text) use (&$descriptions): void {
                        $this->addDescription($descriptions, 'patient_payments', $text);
                    });
                ProductSale::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('sold_at', [$from, $until]))->where('currency', $this->currency)
                    ->pluck('note')->each(function ($text) use (&$descriptions): void {
                        $this->addDescription($descriptions, 'product_sales', $text);
                    });
                FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                    ->where('type', 'income')->where('currency', $this->currency)->get()
                    ->each(function (FinanceTransaction $item) use (&$descriptions): void {
                        $this->addDescription($descriptions, (string) $item->category, $item->description ?: $item->note);
                    });
            }

            if ($this->includesSource('partner')) {
                PartnerPatientPayment::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('paid_at', [$from, $until]))->where('currency', $this->currency)
                    ->pluck('notes')->each(function ($text) use (&$descriptions): void {
                        $this->addDescription($descriptions, 'patient_payments', $text);
                    });
            }

            return $descriptions;
        }

        $financeQuery = FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
            ->where('type', 'expense')->where('currency', $this->currency);
        if ($this->source === 'clinic') {
            $financeQuery->where(fn (Builder $query): Builder => $query
                ->whereNull('clinic_cash_gel')->orWhere('clinic_cash_gel', '>', 0));
        } elseif ($this->source === 'partner') {
            $financeQuery->where('israeli_cash_gel', '>', 0);
        }
        if ($this->reportTab === 'cash_out' && $this->source !== 'partner') {
            $financeQuery->where('payment_method', 'cash')->where('cash_source', 'current_cashier');
        }
        $financeQuery->get()->each(function (FinanceTransaction $item) use (&$descriptions): void {
            $this->addDescription(
                $descriptions,
                (string) $item->category,
                $item->description ?: $item->note,
            );
        });

        if ($this->includesSource('partner')) {
            $partnerQuery = PartnerFinanceTransaction::query()->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))->where('currency', $this->currency);
            if ($this->reportTab === 'cash_out') {
                $partnerQuery->where('from_account', 'cash');
            }
            $partnerQuery->get()->each(function (PartnerFinanceTransaction $item) use (&$descriptions): void {
                $this->addDescription(
                    $descriptions,
                    (string) $item->category,
                    $item->notes ?: $item->recipient,
                );
            });
        }

        if ($this->reportTab === 'cash_out') {
            $sources = $this->source === 'all'
                ? [PartnerFinanceTransaction::SOURCE_CLINIC, PartnerFinanceTransaction::SOURCE_ISRAELI]
                : [$this->source === 'partner' ? PartnerFinanceTransaction::SOURCE_ISRAELI : PartnerFinanceTransaction::SOURCE_CLINIC];
            PartnerFinanceTransaction::query()->whereIn('source', $sources)->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))
                ->where('from_account', 'cash')->whereIn('type', [
                    PartnerFinanceTransaction::TYPE_EXCHANGE,
                    PartnerFinanceTransaction::TYPE_TRANSFER,
                    PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
                ])->get()->each(function (PartnerFinanceTransaction $item) use (&$descriptions): void {
                    if ($item->type === PartnerFinanceTransaction::TYPE_EXCHANGE && $item->from_currency !== $this->currency) {
                        return;
                    }
                    if ($item->type !== PartnerFinanceTransaction::TYPE_EXCHANGE && $item->currency !== $this->currency) {
                        return;
                    }
                    $key = $item->type === PartnerFinanceTransaction::TYPE_EXCHANGE
                        ? 'currency_exchange'
                        : 'movement_'.$item->type.'_'.$item->category;
                    $this->addDescription($descriptions, $key, $item->notes ?: $item->recipient);
                });
        }

        return $descriptions;
    }

    /** @param array<string, array<int, string>> $descriptions */
    private function addDescription(array &$descriptions, string $key, mixed $description): void
    {
        $description = trim((string) $description);
        if ($description === '' || in_array($description, $descriptions[$key] ?? [], true)) {
            return;
        }

        $descriptions[$key][] = $description;
    }

    /** @return array<string, array<int, array{name: string, amount: float}>> */
    private function breakdownDetails(): array
    {
        if ($this->reportTab === 'income') {
            return [];
        }

        $details = [];
        [$from, $until] = $this->range();
        $categories = ['lab_salary', 'doctor_salary', 'salary'];

        $financeQuery = FinanceTransaction::query()
            ->with(['labSalarySettlement.technician', 'employeeSalarySettlement.employee', 'salarySettlement.doctor'])
            ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
            ->where('type', 'expense')
            ->where('currency', $this->currency)
            ->whereIn('category', $categories);

        if ($this->reportTab === 'cash_out' && $this->source !== 'partner') {
            $financeQuery->where('payment_method', 'cash')->where('cash_source', 'current_cashier');
        }

        $financeQuery->get()->each(function (FinanceTransaction $transaction) use (&$details): void {
            $amount = match ($this->source) {
                'clinic' => $transaction->clinic_cash_gel === null ? (float) $transaction->amount : (float) $transaction->clinic_cash_gel,
                'partner' => (float) ($transaction->israeli_cash_gel ?? 0),
                default => (float) $transaction->amount,
            };
            $name = $transaction->labSalarySettlement?->technician?->name
                ?? $transaction->employeeSalarySettlement?->employee?->full_name
                ?? $transaction->salarySettlement?->doctor?->full_name;

            $this->addDetail($details, (string) $transaction->category, $name, $amount);
        });

        if ($this->includesSource('partner')) {
            $partnerQuery = PartnerFinanceTransaction::query()
                ->with(['labSalarySettlement.technician', 'salarySettlement.doctor', 'doctor'])
                ->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))
                ->where('currency', $this->currency)
                ->whereIn('category', $categories);

            if ($this->reportTab === 'cash_out') {
                $partnerQuery->where('from_account', 'cash');
            }

            $partnerQuery->get()->each(function (PartnerFinanceTransaction $transaction) use (&$details): void {
                $name = $transaction->labSalarySettlement?->technician?->name
                    ?? $transaction->doctor?->full_name
                    ?? $transaction->salarySettlement?->doctor?->full_name;

                $this->addDetail($details, (string) $transaction->category, $name, (float) $transaction->amount);
            });
        }

        return collect($details)->map(fn (array $items): array => collect($items)
            ->sortByDesc('amount')->values()->all())->all();
    }

    /** @param array<string, array<string, array{name: string, amount: float}>> $details */
    private function addDetail(array &$details, string $category, ?string $name, float $amount): void
    {
        if (blank($name) || $amount <= 0.005) {
            return;
        }

        $details[$category][$name] ??= ['name' => $name, 'amount' => 0.0];
        $details[$category][$name]['amount'] = round($details[$category][$name]['amount'] + $amount, 2);
    }

    /** @return array<int, array{key: string, label: string, amount: float, count: int}> */
    private function breakdown(string $tab): array
    {
        return match ($tab) {
            'expense' => $this->expenseBreakdown(),
            'cash_out' => $this->cashOutBreakdown(),
            default => $this->incomeBreakdown(),
        };
    }

    /** @return array<int, array{key: string, label: string, amount: float, count: int}> */
    private function incomeBreakdown(): array
    {
        $rows = [];
        [$from, $until] = $this->range();

        if ($this->includesSource('clinic')) {
            $paymentQuery = PaymentSplit::query()->where('currency', $this->currency)
                ->whereHas('payment', fn (Builder $query): Builder => $query
                    ->when($this->restrictReportDates(), fn ($query) => $query->whereDate('payment_date', '>=', $this->dateFrom))
                    ->when($this->restrictReportDates(), fn ($query) => $query->whereDate('payment_date', '<=', $this->dateUntil)));
            $paymentTotals = $paymentQuery->selectRaw('COALESCE(SUM(amount), 0) as aggregate_amount, COUNT(DISTINCT payment_id) as aggregate_count')->first();
            $this->add($rows, 'patient_payments', 'პაციენტის გადახდები', (float) $paymentTotals->aggregate_amount, (int) $paymentTotals->aggregate_count);

            $sales = ProductSale::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('sold_at', [$from, $until]))->where('currency', $this->currency);
            $saleTotals = $sales->selectRaw('COALESCE(SUM(total), 0) as aggregate_amount, COUNT(*) as aggregate_count')->first();
            $this->add($rows, 'product_sales', 'პროდუქტის გაყიდვა', (float) $saleTotals->aggregate_amount, (int) $saleTotals->aggregate_count);

            $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                ->where('type', 'income')->where('currency', $this->currency)
                ->selectRaw('category, SUM(amount) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());
        }

        if ($this->includesSource('partner')) {
            $payments = PartnerPatientPayment::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('paid_at', [$from, $until]))->where('currency', $this->currency);
            $paymentTotals = $payments->selectRaw('COALESCE(SUM(amount), 0) as aggregate_amount, COUNT(*) as aggregate_count')->first();
            $this->add($rows, 'patient_payments', 'პაციენტის გადახდები', (float) $paymentTotals->aggregate_amount, (int) $paymentTotals->aggregate_count);
        }

        return array_values($rows);
    }

    /** @return array<int, array{key: string, label: string, amount: float, count: int}> */
    private function expenseBreakdown(): array
    {
        $rows = [];
        [$from, $until] = $this->range();

        if ($this->source === 'all') {
            $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                ->where('type', 'expense')->where('currency', $this->currency)
                ->selectRaw('category, SUM(amount) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());
        } elseif ($this->source === 'clinic') {
            $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                ->where('type', 'expense')->where('currency', $this->currency)
                ->whereRaw('COALESCE(clinic_cash_gel, amount) > 0.005')
                ->selectRaw('category, SUM(COALESCE(clinic_cash_gel, amount)) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());
        }

        if ($this->includesSource('partner')) {
            $this->addCategoryTotals($rows, PartnerFinanceTransaction::query()->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))->where('currency', $this->currency)
                ->selectRaw('category, SUM(amount) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());

            if ($this->source === 'partner' && $this->currency === 'GEL') {
                $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                    ->where('type', 'expense')->where('israeli_cash_gel', '>', 0)
                    ->selectRaw('category, SUM(israeli_cash_gel) as aggregate_amount, COUNT(*) as aggregate_count')
                    ->groupBy('category')->get());
            }
        }

        return array_values($rows);
    }

    /** @return array<int, array{key: string, label: string, amount: float, count: int}> */
    private function cashOutBreakdown(): array
    {
        $rows = [];
        [$from, $until] = $this->range();

        if ($this->source === 'all') {
            $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                ->where('type', 'expense')->where('currency', $this->currency)
                ->where('payment_method', 'cash')->where('cash_source', 'current_cashier')
                ->selectRaw('category, SUM(amount) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());
            $this->addCashMovements($rows, PartnerFinanceTransaction::SOURCE_CLINIC, $from, $until);
        } elseif ($this->source === 'clinic') {
            $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                ->where('type', 'expense')->where('currency', $this->currency)
                ->where('payment_method', 'cash')->where('cash_source', 'current_cashier')
                ->whereRaw('COALESCE(clinic_cash_gel, amount) > 0.005')
                ->selectRaw('category, SUM(COALESCE(clinic_cash_gel, amount)) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());
            $this->addCashMovements($rows, PartnerFinanceTransaction::SOURCE_CLINIC, $from, $until);
        }

        if ($this->includesSource('partner')) {
            $this->addCategoryTotals($rows, PartnerFinanceTransaction::query()->israeli()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->where('from_account', 'cash')->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))
                ->where('currency', $this->currency)
                ->selectRaw('category, SUM(amount) as aggregate_amount, COUNT(*) as aggregate_count')
                ->groupBy('category')->get());

            if ($this->source === 'partner' && $this->currency === 'GEL') {
                $this->addCategoryTotals($rows, FinanceTransaction::query()->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transaction_date', [$from, $until]))
                    ->where('type', 'expense')->where('israeli_cash_gel', '>', 0)
                    ->selectRaw('category, SUM(israeli_cash_gel) as aggregate_amount, COUNT(*) as aggregate_count')
                    ->groupBy('category')->get());
            }
            $this->addCashMovements($rows, PartnerFinanceTransaction::SOURCE_ISRAELI, $from, $until);
        }

        return array_values($rows);
    }

    /** @param array<string, array{key: string, label: string, amount: float, count: int}> $rows */
    private function addCashMovements(array &$rows, string $source, Carbon $from, Carbon $until): void
    {
        PartnerFinanceTransaction::query()->where('source', $source)->when($this->restrictReportDates(), fn ($query) => $query->whereBetween('transacted_at', [$from, $until]))
            ->where('from_account', 'cash')->whereIn('type', [
                PartnerFinanceTransaction::TYPE_EXCHANGE,
                PartnerFinanceTransaction::TYPE_TRANSFER,
                PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
            ])->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)
                        ->where('from_currency', $this->currency);
                })->orWhere(function (Builder $query): void {
                    $query->where('type', '!=', PartnerFinanceTransaction::TYPE_EXCHANGE)
                        ->where('currency', $this->currency);
                });
            })->selectRaw(
                'type, category, SUM(CASE WHEN type = ? THEN from_amount ELSE amount END) as aggregate_amount, COUNT(*) as aggregate_count',
                [PartnerFinanceTransaction::TYPE_EXCHANGE],
            )->groupBy('type', 'category')->get()->each(function (PartnerFinanceTransaction $item) use (&$rows): void {
                if ($item->type === PartnerFinanceTransaction::TYPE_EXCHANGE) {
                    $this->add($rows, 'currency_exchange', 'ვალუტის გაცვლა', (float) $item->aggregate_amount, (int) $item->aggregate_count);

                    return;
                }

                $label = $item->type === PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL
                    ? 'მფლობელის გატანა'
                    : (PartnerFinanceTransaction::TRANSFER_CATEGORIES[$item->category] ?? 'გადატანა');
                $this->add($rows, 'movement_'.$item->type.'_'.$item->category, $label, (float) $item->aggregate_amount, (int) $item->aggregate_count);
            });
    }

    private function addCategoryTotals(array &$rows, iterable $totals): void
    {
        foreach ($totals as $total) {
            $category = (string) $total->category;
            $this->add(
                $rows,
                $category,
                $this->categoryLabel($category),
                (float) $total->aggregate_amount,
                (int) $total->aggregate_count,
            );
        }
    }

    /** @param array<string, array{key: string, label: string, amount: float, count: int}> $rows */
    private function add(array &$rows, string $key, string $label, float $amount, int $count = 1): void
    {
        if ($amount <= 0.005) {
            return;
        }

        $rows[$key] ??= ['key' => $key, 'label' => $label, 'amount' => 0.0, 'count' => 0];
        $rows[$key]['amount'] = round($rows[$key]['amount'] + $amount, 2);
        $rows[$key]['count'] += $count;
    }

    private function categoryLabel(?string $category): string
    {
        return FinanceTransaction::CATEGORIES[$category] ?? ExpenseCategoryForm::label($category)
            ?? PartnerFinanceTransaction::EXPENSE_CATEGORIES[$category]
            ?? PartnerFinanceTransaction::TRANSFER_CATEGORIES[$category]
            ?? 'სხვა';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        return [
            Carbon::parse($this->dateFrom, config('app.timezone'))->startOfDay(),
            Carbon::parse($this->dateUntil, config('app.timezone'))->endOfDay(),
        ];
    }

    private function includesSource(string $source): bool
    {
        return $this->source === 'all' || $this->source === $source;
    }
}
