<?php

use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\TreatmentEstimates\Pages\CreateTreatmentEstimate;
use App\Models\Patient;
use App\Models\User;
use App\Rules\UniquePatientIdentifier;
use App\Services\PatientMergeService;
use App\Support\PatientIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('legacy merge audit copies are encrypted by the same transactional backfill', function () {
    $owner = User::factory()->create();
    $primary = identifierPatient();
    app(PatientMergeService::class)->merge($primary, identifierPatient('01010112345'), $owner);
    $row = DB::table('patient_merges')->sole();
    $snapshot = json_decode($row->duplicate_patient_snapshot, true);
    $snapshot['personal_id'] = '01010112345';
    DB::table('patient_merges')->where('id', $row->id)->update(['duplicate_patient_snapshot' => json_encode($snapshot)]);

    $this->artisan('patients:encrypt-personal-ids --dry-run')
        ->expectsOutput('Would migrate audit snapshots: 1.')->assertSuccessful();
    expect(DB::table('patient_merges')->value('duplicate_patient_snapshot'))->toContain('01010112345');
    $this->artisan('patients:encrypt-personal-ids')
        ->expectsOutput('Migrated audit snapshots: 1.')->assertSuccessful();
    $stored = json_decode(DB::table('patient_merges')->value('duplicate_patient_snapshot'), true);
    expect($stored['personal_id'])->not->toContain('01010112345')
        ->and(PatientIdentifier::decrypt($stored['personal_id']))->toBe('01010112345');
});

test('corrupt ciphertext aborts backfill without printing patient identifiers or keys', function () {
    $legacy = identifierPatient();
    $corrupt = identifierPatient();
    DB::table('patients')->where('id', $legacy->id)->update(['personal_id' => '01010112345']);
    DB::table('patients')->where('id', $corrupt->id)->update(['personal_id' => PatientIdentifier::PREFIX.'corrupt']);
    expect(Artisan::call('patients:encrypt-personal-ids'))->toBe(1);
    $output = Artisan::output();
    expect($output)->not->toContain('01010112345')->not->toContain(config('patient_identifiers.hash_key'))
        ->and(DB::table('patients')->where('id', $legacy->id)->value('personal_id'))->toBe('01010112345');
});

function identifierPatient(?string $identifier = null): Patient
{
    return Patient::create(['first_name' => 'Identifier', 'last_name' => 'Patient', 'personal_id' => $identifier]);
}

test('identifiers use randomized encryption and a hidden deterministic keyed index', function () {
    $patient = identifierPatient('01010112345');
    $raw = DB::table('patients')->where('id', $patient->id)->first();
    expect($raw->personal_id)->toStartWith(PatientIdentifier::PREFIX)->not->toContain('01010112345')
        ->and(Crypt::decryptString(substr($raw->personal_id, strlen(PatientIdentifier::PREFIX))))->toBe('01010112345')
        ->and($patient->fresh()->personal_id)->toBe('01010112345')
        ->and($patient->fresh()->toArray()['personal_id'])->toBe('01010112345')
        ->and($patient->fresh()->toArray())->not->toHaveKey('personal_id_hash')
        ->and($raw->personal_id_hash)->toBe(PatientIdentifier::hash('01010112345'))
        ->and(strlen($raw->personal_id_hash))->toBe(64)
        ->and($raw->personal_id_hash)->not->toBe(hash('sha256', '01010112345'))
        ->and(PatientIdentifier::encrypt('01010112345'))->not->toBe($raw->personal_id);
});

test('normalization preserves leading zeros and foreign identifier content', function () {
    expect(PatientIdentifier::normalize(" \t010 10-112345\u{00A0}"))->toBe('01010112345')
        ->and(PatientIdentifier::hash('010 10-112345'))->toBe(PatientIdentifier::hash('01010112345'))
        ->and(PatientIdentifier::hash('01010112345'))->not->toBe(PatientIdentifier::hash('02020212345'))
        ->and(PatientIdentifier::normalize(' ISR-100 '))->toBe('ISR-100')
        ->and(PatientIdentifier::normalize(" \t "))->toBeNull();
});

test('updates refresh the index and null clears both columns without double encryption', function () {
    $patient = identifierPatient('01010112345')->fresh();
    $ciphertext = $patient->getRawOriginal('personal_id');
    $patient->save();
    $patient->update(['last_name' => 'Updated']);
    expect($patient->fresh()->getRawOriginal('personal_id'))->toBe($ciphertext);

    $patient->update(['personal_id' => $patient->personal_id]);
    expect($patient->fresh()->personal_id)->toBe('01010112345');

    $patient->update(['personal_id' => '02020212345']);
    expect($patient->fresh()->personal_id)->toBe('02020212345')
        ->and($patient->fresh()->getRawOriginal('personal_id_hash'))->toBe(PatientIdentifier::hash('02020212345'))
        ->and(Patient::wherePersonalId('01010112345')->exists())->toBeFalse();

    $patient->update(['personal_id' => ' ']);
    expect($patient->fresh()->getRawOriginal('personal_id'))->toBeNull()
        ->and($patient->fresh()->getRawOriginal('personal_id_hash'))->toBeNull()
        ->and(identifierPatient()->personal_id)->toBeNull();
});

test('lookup is exact and keeps name phone and patient number search', function () {
    $patient = identifierPatient('01010112345');
    $patient->update(['phone' => '555123456']);
    foreach (['01010112345', '010 10-112345', 'Identifier', 'Patient Identifier', '555123456', '№ '.$patient->patient_number] as $search) {
        expect(Patient::searchForClinic($search)->sole()->is($patient))->toBeTrue();
    }
    expect(Patient::searchForClinic('010101123')->exists())->toBeFalse()
        ->and(Patient::wherePersonalId('')->exists())->toBeFalse()
        ->and(Patient::wherePersonalId('010 10-112345')->sole()->is($patient))->toBeTrue();
    $query = Patient::searchForClinic('01010112345')->toSql();
    expect($query)->not->toContain('LOWER(personal_id)')->toContain('personal_id_hash');
});

test('existing uniqueness validation uses normalized hashes in forms and the model', function () {
    $patient = identifierPatient('01010112345');
    expect(Validator::make(['personal_id' => '010 10-112345'], ['personal_id' => [new UniquePatientIdentifier]])->fails())->toBeTrue()
        ->and(Validator::make(['personal_id' => '01010112345'], ['personal_id' => [new UniquePatientIdentifier($patient->id)]])->passes())->toBeTrue()
        ->and(fn () => identifierPatient('010 10-112345'))->toThrow(ValidationException::class);
});

test('missing or weak hash secrets fail before storing an identifier', function (?string $key) {
    config(['patient_identifiers.hash_key' => $key]);
    expect(fn () => identifierPatient('01010112345'))->toThrow(RuntimeException::class, 'PERSONAL_ID_HASH_KEY');
    expect(Patient::count())->toBe(0);
    $this->artisan('patients:encrypt-personal-ids')->assertFailed();
})->with([null, '', 'short', 'base64:invalid!']);

test('key changes affect hashes and invalid encrypted values do not fall back to plaintext', function () {
    $patient = identifierPatient('01010112345');
    $old = PatientIdentifier::hash('01010112345');
    config(['patient_identifiers.hash_key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect(PatientIdentifier::hash('01010112345'))->not->toBe($old);
    $this->artisan('patients:encrypt-personal-ids')->assertFailed();
    DB::table('patients')->where('id', $patient->id)->update(['personal_id' => 'damaged']);
    expect(fn () => $patient->fresh()->personal_id)->toThrow(RuntimeException::class, 'Invalid encrypted patient identifier.');
});

test('backfill supports dry run legacy plaintext blanks native ciphertext and safe reruns', function () {
    $legacy = identifierPatient();
    $blank = identifierPatient();
    $native = identifierPatient();
    $encrypted = identifierPatient('03030312345');
    DB::table('patients')->where('id', $legacy->id)->update(['personal_id' => '010 10-112345']);
    DB::table('patients')->where('id', $blank->id)->update(['personal_id' => ' ']);
    DB::table('patients')->where('id', $native->id)->update(['personal_id' => Crypt::encryptString('02020212345')]);
    $unchangedCiphertext = $encrypted->getRawOriginal('personal_id');

    $this->artisan('patients:encrypt-personal-ids --dry-run')
        ->expectsOutput('Would migrate: 3; unchanged: 1.')->assertSuccessful();
    expect(DB::table('patients')->where('id', $legacy->id)->value('personal_id'))->toBe('010 10-112345');

    $this->artisan('patients:encrypt-personal-ids')
        ->expectsOutput('Migrated: 3; unchanged: 1.')->assertSuccessful();
    expect($legacy->fresh()->personal_id)->toBe('01010112345')
        ->and($native->fresh()->personal_id)->toBe('02020212345')
        ->and($blank->fresh()->personal_id)->toBeNull()
        ->and(Patient::wherePersonalId('01010112345')->sole()->id)->toBe($legacy->id);
    $this->artisan('patients:encrypt-personal-ids')
        ->expectsOutput('Migrated: 0; unchanged: 4.')->assertSuccessful();
    expect($encrypted->fresh()->getRawOriginal('personal_id'))->toBe($unchangedCiphertext);
});

test('a duplicate normalized legacy identifier rolls back every backfill change', function () {
    $first = identifierPatient();
    $second = identifierPatient();
    DB::table('patients')->where('id', $first->id)->update(['personal_id' => '01010112345']);
    DB::table('patients')->where('id', $second->id)->update(['personal_id' => '010 10-112345']);
    $this->artisan('patients:encrypt-personal-ids')->assertFailed();
    expect(DB::table('patients')->whereNotNull('personal_id_hash')->count())->toBe(0)
        ->and(DB::table('patients')->where('id', $first->id)->value('personal_id'))->toBe('01010112345');
});

test('patient UI shows decrypted identifiers and exact formatted search still works', function () {
    $this->actingAs(User::factory()->create());
    $patient = identifierPatient('01010112345');
    $raw = $patient->getRawOriginal('personal_id');
    Livewire::test(ViewPatient::class, ['record' => $patient->id])
        ->assertSee('01010112345')->assertDontSee($raw)->assertDontSee('personal_id_hash');
    Livewire::test(EditPatient::class, ['record' => $patient->id])
        ->assertSchemaStateSet(['personal_id' => '01010112345'])
        ->fillForm(['personal_id' => '02020212345', 'phone' => '555123456'])->call('save')->assertHasNoFormErrors();
    Livewire::test(ListPatients::class)->searchTable('020 20-212345')->assertCanSeeTableRecords([$patient]);
    expect($patient->fresh()->personal_id)->toBe('02020212345');
});

test('treatment estimate patient autocomplete uses the encrypted identifier index', function () {
    $this->actingAs(User::factory()->create());
    $patient = identifierPatient('01010112345');
    $component = Livewire::test(CreateTreatmentEstimate::class);
    $results = $component->instance()->form->getComponent('patient_id')->getSearchResults('01010112345');
    expect($results)->toHaveKey($patient->id, $patient->full_name);
});

test('patient merge keeps identifier encryption in the survivor and audit snapshot', function () {
    $owner = User::factory()->create();
    $primary = identifierPatient();
    $duplicate = identifierPatient('01010112345');
    $merged = app(PatientMergeService::class)->merge($primary, $duplicate, $owner);
    $snapshot = json_decode(DB::table('patient_merges')->sole()->duplicate_patient_snapshot, true);
    expect($merged->personal_id)->toBe('01010112345')
        ->and($snapshot['personal_id'])->toStartWith(PatientIdentifier::PREFIX)->not->toContain('01010112345')
        ->and(PatientIdentifier::decrypt($snapshot['personal_id']))->toBe('01010112345');
});
