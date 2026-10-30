<?php

return [
    // Reviewed whole-service identifiers, normalized with the existing LOWER/TRIM
    // catalog convention. Never add fragments, patterns or dental treatment names.
    // Lowest-priority PAYROLL projection only: no catalog/mapping/visit writes and
    // no changes to clinical classification, statistics or receipt attribution.
    'manual_salary_radiology_names' => [
        '3D CT', 'CBCT', '3D tomography', '3D ტომოგრაფია', '3დ ტომოგრაფია',
        'ტომოგრაფია 3D', 'ტომოგრაფია 3დ',
        'ტომოგრაფია 3D', 'ტომოგრაფია 3დ',
        'tomography', 'ტომოგრაფია', 'კომპიუტერული ტომოგრაფია',
        'radiology', 'რადიოლოგია', 'radiography', 'რენტგენოგრაფია', 'რენტგენი',
        'panorama', 'პანორამა', 'panoramic x-ray', 'პანორამული რენტგენი',
        'orthopantomogram', 'ორთოპანტომოგრამა',
    ],
];
