<?php

namespace App\Filament\Pages;

use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\ProductSale;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Support\Currency;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
        if (in_array($tab, ['finance', 'dynamics', 'doctors'], true)) {
            $this->sectionTab = $tab;

            if ($tab !== 'doctors') {
                $this->selectedDoctorId = null;
            }
        }
    }

    public function toggleDoctor(int $doctorId): void
    {
        $this->selectedDoctorId = $this->selectedDoctorId === $doctorId ? null : $doctorId;
    }

    public function updatedSource(): void
    {
        $this->selectedDoctorId = null;
    }

    public function updatedCurrency(): void
    {
        $this->selectedDoctorId = null;
    }

    public function updatedDateFrom(): void
    {
        parent::updatedDateFrom();
        $this->selectedDoctorId = null;
    }

    public function updatedDateUntil(): void
    {
        parent::updatedDateUntil();
        $this->selectedDoctorId = null;
    }

    protected function getViewData(): array
    {
        $this->historyMode = 'overview';

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
        $monthly = $from->diffInDays($until) > 92;
        $format = $monthly ? 'Y-m' : 'Y-m-d';
        $labelFormat = $monthly ? 'm.Y' : 'd.m';
        $buckets = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($until)) {
            $key = $cursor->format($format);
            $buckets[$key] ??= ['label' => $cursor->format($labelFormat), 'income' => 0.0, 'expense' => 0.0];
            $cursor = $monthly ? $cursor->addMonth()->startOfMonth() : $cursor->addDay();
        }

        $add = static function (array &$items, mixed $date, string $type, float $amount) use ($format): void {
            $key = Carbon::parse($date)->format($format);
            if (isset($items[$key])) {
                $items[$key][$type] = round($items[$key][$type] + $amount, 2);
            }
        };

        if ($this->includesSource('clinic')) {
            PaymentSplit::query()->with('payment')->where('currency', $this->currency)
                ->whereHas('payment', fn (Builder $query): Builder => $query->whereBetween('payment_date', [$this->dateFrom, $this->dateUntil]))
                ->get()->each(function (PaymentSplit $split) use (&$buckets, $add): void {
                    $add($buckets, $split->payment->payment_date, 'income', (float) $split->amount);
                });
            ProductSale::query()->whereBetween('sold_at', [$from, $until])->where('currency', $this->currency)->get()
                ->each(function (ProductSale $sale) use (&$buckets, $add): void {
                    $add($buckets, $sale->sold_at, 'income', (float) $sale->total);
                });
            FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])->where('type', 'income')->where('currency', $this->currency)->get()
                ->each(function (FinanceTransaction $item) use (&$buckets, $add): void {
                    $add($buckets, $item->transaction_date, 'income', (float) $item->amount);
                });
        }

        if ($this->includesSource('partner')) {
            PartnerPatientPayment::query()->whereBetween('paid_at', [$from, $until])->where('currency', $this->currency)->get()
                ->each(function (PartnerPatientPayment $item) use (&$buckets, $add): void {
                    $add($buckets, $item->paid_at, 'income', (float) $item->amount);
                });
            PartnerFinanceTransaction::query()->israeli()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->whereBetween('transacted_at', [$from, $until])->where('currency', $this->currency)->get()
                ->each(function (PartnerFinanceTransaction $item) use (&$buckets, $add): void {
                    $add($buckets, $item->transacted_at, 'expense', (float) $item->amount);
                });
        }

        FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])->where('type', 'expense')->where('currency', $this->currency)->get()
            ->each(function (FinanceTransaction $item) use (&$buckets, $add): void {
                $amount = match ($this->source) {
                    'clinic' => $item->clinic_cash_gel === null ? (float) $item->amount : (float) $item->clinic_cash_gel,
                    'partner' => $this->currency === 'GEL' ? (float) ($item->israeli_cash_gel ?? 0) : 0.0,
                    default => (float) $item->amount,
                };
                $add($buckets, $item->transaction_date, 'expense', $amount);
            });

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
        $visits = Visit::query()->with(['doctor', 'treatmentCaseItems.treatmentCase'])
            ->where('currency', $this->currency)
            ->whereNotNull('doctor_id')
            ->whereNotNull('total_price')
            ->whereDate('visit_date', '>=', $this->dateFrom)
            ->whereDate('visit_date', '<=', $this->dateUntil);

        if ($this->source !== 'all') {
            $groupId = $this->source === 'partner' ? PatientGroup::israelPartnerId() : PatientGroup::clinicId();
            $visits->whereHas('patient', fn (Builder $query): Builder => $query->where('patient_group_id', $groupId));
        }

        $visits = $visits->get();
        $totalRevenue = round((float) $visits->sum(fn (Visit $visit): float => (float) ($visit->net_amount ?? 0)), 2);
        $categories = [];
        $details = [];

        foreach ($visits as $visit) {
            $items = $visit->treatmentCaseItems;
            $itemGross = (float) $items->sum(fn ($item): float => $item->manipulation_total);
            $visitRevenue = (float) ($visit->net_amount ?? 0);
            $buildDetail = $this->selectedDoctorId === (int) $visit->doctor_id;
            if ($buildDetail) {
                $details[$visit->doctor_id] ??= ['categories' => [], 'procedures' => []];
            }

            if ($items->isEmpty()) {
                $this->addDoctorCategory($categories, 'other', 'სხვა', $visit, 1, $visitRevenue);
                if ($buildDetail) {
                    $this->addDoctorCategory($details[$visit->doctor_id]['categories'], 'other', 'სხვა', $visit, 1, $visitRevenue);
                }
            }

            foreach ($items as $item) {
                $category = (string) ($item->treatmentCase?->category ?: 'other');
                $label = TreatmentCase::CATEGORIES[$category] ?? ($category === 'other' ? 'სხვა' : $category);
                $quantity = max(1, (int) $item->quantity);
                $revenue = $itemGross > 0 ? $visitRevenue * ($item->manipulation_total / $itemGross) : 0.0;
                $this->addDoctorCategory($categories, $category, $label, $visit, $quantity, $revenue);
                if ($buildDetail) {
                    $this->addDoctorCategory($details[$visit->doctor_id]['categories'], $category, $label, $visit, $quantity, $revenue);
                    $procedure = $item->display_name;
                    $details[$visit->doctor_id]['procedures'][$procedure] ??= ['name' => $procedure, 'count' => 0, 'revenue' => 0.0];
                    $details[$visit->doctor_id]['procedures'][$procedure]['count'] += $quantity;
                    $details[$visit->doctor_id]['procedures'][$procedure]['revenue'] = round($details[$visit->doctor_id]['procedures'][$procedure]['revenue'] + $revenue, 2);
                }
            }
        }

        $rows = $visits->groupBy('doctor_id')
            ->map(function ($doctorVisits) use ($totalRevenue): array {
                $revenue = round((float) $doctorVisits->sum(fn (Visit $visit): float => (float) ($visit->net_amount ?? 0)), 2);

                return [
                    'id' => (int) $doctorVisits->first()->doctor_id,
                    'name' => $doctorVisits->first()->doctor->full_name,
                    'revenue' => $revenue,
                    'procedures' => max($doctorVisits->count(), (int) $doctorVisits->sum(fn (Visit $visit): int => (int) $visit->treatmentCaseItems->sum('quantity'))),
                    'patients' => $doctorVisits->pluck('patient_id')->filter()->unique()->count(),
                    'percentage' => $totalRevenue > 0 ? round($revenue / $totalRevenue * 100, 1) : 0.0,
                ];
            })->sortByDesc('revenue')->values();

        foreach ($details as &$doctorDetail) {
            $doctorDetail['categories'] = $this->finalizeDoctorCategories($doctorDetail['categories'], (float) collect($doctorDetail['categories'])->sum('revenue'));
            $doctorDetail['procedures'] = collect($doctorDetail['procedures'] ?? [])->sortByDesc('revenue')->values()->all();
        }
        unset($doctorDetail);

        return [
            'totalPatients' => $visits->pluck('patient_id')->filter()->unique()->count(),
            'totalRevenue' => $totalRevenue,
            'categories' => $this->finalizeDoctorCategories($categories, $totalRevenue),
            'doctors' => $rows->all(),
            'details' => $details,
        ];
    }

    private function addDoctorCategory(array &$categories, string $key, string $label, Visit $visit, int $count, float $revenue): void
    {
        $categories[$key] ??= ['label' => $label, 'patients' => [], 'procedures' => 0, 'revenue' => 0.0];
        $categories[$key]['patients'][$visit->patient_id] = true;
        $categories[$key]['procedures'] += $count;
        $categories[$key]['revenue'] = round($categories[$key]['revenue'] + $revenue, 2);
    }

    private function finalizeDoctorCategories(array $categories, float $totalRevenue): array
    {
        return collect($categories)->map(function (array $row, string $key) use ($totalRevenue): array {
            $row['key'] = $key;
            $row['patients'] = count($row['patients']);
            $row['percentage'] = $totalRevenue > 0 ? round($row['revenue'] / $totalRevenue * 100, 1) : 0.0;

            return $row;
        })->sortByDesc('revenue')->values()->all();
    }

    /** @return array<string, array<int, string>> */
    private function breakdownDescriptions(): array
    {
        $descriptions = [];
        [$from, $until] = $this->range();

        if ($this->reportTab === 'income') {
            if ($this->includesSource('clinic')) {
                Payment::query()->whereBetween('payment_date', [$this->dateFrom, $this->dateUntil])
                    ->whereHas('splits', fn (Builder $query): Builder => $query->where('currency', $this->currency))
                    ->pluck('comment')->each(function ($text) use (&$descriptions): void {
                        $this->addDescription($descriptions, 'patient_payments', $text);
                    });
                ProductSale::query()->whereBetween('sold_at', [$from, $until])->where('currency', $this->currency)
                    ->pluck('note')->each(function ($text) use (&$descriptions): void {
                        $this->addDescription($descriptions, 'product_sales', $text);
                    });
                FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                    ->where('type', 'income')->where('currency', $this->currency)->get()
                    ->each(function (FinanceTransaction $item) use (&$descriptions): void {
                        $this->addDescription($descriptions, (string) $item->category, $item->description ?: $item->note);
                    });
            }

            if ($this->includesSource('partner')) {
                PartnerPatientPayment::query()->whereBetween('paid_at', [$from, $until])->where('currency', $this->currency)
                    ->pluck('notes')->each(function ($text) use (&$descriptions): void {
                        $this->addDescription($descriptions, 'patient_payments', $text);
                    });
            }

            return $descriptions;
        }

        $financeQuery = FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
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
                ->whereBetween('transacted_at', [$from, $until])->where('currency', $this->currency);
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
            PartnerFinanceTransaction::query()->whereIn('source', $sources)->whereBetween('transacted_at', [$from, $until])
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
            ->whereBetween('transaction_date', [$from, $until])
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
                ->whereBetween('transacted_at', [$from, $until])
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
                    ->whereDate('payment_date', '>=', $this->dateFrom)
                    ->whereDate('payment_date', '<=', $this->dateUntil));
            $this->add($rows, 'patient_payments', 'პაციენტის გადახდები', (float) (clone $paymentQuery)->sum('amount'), (int) (clone $paymentQuery)->distinct()->count('payment_id'));

            $sales = ProductSale::query()->whereBetween('sold_at', [$from, $until])->where('currency', $this->currency);
            $this->add($rows, 'product_sales', 'პროდუქტის გაყიდვა', (float) (clone $sales)->sum('total'), (int) (clone $sales)->count());

            FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                ->where('type', 'income')->where('currency', $this->currency)->get()
                ->each(function (FinanceTransaction $item) use (&$rows): void {
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->amount);
                });
        }

        if ($this->includesSource('partner')) {
            $payments = PartnerPatientPayment::query()->whereBetween('paid_at', [$from, $until])->where('currency', $this->currency);
            $this->add($rows, 'patient_payments', 'პაციენტის გადახდები', (float) (clone $payments)->sum('amount'), (int) (clone $payments)->count());
        }

        return array_values($rows);
    }

    /** @return array<int, array{key: string, label: string, amount: float, count: int}> */
    private function expenseBreakdown(): array
    {
        $rows = [];
        [$from, $until] = $this->range();

        if ($this->source === 'all') {
            FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                ->where('type', 'expense')->where('currency', $this->currency)->get()
                ->each(function (FinanceTransaction $item) use (&$rows): void {
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->amount);
                });
        } elseif ($this->source === 'clinic') {
            FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                ->where('type', 'expense')->where('currency', $this->currency)->get()
                ->each(function (FinanceTransaction $item) use (&$rows): void {
                    $amount = $item->clinic_cash_gel === null ? (float) $item->amount : (float) $item->clinic_cash_gel;
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), $amount);
                });
        }

        if ($this->includesSource('partner')) {
            PartnerFinanceTransaction::query()->israeli()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->whereBetween('transacted_at', [$from, $until])->where('currency', $this->currency)->get()
                ->each(function (PartnerFinanceTransaction $item) use (&$rows): void {
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->amount);
                });

            if ($this->source === 'partner' && $this->currency === 'GEL') {
                FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                    ->where('type', 'expense')->where('israeli_cash_gel', '>', 0)->get()
                    ->each(function (FinanceTransaction $item) use (&$rows): void {
                        $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->israeli_cash_gel);
                    });
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
            FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                ->where('type', 'expense')->where('currency', $this->currency)
                ->where('payment_method', 'cash')->where('cash_source', 'current_cashier')->get()
                ->each(function (FinanceTransaction $item) use (&$rows): void {
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->amount);
                });
            $this->addCashMovements($rows, PartnerFinanceTransaction::SOURCE_CLINIC, $from, $until);
        } elseif ($this->source === 'clinic') {
            FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                ->where('type', 'expense')->where('currency', $this->currency)
                ->where('payment_method', 'cash')->where('cash_source', 'current_cashier')->get()
                ->each(function (FinanceTransaction $item) use (&$rows): void {
                    $amount = $item->clinic_cash_gel === null ? (float) $item->amount : (float) $item->clinic_cash_gel;
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), $amount);
                });
            $this->addCashMovements($rows, PartnerFinanceTransaction::SOURCE_CLINIC, $from, $until);
        }

        if ($this->includesSource('partner')) {
            PartnerFinanceTransaction::query()->israeli()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->where('from_account', 'cash')->whereBetween('transacted_at', [$from, $until])
                ->where('currency', $this->currency)->get()
                ->each(function (PartnerFinanceTransaction $item) use (&$rows): void {
                    $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->amount);
                });

            if ($this->source === 'partner' && $this->currency === 'GEL') {
                FinanceTransaction::query()->whereBetween('transaction_date', [$from, $until])
                    ->where('type', 'expense')->where('israeli_cash_gel', '>', 0)->get()
                    ->each(function (FinanceTransaction $item) use (&$rows): void {
                        $this->add($rows, (string) $item->category, $this->categoryLabel($item->category), (float) $item->israeli_cash_gel);
                    });
            }
            $this->addCashMovements($rows, PartnerFinanceTransaction::SOURCE_ISRAELI, $from, $until);
        }

        return array_values($rows);
    }

    /** @param array<string, array{key: string, label: string, amount: float, count: int}> $rows */
    private function addCashMovements(array &$rows, string $source, Carbon $from, Carbon $until): void
    {
        PartnerFinanceTransaction::query()->where('source', $source)->whereBetween('transacted_at', [$from, $until])
            ->where('from_account', 'cash')->whereIn('type', [
                PartnerFinanceTransaction::TYPE_EXCHANGE,
                PartnerFinanceTransaction::TYPE_TRANSFER,
                PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
            ])->get()->each(function (PartnerFinanceTransaction $item) use (&$rows): void {
                if ($item->type === PartnerFinanceTransaction::TYPE_EXCHANGE) {
                    if ($item->from_currency === $this->currency) {
                        $this->add($rows, 'currency_exchange', 'ვალუტის გაცვლა', (float) $item->from_amount);
                    }

                    return;
                }

                if ($item->currency !== $this->currency) {
                    return;
                }

                $label = $item->type === PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL
                    ? 'მფლობელის გატანა'
                    : (PartnerFinanceTransaction::TRANSFER_CATEGORIES[$item->category] ?? 'გადატანა');
                $this->add($rows, 'movement_'.$item->type.'_'.$item->category, $label, (float) $item->amount);
            });
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
        return FinanceTransaction::CATEGORIES[$category]
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
