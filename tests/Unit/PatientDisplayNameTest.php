<?php

use App\Models\Patient;

test('patient display names capitalize initials without changing stored or intentional casing', function () {
    $patient = new Patient;
    $patient->setRawAttributes([
        'first_name' => 'sharon anne-Marie',
        'last_name' => 'mcDonald O’NEILL',
        'lab_display_name' => 'avraham cohen',
    ], true);

    expect($patient->full_name)->toBe('Sharon Anne-Marie McDonald O’NEILL')
        ->and($patient->lab_name)->toBe('Avraham Cohen')
        ->and($patient->lab_selection_label)->toBe('Avraham Cohen')
        ->and($patient->getAttributes()['first_name'])->toBe('sharon anne-Marie')
        ->and($patient->getAttributes()['last_name'])->toBe('mcDonald O’NEILL')
        ->and($patient->isDirty())->toBeFalse()
        ->and(Patient::formatDisplayName('éva müller'))->toBe('Éva Müller')
        ->and(Patient::formatDisplayName('ანა მაისურაძე'))->toBe('ანა მაისურაძე');
});
