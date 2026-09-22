<?php

use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('payment autofill preserves row identity and correcting amount clears validation', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = Visit::create(['patient_id' => Patient::create(['first_name' => 'Payment', 'last_name' => 'State'])->id, 'visit_date' => today(), 'visit_type' => 'treatment', 'total_price' => 100]);
    $service = TreatmentCase::create(['name' => 'Payment state work', 'category' => 'therapy']);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 100]);
    $page = Livewire::test(EditVisit::class, ['record' => $visit->id])
        ->mountAction(TestAction::make('makePayment')->schemaComponent());
    $key = array_key_first($page->get('mountedActions.0.data.splits'));
    $path = "mountedActions.0.data.splits.{$key}.amount";
    $page->set('mountedActions.0.data.amount', 60);
    expect(array_keys($page->get('mountedActions.0.data.splits')))->toBe([$key]);
    expect((float) $page->get($path))->toBe(60.0);
    $page->set($path, '')->callMountedAction()->assertHasErrors([$path => 'required']);
    expect($page->instance()->getErrorBag()->first($path))->toBe('თანხა სავალდებულოა');
    $page->set($path, '60')->assertHasNoErrors([$path]);
    expect((float) $page->get($path))->toBe(60.0);
    $page->callMountedAction()->assertHasNoActionErrors();
    expect((float) $visit->payments()->sum('amount'))->toBe(60.0);
});
