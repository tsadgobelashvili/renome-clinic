# Patient identifier encryption

The application still reads and writes plaintext through `Patient::personal_id`. The custom cast normalizes input, encrypts with Laravel's application encrypter (`APP_KEY`), and maintains a dedicated HMAC-SHA256 blind index. `personal_id_hash` is hidden from model serialization and forms.

The identifier column is now nullable text to accommodate authenticated ciphertext. Its existing uniqueness constraint moves to nullable `char(64) personal_id_hash`. Names, phone numbers, patient numbers, and lab birth-date behavior remain unchanged. Personal-ID lookup is exact, never a ciphertext LIKE search. Whitespace and numeric hyphens are normalized; foreign identifier content such as ISR-100 is preserved.

## Local rollout

Existing data is not backfilled automatically by the schema migration. Deploy schema, key, and backfill together while patient writes are stopped. The transitional model can read legacy values, but those rows do not participate in hashed lookup until backfilled.

Keep a database backup and retain APP_KEY and PERSONAL_ID_HASH_KEY securely. Do not regenerate either key after rollout.

1. Add an independent random key to the local .env file. This PowerShell block writes it without printing the secret and refuses to replace an existing nonempty value:

```powershell
$envPath = Join-Path (Get-Location) '.env'
$envText = [IO.File]::ReadAllText($envPath)
if ($envText -match '(?m)^PERSONAL_ID_HASH_KEY=[^\r\n]+\r?$') {
    throw 'A hash key entry already exists. Preserve it; do not rotate it here.'
}
$keyBytes = New-Object byte[] 32
$keyRng = [Security.Cryptography.RandomNumberGenerator]::Create()
$keyRng.GetBytes($keyBytes)
$keyRng.Dispose()
$keyLine = 'PERSONAL_ID_HASH_KEY=base64:' + [Convert]::ToBase64String($keyBytes)
if ($envText -match '(?m)^PERSONAL_ID_HASH_KEY=\r?$') {
    $envText = [regex]::Replace($envText, '(?m)^PERSONAL_ID_HASH_KEY=\r?$', $keyLine)
} else {
    $envText = $envText.TrimEnd() + [Environment]::NewLine + $keyLine + [Environment]::NewLine
}
[IO.File]::WriteAllText($envPath, $envText, (New-Object Text.UTF8Encoding $false))
Remove-Variable keyBytes, keyLine, envText
```

2. From the project root run:

```shell
php artisan down
php artisan config:clear
php artisan migrate
php artisan patients:encrypt-personal-ids --dry-run
php artisan patients:encrypt-personal-ids
php artisan up
```

Stop if migration or either backfill invocation fails. Do not resume normal traffic with a partially prepared schema/index. Restart any long-running application workers after changing configuration.

The command processes rows in bounded chunks under a single database transaction and row locks. Dry-run performs the same validation and rolls everything back. A failure rolls back all rows. It prints migrated/unchanged counts only, never IDs, SQL bindings, ciphertext or secrets.

Already-encrypted rows are verified and skipped without re-encryption. Native Laravel ciphertext from earlier manual encryption is also recognized. Blank identifiers become null with null hashes. Duplicate normalized IDs, index/key mismatches and unreadable ciphertext abort safely. The command also encrypts personal-ID copies in historical merge audit snapshots; new snapshots already use encryption.

The schema migration refuses to roll back while encrypted/indexed identifiers remain, avoiding accidental data loss or downgrade to plaintext. Hash-key rotation is intentionally not automatic; it requires a coordinated reindexing procedure.

## Verification

Focused coverage includes encrypted raw database values, transparent model/form reads, hidden hashes, normalization, unique validation, updates/clearing, exact searches, visit and estimate selection, PDF/Word export inputs, merge snapshots, reruns, dry-run, full rollback and existing authorization/Laboratory behavior.
