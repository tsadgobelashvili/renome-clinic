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
    $subcategory = $category->subcategories()->firstOrFail();
    $page->call('edit', $subcategory->id, $category->id)->set('name', 'Supplies')->call('save')
        ->call('toggleActive', $subcategory->id, true);
    expect($subcategory->fresh()->name)->toBe('Supplies');
    expect($subcategory->fresh()->active)->toBeFalse();
    $page->call('deleteRecord', $subcategory->id, true)->call('deleteRecord', $category->id);
    expect(ExpenseCategory::find($category->id))->toBeNull();
    expect(ExpenseSubcategory::find($subcategory->id))->toBeNull();
});

test('used classification is deactivated by management and never destructively deleted', function () {
    $category = ExpenseCategory::create(['name' => 'Historical']);
    $subcategory = $category->subcategories()->create(['name' => 'Salaries']);
    $expense = classifiedExpense($category, $subcategory);
    Livewire::test(ExpenseCategories::class)->call('deleteRecord', $category->id)
        ->call('deleteRecord', $subcategory->id, true);
    expect($category->fresh()->active)->toBeFalse();
    expect($subcategory->fresh()->active)->toBeFalse();
    expect($expense->fresh()->expenseCategory->name)->toBe('Historical');
    expect($expense->fresh()->expenseSubcategory->name)->toBe('Salaries');
    expect(ExpenseCategoryForm::label($expense->category))->toBe('Historical');
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
        ExpenseCategory::create(['name' => 'Category '.$number])->subcategories()->create(['name' => 'Child']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page->call('$refresh');
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'expense_categor') || str_contains($query['query'], 'expense_subcategor'));
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2);
});

test('finance expense action requires category and clears subcategory when category changes', function () {
    $category = ExpenseCategory::create(['name' => 'One']);
    $other = ExpenseCategory::create(['name' => 'Two']);
    $sub = $category->subcategories()->create(['name' => 'Child']);
    Livewire::test(Finance::class)->mountAction('add_expense')
        ->fillForm(['expense_category_id' => $category->id, 'expense_subcategory_id' => $sub->id])
        ->set('mountedActions.0.data.expense_category_id', $other->id)
        ->assertSet('mountedActions.0.data.expense_subcategory_id', null);
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
    $category = ExpenseCategory::create(['name' => 'Direct category']);
    $sub = $category->subcategories()->create(['name' => 'Materials']);
    $page = Livewire::test(ListDirectExpenses::class)
        ->call('mountAction', 'expense', ['item' => $item->id])
        ->fillForm(['name' => 'Implant', 'amount' => 100, 'expense_category_id' => $category->id, 'expense_subcategory_id' => $sub->id])
        ->callMountedAction()->assertHasNoFormErrors();
    $expense = $item->directExpenses()->sole();
    expect($expense->expense_category_id)->toBe($category->id);
    $category->update(['active' => false]);
    $sub->update(['active' => false]);
    $page->call('mountAction', 'expense', ['item' => $item->id, 'expense' => $expense->id])
        ->fillForm(['name' => 'Updated implant'])->callMountedAction()->assertHasNoFormErrors();
    expect($expense->fresh()->name)->toBe('Updated implant');
    expect(fn () => $category->delete())->toThrow(ValidationException::class);
});

test('managed categories appear in SQL grouped Finance report totals after deactivation', function () {
    $category = ExpenseCategory::create(['name' => 'Managed equipment']);
    classifiedExpense($category);
    $category->update(['active' => false]);
    Livewire::test(FinanceReports::class)->call('selectReportTab', 'expense')
        ->assertViewHas('reportTotal', 10.0)
        ->assertViewHas('reportRows', fn ($rows) => count($rows) === 1 && $rows[0]['label'] === 'Managed equipment');
});
