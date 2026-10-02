<?php

use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\LabCasesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Patients\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\Patients\Support\PatientPaymentHistory;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\DatabaseSafety;

beforeEach(function () {
    DatabaseSafety::assertInMemory(DB::connection()->getConfig());
    Http::preventStrayRequests();
    // Ephemeral query fixtures only: no application database, migrations or model write hooks.
    $tables = [
        'users' => 'name email password role locale is_active created_at updated_at',
        'patients' => 'first_name last_name patient_number phone personal_id birth_date notes patient_group_id',
        'patient_groups' => 'name slug',
        'doctors' => 'first_name last_name is_active',
        'patient_doctor' => 'patient_id doctor_id is_primary role assignment_source created_at updated_at',
        'visits' => 'patient_id doctor_id visit_date visit_type visit_time total_price currency discount_amount discount_type discount_value cancelled_at',
        'payments' => 'visit_id amount currency payment_method payment_date comment is_historical deleted_at created_at',
        'payment_splits' => 'payment_id amount currency payment_method',
        'partner_patient_payments' => 'patient_id visit_id amount currency payment_method paid_at notes deleted_at',
        'visit_treatment_cases' => 'visit_id treatment_case_id custom_service_name teeth quantity unit_price currency comment',
        'treatment_cases' => 'name category',
        'direct_expenses' => 'visit_treatment_case_id name quantity amount currency expense_direction_id expense_type_id',
        'expense_categories' => 'name',
        'treatment_estimates' => 'patient_id doctor_id estimate_date',
        'lab_cases' => 'patient_id doctor_id assistant_employee_id case_date source status exocad_project_reference material shade quantity modeled_by modeling milled_by milling_quantity milling_technician',
        'lab_work_items' => 'lab_case_id work_type technician_id quantity',
        'lab_main_works' => 'lab_case_id material shade quantity technician_id sort_order',
        'lab_additional_works' => 'lab_case_id work_type quantity technician_id technician',
        'employees' => 'first_name last_name',
    ];
    foreach ($tables as $table => $columns) {
        DB::statement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY AUTOINCREMENT, '.implode(', ', array_map(
            fn ($column) => $column.' '.(str_ends_with($column, '_id') || in_array($column, ['amount', 'total_price', 'discount_amount', 'quantity', 'unit_price']) ? 'NUMERIC' : 'TEXT'),
            explode(' ', $columns),
        )).')');
    }
    app()->setLocale('en');
    $this->actingAs(User::forceCreate(['name' => 'Profile test', 'email' => 'profile@example.test', 'role' => User::ROLE_OWNER, 'is_active' => true]));
    DB::table('patient_groups')->insert(['id' => 1, 'name' => 'Clinic', 'slug' => 'clinic']);
    foreach ([1, 2] as $id) {
        DB::table('patients')->insert(['id' => $id, 'first_name' => 'Patient', 'last_name' => (string) $id, 'patient_number' => $id, 'patient_group_id' => 1]);
        DB::table('visits')->insert(['id' => $id, 'patient_id' => $id, 'visit_date' => '2025-02-03', 'visit_type' => 'treatment', 'total_price' => 500, 'discount_amount' => 50, 'currency' => 'GEL']);
    }
    $this->patient = Patient::findOrFail(1);
});

test('laboratory history renders linked cases for clinic and laboratory-created patients without per-row queries', function (bool $laboratoryCreated) {
    if ($laboratoryCreated) {
        // Laboratory quick creation uses the same Patient model, without requiring a phone or visit.
        DB::table('patients')->insert(['id' => 3, 'first_name' => 'Lab', 'last_name' => 'Patient', 'patient_number' => 3, 'birth_date' => '1990-02-03', 'patient_group_id' => 1]);
    }
    $patient = $laboratoryCreated ? Patient::findOrFail(3) : $this->patient;
    DB::table('doctors')->insert(['id' => 1, 'first_name' => 'History', 'last_name' => 'Doctor']);
    DB::table('employees')->insert(['id' => 1, 'first_name' => 'History', 'last_name' => 'Assistant']);
    foreach ([1, 2] as $id) {
        DB::table('lab_cases')->insert([
            'id' => $id, 'patient_id' => $patient->id, 'doctor_id' => $id === 1 ? 1 : null,
            'assistant_employee_id' => $id === 2 ? 1 : null, 'case_date' => '2026-09-01',
            'source' => 'clinic', 'status' => 'open', 'exocad_project_reference' => 'Patient laboratory project '.$id,
        ]);
        DB::table('lab_work_items')->insert(['lab_case_id' => $id, 'work_type' => 'pmma', 'technician_id' => auth()->id()]);
    }
    DB::table('lab_cases')->insert(['id' => 9, 'patient_id' => 2, 'case_date' => '2026-09-02', 'exocad_project_reference' => 'Foreign laboratory project']);
    $page = Livewire::test(LabCasesRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
        ->assertOk()->assertSee('Patient laboratory project 1')->assertSee('Patient laboratory project 2')
        ->assertSee('History Doctor')->assertSee('History Assistant')->assertDontSee('Foreign laboratory project');
    expect($page->instance()->getTableRecords()->pluck('id')->sort()->values()->all())->toBe([1, 2]);
    expect($page->instance()->getTableRecord('9'))->toBeNull();

    DB::enableQueryLog();
    DB::flushQueryLog();
    $cases = $page->instance()->getTable()->getQuery()->get();
    expect(DB::getQueryLog())->toHaveCount(7);
    foreach ($cases as $case) {
        expect($case->relationLoaded('doctor'))->toBeTrue()
            ->and($case->relationLoaded('assistantEmployee'))->toBeTrue()
            ->and($case->relationLoaded('workItems'))->toBeTrue();
        $case->doctor_display;
        foreach ($case->workItems as $item) {
            expect($item->relationLoaded('technician'))->toBeTrue();
            $item->technician?->name;
        }
    }
    expect(DB::getQueryLog())->toHaveCount(7);
    DB::disableQueryLog();
})->with(['existing clinic patient' => false, 'laboratory-created patient' => true]);

test('laboratory history renders an empty table for a patient without lab cases', function () {
    $page = Livewire::test(LabCasesRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])->assertOk();
    expect($page->instance()->getTableRecords())->toHaveCount(0);
});

test('laboratory history keeps owner-only profile visibility', function () {
    expect(LabCasesRelationManager::canViewForRecord($this->patient, ViewPatient::class))->toBeTrue();
    Livewire::test(ViewPatient::class, ['record' => 1])->set('activeRelationManager', '2')
        ->assertSeeHtml('wire:name="'.LabCasesRelationManager::class.'"');
    $this->actingAs(new User(['id' => 20, 'role' => User::ROLE_ADMINISTRATOR, 'is_active' => true]));
    expect(LabCasesRelationManager::canViewForRecord($this->patient, ViewPatient::class))->toBeFalse();
    Livewire::test(ViewPatient::class, ['record' => 1])->assertDontSee('Laboratory history');
    $this->actingAs(new User(['id' => 21, 'role' => User::ROLE_LAB_TECHNICIAN, 'is_active' => true]));
    expect(LabCasesRelationManager::canViewForRecord($this->patient, ViewPatient::class))->toBeFalse();
});

test('laboratory history pairs stored work quantities shades and performers in translated compact lines', function (string $locale) {
    app()->setLocale($locale);
    DB::table('employees')->insert([
        ['id' => 1, 'first_name' => 'Main', 'last_name' => 'Technician'],
        ['id' => 2, 'first_name' => 'Additional', 'last_name' => 'Technician'],
    ]);
    DB::table('lab_cases')->insert(['id' => 1, 'patient_id' => 1, 'case_date' => '2026-09-29', 'status' => 'open',
        'material' => 'other', 'shade' => 'Legacy shade', 'quantity' => 99, 'exocad_project_reference' => 'Existing reference']);
    DB::table('lab_main_works')->insert([
        ['lab_case_id' => 1, 'material' => 'zircon', 'shade' => 'A2', 'quantity' => 6, 'technician_id' => 1, 'sort_order' => 1],
        ['lab_case_id' => 1, 'material' => 'pmma', 'shade' => null, 'quantity' => 2, 'technician_id' => null, 'sort_order' => 2],
    ]);
    DB::table('lab_additional_works')->insert(['lab_case_id' => 1, 'work_type' => 'milling', 'quantity' => 3, 'technician_id' => 2]);
    DB::table('lab_work_items')->insert(['lab_case_id' => 1, 'work_type' => 'titanium_bar_modeling', 'quantity' => 4, 'technician_id' => auth()->id()]);
    $page = Livewire::test(LabCasesRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->assertSee(__('lab.material'))->assertSee(__('lab.shade'))->assertSee(__('lab.quantity'))->assertSee(__('lab.technician'))
        ->assertSee(__('lab.date'))->assertSee(__('lab.exocad'))->assertSee(__('patient-profile.lab_statuses.open'))
        ->assertSee('Main Technician')->assertSee('Additional Technician')->assertSee('Profile test')
        ->assertSee('A2')->assertSee('Existing reference')->assertDontSee('Legacy shade');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $case = $page->instance()->getTable()->getQuery()->first();
    $queries = count(DB::getQueryLog());
    $html = view('filament.resources.patients.lab-work-details', ['getRecord' => fn () => $case])->render();
    expect(DB::getQueryLog())->toHaveCount($queries);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
    $lines = (new DOMXPath($document))->query('//div[contains(@class,"items-baseline")]');
    expect($lines)->toHaveCount(4);
    $text = fn ($index) => preg_replace('/\s+/u', ' ', trim($lines->item($index)->textContent));
    expect($text(0))->toContain(__('patient-profile.lab_work_types.zircon'), 'A2', __('lab.quantity').': 6', 'Main Technician');
    expect($text(1))->toContain('PMMA', __('lab.quantity').': 2', __('lab.technician').': —')->not->toContain('Main Technician');
    expect($text(2))->toContain(__('patient-profile.lab_work_types.milling'), __('lab.quantity').': 3', 'Additional Technician');
    expect($text(3))->toContain(__('patient-profile.lab_work_types.titanium_bar_modeling'), __('lab.quantity').': 4', 'Profile test');
    expect($html)->not->toContain('<table', '6.00', '4.00');
    DB::disableQueryLog();
})->with(['en', 'ka']);

test('laboratory history uses stored legacy case values and missing value placeholders', function () {
    DB::table('lab_cases')->insert(['id' => 1, 'patient_id' => 1, 'case_date' => '2026-09-29',
        'material' => 'zircon', 'shade' => 'B1', 'quantity' => 12, 'modeled_by' => auth()->id(),
        'milling_quantity' => 2, 'milling_technician' => 'Recorded legacy miller']);
    DB::table('lab_cases')->insert(['id' => 2, 'patient_id' => 1, 'case_date' => '2026-09-28']);
    $page = Livewire::test(LabCasesRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->assertSee('Zircon')->assertSee('B1')->assertSee('Profile test')->assertSee('Recorded legacy miller');
    $case = $page->instance()->getTable()->getQuery()->find(2);
    $html = view('filament.resources.patients.lab-work-details', ['getRecord' => fn () => $case])->render();
    expect(substr_count($html, '—'))->toBe(4);
});

function profileReceipt(array $attributes = []): int
{
    return DB::table('payments')->insertGetId(array_replace([
        'visit_id' => 1, 'amount' => 200, 'currency' => 'GEL', 'payment_method' => 'cash',
        'payment_date' => '2025-02-01', 'created_at' => '2026-09-30', 'comment' => 'Historical receipt', 'is_historical' => true,
    ], $attributes));
}

test('profile renders compact information and lazy translated history tabs', function () {
    profileReceipt(['comment' => 'Receipt loaded on demand']);
    $page = Livewire::test(ViewPatient::class, ['record' => 1])->assertOk()
        ->assertSee('History number')->assertSee('Visits')->assertSee('Payments')->assertSee('Laboratory history')
        ->assertDontSee('Receipt loaded on demand');
    $page->set('activeRelationManager', '1')->assertSeeHtml('wire:name="'.PaymentsRelationManager::class.'"');
    app()->setLocale('ka');
    Livewire::test(ViewPatient::class, ['record' => 1])->assertOk()
        ->assertSee('ვიზიტები')->assertSee('გადახდები')->assertSee('ლაბორატორიის ისტორია');
});

test('payment tab renders historical linked and unlinked receipts without duplicates or currency aggregation', function () {
    DB::table('patient_groups')->insert(['id' => 2, 'name' => 'Israeli', 'slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    DB::table('patients')->where('id', 1)->update(['patient_group_id' => 2]);
    $this->patient->refresh();
    $id = profileReceipt();
    DB::table('payment_splits')->insert([
        ['payment_id' => $id, 'amount' => 120, 'currency' => 'GEL', 'payment_method' => 'cash'],
        ['payment_id' => $id, 'amount' => 80, 'currency' => 'GEL', 'payment_method' => 'card'],
    ]);
    DB::table('partner_patient_payments')->insert(['id' => $id, 'patient_id' => 1, 'amount' => 75, 'currency' => 'USD', 'paid_at' => '2025-02-04', 'payment_method' => 'cash', 'notes' => 'Unlinked USD receipt']);
    DB::table('partner_patient_payments')->insert(['patient_id' => 2, 'amount' => 80, 'currency' => 'USD', 'paid_at' => '2025-02-04', 'notes' => 'Other partner patient']);
    DB::table('partner_patient_payments')->insert(['patient_id' => 1, 'amount' => 80, 'currency' => 'USD', 'paid_at' => '2025-02-04', 'notes' => 'Deleted partner receipt', 'deleted_at' => now()]);
    profileReceipt(['visit_id' => 2, 'comment' => 'Other patient']);
    profileReceipt(['deleted_at' => now(), 'comment' => 'Deleted receipt']);
    $page = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->assertSee('Historical receipt')->assertSee('Unlinked USD receipt')->assertSee('120.00')->assertSee('80.00')
        ->assertDontSee('Other patient')->assertDontSee('Deleted receipt')->assertDontSee('Other partner patient')
        ->assertDontSee('Deleted partner receipt')->assertSee('Payment · 04.02.2025');
    expect($page->instance()->getTableRecords()->total())->toBe(2);
    expect($page->instance()->getTableRecords()->first()->source)->toBe('israeli');
    expect($page->instance()->getTableRecord((string) $id)?->visit?->getKey())->toBe(1);
    $page->mountAction(TestAction::make('visitDetails')->table($id))->assertActionMounted(TestAction::make('visitDetails')->table($id));
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Historical receipt');
});

test('visit row opens read only detail with separate expenses and existing amounts', function () {
    profileReceipt();
    DB::table('visit_treatment_cases')->insert(['id' => 1, 'visit_id' => 1, 'custom_service_name' => 'Long manipulation name for readable wrapping', 'teeth' => '11, 12', 'quantity' => 2, 'unit_price' => 250, 'currency' => 'GEL']);
    DB::table('direct_expenses')->insert(['visit_treatment_case_id' => 1, 'name' => 'Authorized expense', 'quantity' => 1, 'amount' => 25, 'currency' => 'USD']);
    $page = Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class]);
    expect($page->instance()->getTable()->getRecordUrl(Visit::find(1)))->toBeNull();
    $page->mountAction(TestAction::make('visitDetails')->table(1))->assertActionMounted(TestAction::make('visitDetails')->table(1));
    expect($page->instance()->getMountedAction()->getModalContent()->render())
        ->toContain('Long manipulation name', 'Authorized expense', 'Expenses', '450.00', '250.00');
    expect($page->instance()->getMountedAction()->getExtraModalFooterActions())->toHaveCount(1);
    expect($page->instance()->getMountedAction()->getModalSubmitAction())->toBeNull();
});

test('payment pagination uses payment date then unique key and query count stays bounded', function () {
    for ($i = 0; $i < 13; $i++) {
        profileReceipt(['payment_date' => '2025-02-01']);
    }
    $page = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class]);
    expect($page->instance()->getTableRecords()->total())->toBe(13)
        ->and($page->instance()->getTableRecords()->count())->toBe(10)
        ->and($page->instance()->getTableRecords()->first()->id)->toBe(13);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = PatientPaymentHistory::query($this->patient)->get();
    foreach ($rows as $row) {
        $row->method_display;
        $row->visit?->visit_date;
    }
    expect(DB::getQueryLog())->toHaveCount(3);
    DB::disableQueryLog();
});

test('foreign visit and payment keys cannot open another patients details', function () {
    $foreign = profileReceipt(['visit_id' => 2, 'comment' => 'Foreign secret']);
    Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->mountAction(TestAction::make('visitDetails')->table(2))->assertDontSee('Foreign secret')->assertActionNotMounted();
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->mountAction(TestAction::make('visitDetails')->table($foreign))->assertDontSee('Foreign secret')->assertActionNotMounted();
});

test('unauthorized roles cannot view profile or its payment tab', function () {
    $this->actingAs(new User(['id' => 50, 'role' => User::ROLE_LAB_TECHNICIAN, 'is_active' => true]));
    expect(PaymentsRelationManager::canViewForRecord($this->patient, ViewPatient::class))->toBeFalse();
    Livewire::test(ViewPatient::class, ['record' => 1])->assertForbidden();
    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])->assertForbidden();
});

test('linked Israeli payment opens only its visit and read only users receive no edit action', function () {
    DB::table('partner_patient_payments')->insert(['id' => 7, 'patient_id' => 1, 'visit_id' => 1, 'amount' => 90, 'currency' => 'USD', 'paid_at' => '2025-01-02', 'payment_method' => 'card', 'notes' => 'Linked partner receipt']);
    Gate::before(fn ($user, $ability, $arguments) => $ability === 'update' && ($arguments[0] ?? null) instanceof Visit ? false : null);
    $page = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $page->mountAction(TestAction::make('visitDetails')->table(-7))->assertActionMounted(TestAction::make('visitDetails')->table(-7));
    $action = $page->instance()->getMountedAction();
    expect($action->getModalContent()->render())->toContain('Linked partner receipt', '$90.00', '02.01.2025');
    expect($action->getExtraModalFooterActions())->toBeEmpty();
    expect(collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete|replace)\b/i', $query['query'])))->toBeEmpty();
    DB::disableQueryLog();
});

test('payment history compiles PostgreSQL page count and record lookup SQL without connecting to PostgreSQL', function () {
    // Compilation only, not an engine test. All execution is intercepted by pretend().
    $connection = DB::connection();
    $originalGrammar = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new PostgresGrammar($connection));
    try {
        $query = PatientPaymentHistory::query($this->patient)->orderByDesc('payment_date')->orderByDesc('id');
        $sql = $query->toSql();
        expect($sql)->toContain('CAST(p.id AS BIGINT)', 'CAST(-partner_patient_payments.id AS BIGINT)', 'FALSE as is_historical',
            'p.is_historical', 'p.payment_date', 'paid_at as payment_date', 'union all',
            '"v"."patient_id" = ?', '"partner_patient_payments"."patient_id" = ?',
            '"p"."deleted_at" is null', '"partner_patient_payments"."deleted_at" is null')
            ->not->toContain('SIGNED', '0 as is_historical', '`');
        expect($query->getBindings())->toBe([1, 1]);

        $counts = $connection->pretend(fn () => (clone $query)->paginate(10, ['*'], 'page', 2));
        expect($counts)->toHaveCount(1);
        expect($counts[0]['query'])->toContain('count(*) as "aggregate"', 'union all')->not->toContain('order by', 'limit');
        $pages = $connection->pretend(fn () => (clone $query)->paginate(10, ['*'], 'page', 2, 13));
        expect($pages)->toHaveCount(1);
        expect($pages[0]['query'])->toContain('order by "payment_date" desc, "id" desc limit 10 offset 10');
        foreach (['2147483650', '-2147483650'] as $key) {
            $lookup = $connection->pretend(fn () => (clone $query)->find($key));
            expect($lookup)->toHaveCount(1);
            expect($lookup[0]['query'])->toContain('"payments"."id" =', 'limit 1');
            expect((clone $query)->whereKey($key)->getBindings())->toBe([1, 1, $key]);
        }
    } finally {
        $connection->setQueryGrammar($originalGrammar);
    }
});

test('payment history preserves bigint row identities and date ordering across Clinic and Israeli receipts', function () {
    $id = 2147483650;
    profileReceipt(['id' => $id, 'payment_date' => '2025-02-04']);
    DB::table('partner_patient_payments')->insert(['id' => $id, 'patient_id' => 1, 'visit_id' => 1,
        'amount' => 90, 'currency' => 'USD', 'paid_at' => '2025-02-04 16:30:00', 'payment_method' => 'card', 'notes' => 'Bigint partner receipt']);
    $page = Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->patient, 'pageClass' => ViewPatient::class])
        ->assertSee('Historical receipt')->assertSee('Bigint partner receipt');
    expect($page->instance()->getTableRecords()->total())->toBe(2);
    expect($page->instance()->getTableRecords()->pluck('id')->all())->toBe([-$id, $id]);
    foreach ([$id => true, -$id => false] as $key => $historical) {
        $record = $page->instance()->getTableRecord((string) $key);
        expect($record->is_historical)->toBe($historical)->and($record->visit->id)->toBe(1);
        $page->mountAction(TestAction::make('visitDetails')->table($key))
            ->assertActionMounted(TestAction::make('visitDetails')->table($key));
        expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('Historical receipt', 'Bigint partner receipt');
        $page->unmountAction();
    }
});
