Captured through BogBusinessApiService for 2026-09-15 through 2026-09-16.
The 11-record JSON fixture retains the API keys, nesting, nulls, dates, and monetary values.
Names, accounts, identifiers, descriptions and references have been replaced with synthetic values.

Observed BOG fields:
- entryAmountDebit / entryAmountCredit: separate account-currency amounts.
- entryAmount: signed account-currency amount (negative outgoing, positive incoming).
- entryAmountBase and documentSourceAmount/documentDestinationAmount: not direction sources.
- entryId, entryDate, documentValueDate, documentNomination, documentProductGroup.
- senderDetails / beneficiaryDetails: name, inn, accountNumber, bankCode, bankName.

The signed-only test removes the separate debit/credit fields from fixture copies;
the captured response itself contains both separate fields and signed entryAmount.
Unknown direction codes are deliberately not guessed from product groups.
