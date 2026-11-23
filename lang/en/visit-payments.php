<?php

return [
    'ct' => 'CT payment → Clinic Cash', 'treatment' => 'Other treatment payment → Israeli Finance',
    'ct_discount' => 'CT share of the visit discount',
    'allocation_help' => 'Enter both service allocations in the visit currency; exclude retail products. Their sum must equal the service payment. Payment rows fund CT first in the listed order, then other treatments.',
    'invalid_allocation' => 'Specify an exact CT / other treatment allocation and discount allocation. The amounts must reconcile with the service payment.',
    'exceeds_bucket' => 'The allocated payment exceeds the outstanding balance of that service.',
    'legacy' => 'This visit has older receipts without a reliable service allocation. An owner must review them before accepting another CT payment; no historical receipt has been moved or cancelled.',
    'changed_request' => 'This confirmation was already used with different payment details. Reopen the payment form for a separate payment.',
];
