<?php

return [
    'title' => 'Match RS purchases', 'confirm' => 'Confirm matching', 'saved' => 'RS matching updated',
    'status' => 'RS status', 'all' => 'RS: All', 'matched' => 'RS matched', 'partial' => 'RS partial', 'unmatched' => 'RS unmatched',
    'search' => 'Supplier, tax code or document number', 'suggestions' => 'Suggested documents', 'selected' => 'Selected documents',
    'document' => 'Document', 'amount' => 'Payment portion (GEL)', 'total' => 'RS document total', 'bank' => 'Bank debit',
    'allocated' => 'Allocated', 'unallocated' => 'Unallocated', 'difference' => 'Bank − RS difference', 'add' => 'Add', 'remove' => 'Remove',
    'empty' => 'No documents found.', 'clear' => 'Remove all matches', 'saved_breakdown' => 'Current saved allocation',
    'override_help' => 'RS allocation is used for expense statistics while this match exists. Unmatch to use the manual category below.',
    'help' => 'Suggestions are not confirmed automatically. For part payments, enter the portion paid for each document; its item directions are allocated proportionally. No additional expense is created.',
    'difference_help' => 'Differences stay unallocated. If selected portions exceed the bank debit, allocation remains unresolved until you adjust them.',
    'remove_help' => 'Remove documents, then confirm to unmatch. Normal manual categorization will apply again.',
    'invalid_document' => 'Choose valid RS documents and positive payment portions within each document’s remaining unpaid total. Returns/negative lines require manual review.',
    'invalid_bank' => 'RS matching supports positive GEL purchase debits only; transfers, settlements and bank commissions are not purchase expenses.',
];
