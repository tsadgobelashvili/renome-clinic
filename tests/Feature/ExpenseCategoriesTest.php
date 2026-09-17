<?php

use App\Filament\Pages\ExpenseCategories;
use App\Filament\Pages\Finance;
use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\DirectExpenses\Pages\ListDirectExpenses;
use App\Models\Doctor;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\ExpenseDimensions;
use App\Support\ExpenseCategoryForm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function classifiedExpense(ExpenseCategory $category, ?ExpenseSubcategory $subcategory = null): FinanceTransaction
{
    return FinanceTransaction::create(['type' => 'expense', 'transaction_date' => now(), 'amount' => 10,
        'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'expense_category_id' => $category->id,
        'expense_subcategory_id' => $subcategory?->id]);
}

test('owner creates edits deactivates and deletes unused categories and subcategories', function () {
    $page = Livewire::test(ExpenseCategories::class)->call('edit')->set('name', 'Test category')->call('save')->assertHasNoErrors();
    $category = ExpenseCategory::where('name', 'Test category')->firstOrFail();
    $page->call('edit', $category->id)->set('name', 'Renamed')->call('save')->assertHasNoErrors()
        ->call('toggleActive', $category->id);
    expect($category->fresh()->name)->toBe('Renamed');
    expect($category->fresh()->active)->toBeFalse();
    $page->call('edit', null, $category->id)->set('name', 'Materials')->call('save')->assertHasNoErrors();
    $subcategory = $category->children()->firstOrFail();
    $page->call('edit', $subcategory->id, $category->id)->set('name', 'Supplies')->call('save')
        ->call('toggleActive', $subcategory->id, true);
    expect($subcategory->fresh()->name)->toBe('Supplies');
    expect($subcategory->fresh()->active)->toBeFalse();
    $page->call('deleteRecord', $subcategory->id, true)->call('deleteRecord', $category->id);
    expect(ExpenseCategory::find($category->id))->toBeNull();
    expect(ExpenseCategory::find($subcategory->id))->toBeNull();
});

test('used classification is deactivated by management and never destructively deleted', function () {
    $category = new ExpenseCategory(['name' => 'Historical']);
    $category->forceFill(['classification_dimension' => 'direction'])->save();
    $subcategory = new ExpenseCategory(['name' => 'Salaries']);
    $subcategory->forceFill(['classification_dimension' => 'type', 'parent_id' => $category->id])->save();
    $expense = FinanceTransaction::create(['type' => 'expense', 'transaction_date' => now(), 'amount' => 10,
        'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'expense_direction_id' => $category->id,
        'expense_type_id' => $subcategory->id]);
    Livewire::test(ExpenseCategories::class)->call('deleteRecord', $category->id)
        ->call('deleteRecord', $subcategory->id, true);
    expect($category->fresh()->active)->toBeFalse();
    expect($subcategory->fresh()->active)->toBeFalse();
    expect($expense->fresh()->expenseDirection->name)->toBe('Historical');
    expect($expense->fresh()->expenseType->name)->toBe('Salaries');
    $expense->update(['description' => 'Still editable']);
    expect(fn () => $category->delete())->toThrow(ValidationException::class);
    expect(fn () => $subcategory->delete())->toThrow(ValidationException::class);
});

test('dependent options exclude inactive records except the historical selection', function () {
    $category = ExpenseCategory::create(['name' => 'Active']);
    $inactive = ExpenseCategory::create(['name' => 'Inactive', 'active' => false]);
    $sub = $category->subcategories()->create(['name' => 'Supplies']);
    $old = $category->subcategories()->create(['name' => 'Old', 'active' => false]);
    $foreign = $inactive->subcategories()->create(['name' => 'Foreign']);
    expect(ExpenseCategoryForm::categories())->toHaveKey($category->id)->not->toHaveKey($inactive->id);
    expect(ExpenseCategoryForm::categories($inactive->id))->toHaveKey($inactive->id);
    expect(ExpenseCategoryForm::subcategories($category->id))->toBe([$sub->id => 'Supplies']);
    expect(ExpenseCategoryForm::subcategories($category->id, $old->id))->toHaveKey($old->id)->not->toHaveKey($foreign->id);
    expect(fn () => classifiedExpense($inactive))->toThrow(ValidationException::class);
    expect(fn () => classifiedExpense($category, $foreign))->toThrow(ValidationException::class);
    expect(fn () => classifiedExpense($category, $old))->toThrow(ValidationException::class);
});

test('admin can use categories but cannot manage them', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(ExpenseCategories::canAccess())->toBeFalse();
    Livewire::test(ExpenseCategories::class)->assertForbidden();
    expect(ExpenseCategoryForm::categories())->not->toBeEmpty();
});

test('category list query count stays constant as categories grow', function () {
    $page = Livewire::test(ExpenseCategories::class);
    foreach (range(1, 15) as $number) {
        $category = new ExpenseCategory(['name' => 'Category '.$number]);
        $category->forceFill(['classification_dimension' => 'direction'])->save();
        $child = new ExpenseCategory(['name' => 'Child']);
        $child->forceFill(['classification_dimension' => 'type', 'parent_id' => $category->id])->save();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page->call('$refresh');
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'expense_categor') || str_contains($query['query'], 'expense_subcategor'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2);
});

test('finance expense action clears child when category changes', function () {
    $dimensions = app(ExpenseDimensions::class);
    Livewire::test(Finance::class)->mountAction('add_expense')
        ->fillForm(['expense_direction_id' => $dimensions->id('direction', 'surgery'), 'expense_type_id' => $dimensions->id('type', 'materials')])
        ->set('mountedActions.0.data.expense_direction_id', $dimensions->id('direction', 'therapy'))
        ->assertSet('mountedActions.0.data.expense_type_id', null);
});

test('partner expense retains managed classification and database foreign keys prevent deletion', function () {
    $category = ExpenseCategory::create(['name' => 'Partner']);
    $transaction = PartnerFinanceTransaction::create(['type' => 'expense', 'transacted_at' => now(),
        'expense_category_id' => $category->id, 'from_account' => 'cash', 'amount' => 20, 'currency' => 'GEL']);
    expect($transaction->category)->toBe('managed_'.$category->id);
    expect(fn () => $category->delete())->toThrow(ValidationException::class);
    expect(fn () => DB::table('expense_categories')->where('id', $category->id)->delete())->toThrow(QueryException::class);
});

test('direct expense form creates and edits classification including inactive historical values', function () {
    $patient = Patient::create(['first_name' => 'Expense', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Expense', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL']);
    $work = TreatmentCase::create(['name' => 'Work', 'category' => 'surgery', 'is_active' => true]);
    $item = $visit->treatmentCaseItems()->create(['treatment_case_id' => $work->id, 'quantity' => 1, 'unit_price' => 500]);
    $category = ExpenseCategory::where('classification_code', 'surgery')->sole();
    $sub = ExpenseCategory::where('classification_code', 'materials')->where('parent_id', $category->id)->sole();
    $page = Livewire::test(ListDirectExpenses::class)
        ->call('mountAction', 'expense', ['item' => $item->id])
        ->fillForm(['name' => 'Implant', 'amount' => 100, 'expense_direction_id' => $category->id, 'expense_type_id' => $sub->id])
        ->callMountedAction()->assertHasNoFormErrors();
    $expense = $item->directExpenses()->sole();
    expect($expense->expense_direction_id)->toBe($category->id)->and($expense->expense_type_id)->toBe($sub->id);
    $category->update(['active' => false]);
    $sub->update(['active' => false]);
    $page->call('mountAction', 'expense', ['item' => $item->id, 'expense' => $expense->id])
        ->fillForm(['name' => 'Updated implant'])->callMountedAction()->assertHasNoFormErrors();
    expect($expense->fresh()->name)->toBe('Updated implant');
    expect(fn () => $category->delete())->toThrow(ValidationException::class);
});

test('managed categories appear in SQL grouped Finance report totals after deactivation', function () {
    $category = ExpenseCategory::where('classification_code', 'equipment')->whereNull('parent_id')->sole();
    FinanceTransaction::create(['type' => 'expense', 'transaction_date' => now(), 'amount' => 10, 'currency' => 'GEL',
        'payment_method' => 'bank_transfer', 'expense_type_id' => $category->id,
        'expense_direction_id' => app(ExpenseDimensions::class)->id('direction', 'general')]);
    $category->update(['active' => false]);
    Livewire::test(FinanceReports::class)->call('selectReportTab', 'expense')->set('expenseGrouping', 'type')
        ->assertViewHas('reportTotal', 10.0)
        ->assertViewHas('reportRows', fn ($rows) => count($rows) === 1 && $rows[0]['label'] === 'ტექნიკა');
});
