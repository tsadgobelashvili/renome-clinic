<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceReports;
use App\Models\CashboxTransaction;
use App\Models\ExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\FinanceManager;
use App\Support\CashboxManager;
use App\Support\CashboxMovementPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $manager = app(CashboxManager::class);
    $manager->today()->update(['opening_balance' => 1000, 'opening_balance_usd' => 500]);
});

test('cashier expense actions require shared classification and preserve expense details', function (string $page, string $action) {
    $category = ExpenseCategory::where('classification_dimension', 'direction')->where('classification_code', 'surgery')->sole();
    $subcategory = ExpenseCategory::where('classification_dimension', 'type')->where('classification_code', 'materials')->where('parent_id', $category->id)->sole();
    $component = Livewire::test($page);
    if ($page === Dashboard::class) {
        $component->mountAction('cashboxOverview');
    }
    $component->mountAction($action);
    $data = ['amount' => 100, 'currency' => 'USD', 'transaction_date' => now()->format('Y-m-d H:i:s'), 'description' => 'Sterile gloves delivery'];
    $component->fillForm($data)->callMountedAction()->assertHasFormErrors(['expense_direction_id' => 'required']);
    $component->fillForm([...$data, 'expense_direction_id' => $category->id])
        ->callMountedAction()->assertHasFormErrors(['expense_type_id' => 'required']);
    $component->fillForm([...$data, 'expense_direction_id' => $category->id, 'expense_type_id' => $subcategory->id])
        ->callMountedAction()->assertHasNoErrors();

    $expense = FinanceTransaction::query()->sole();
    expect($expense->expense_direction_id)->toBe($category->id)
        ->and($expense->expense_type_id)->toBe($subcategory->id)
        ->and($expense->description)->toBe($data['description'])
        ->and($expense->currency)->toBe('USD');
    $movement = CashboxTransaction::where('type', 'expense')->sole();
    expect($movement->finance_transaction_id)->toBe($expense->id)
        ->and(app(CashboxManager::class)->today()->summary()['expectedByCurrency'])->toMatchArray(['GEL' => 1000.0, 'USD' => 400.0]);

    Livewire::test(Dashboard::class)->mountAction('cashboxOverview')
        ->assertMountedActionModalSee(['ქირურგია', 'მასალები', $data['description']]);
    Livewire::test(Cashbox::class)->assertSee('ქირურგია')->assertSee('მასალები')->assertSee($data['description']);
    app(FinanceManager::class)->update($expense, ['description' => 'Updated purpose']);
    expect(CashboxTransaction::where('finance_transaction_id', $expense->id)->count())->toBe(1)
        ->and(app(CashboxManager::class)->today()->summary()['expectedByCurrency']['USD'])->toBe(400.0);
})->with([[Cashbox::class, 'expense'], [Dashboard::class, 'dashboardExpense']]);

test('independent classification does not require legacy subcategories', function () {
    $dimensions = app(ExpenseDimensions::class);
    Livewire::test(Cashbox::class)->callAction('expense', data: [
        'amount' => 50, 'currency' => 'GEL', 'transaction_date' => now()->format('Y-m-d H:i:s'),
        'expense_direction_id' => $dimensions->id('direction', 'general'),
        'expense_type_id' => $dimensions->id('type', 'other', $dimensions->id('direction', 'general')),
    ])->assertHasNoFormErrors();
    expect(FinanceTransaction::sole()->expense_subcategory_id)->toBeNull()
        ->and(app(CashboxManager::class)->today()->summary()['expected'])->toBe(950.0);
    Livewire::test(FinanceReports::class)->call('selectReportTab', 'expense')
        ->assertViewHas('reportTotal', 50.0)
        ->assertViewHas('reportRows', fn ($rows) => count($rows) === 1 && $rows[0]['label'] === 'საერთო');
});
test('legacy uncategorized expenses render and withdrawals do not require classification', function () {
    app(CashboxManager::class)->today()->transactions()->create([
        'type' => 'expense', 'amount' => 20, 'currency' => 'GEL', 'payment_method' => 'cash',
        'transaction_date' => now(), 'description' => 'Legacy expense',
    ]);
    Livewire::test(Dashboard::class)->mountAction('cashboxOverview')
        ->assertMountedActionModalSee([__('expense-categories.uncategorized'), 'Legacy expense']);
    Livewire::test(Cashbox::class)->assertSee(__('expense-categories.uncategorized'))
        ->callAction('withdrawal', data: ['amount' => 30, 'description' => 'Owner withdrawal'])->assertHasNoFormErrors();
    expect(FinanceTransaction::count())->toBe(0)
        ->and(CashboxTransaction::where('type', 'cash_withdrawal')->count())->toBe(1)
        ->and(app(CashboxManager::class)->today()->summary()['expected'])->toBe(950.0);
});

test('movement display uses financial colors signs shared income classification and saved descriptions', function () {
    foreach ([
        ['expense', 'danger', '−', 'ხარჯი'],
        ['patient_payment', 'success', '+', 'შემოსავალი'],
        ['other_income', 'success', '+', 'შემოსავალი'],
        ['product_sale', 'success', '+', 'შემოსავალი'],
        ['cash_transfer_in', 'gray', '+', CashboxTransaction::TYPE_LABELS['cash_transfer_in']],
        ['cash_transfer_out', 'gray', '−', CashboxTransaction::TYPE_LABELS['cash_transfer_out']],
        ['cash_withdrawal', 'gray', '−', CashboxTransaction::TYPE_LABELS['cash_withdrawal']],
    ] as [$type, $color, $sign, $label]) {
        $row = new CashboxTransaction(['type' => $type]);
        $row->setRelation('financeTransaction', null);
        expect(CashboxMovementPresentation::color($row))->toBe($color)
            ->and(CashboxMovementPresentation::sign($row))->toBe($sign)
            ->and(CashboxMovementPresentation::type($row))->toBe($label)
            ->and(CashboxMovementPresentation::description($row))->toBe('—');
    }

    $category = ExpenseCategory::create(['name' => 'Shared income category']);
    $subcategory = $category->subcategories()->create(['name' => 'Shared child']);
    $finance = app(FinanceManager::class)->create([
        'type' => 'income', 'expense_category_id' => $category->id, 'expense_subcategory_id' => $subcategory->id,
        'description' => 'Saved income purpose', 'amount' => 25, 'currency' => 'GEL',
        'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(),
    ]);
    $row = $finance->cashboxTransaction->load('financeTransaction.expenseCategory', 'financeTransaction.expenseSubcategory');
    expect(CashboxMovementPresentation::category($row))->toBe('Shared income category → Shared child');
    Livewire::test(Dashboard::class)->mountAction('cashboxOverview')
        ->assertMountedActionModalSee(['Shared income category → Shared child', 'Saved income purpose', '+25.00 ₾']);
    $html = view('filament.pages.partials.cashbox-movement-row', ['transaction' => $row, 'amountDisplay' => '25.00 ₾'])->render();
    expect($html)->toContain('text-green-600', 'title="Saved income purpose"');
    $row->type = 'expense';
    expect(view('filament.pages.partials.cashbox-movement-row', ['transaction' => $row, 'amountDisplay' => '25.00 ₾'])->render())
        ->toContain('text-red-600', '−25.00 ₾');
    $row->type = 'cash_transfer_out';
    expect(view('filament.pages.partials.cashbox-movement-row', ['transaction' => $row, 'amountDisplay' => '25.00 ₾'])->render())
        ->toContain('text-gray-500', '−25.00 ₾')->not->toContain('text-red-600', 'text-green-600');
});
