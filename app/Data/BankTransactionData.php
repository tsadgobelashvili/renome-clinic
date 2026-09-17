<?php

namespace App\Data;

use App\Services\Bank\BogAccountIdentifier;
use App\Services\Bank\BogCommission;
use Illuminate\Support\Facades\Validator;

/** Normalized input shared by Excel statement imports and the API adapter. */
final readonly class BankTransactionData
{
    public array $attributes;

    public function __construct(array $attributes)
    {
        $attributes = array_replace([
            'bank' => 'BOG', 'account_identifier' => null, 'value_date' => null,
            'operation_id' => null, 'operation_type' => null, 'reference' => null,
            'counterparty_name' => null, 'counterparty_account' => null, 'description' => null,
            'bank_fee' => '0.00', 'gross_amount' => null, 'balance_after' => null, 'raw_data' => null,
        ], $attributes);
        if ($attributes['bank'] === 'BOG' && $attributes['operation_id'] !== null && is_string($attributes['account_identifier'])) {
            $attributes['account_identifier'] = BogAccountIdentifier::normalize($attributes['account_identifier']);
        }
        $attributes = array_replace($attributes, BogCommission::metadata($attributes));
        $rules = [
            'bank' => 'required|string|max:20', 'transaction_date' => 'required|date_format:Y-m-d H:i:s',
            'value_date' => 'nullable|date_format:Y-m-d', 'direction' => 'required|in:inflow,outflow',
            'currency' => 'required|regex:/^[A-Z]{3}$/', 'amount' => ['required', 'regex:/^\d{1,13}\.\d{2}$/', 'numeric', 'gt:0'],
            'bank_fee' => ['required', 'regex:/^\d{1,13}\.\d{2}$/'],
            'gross_amount' => ['nullable', 'regex:/^\d{1,13}\.\d{2}$/'],
            'balance_after' => ['nullable', 'regex:/^-?\d{1,13}\.\d{2}$/'],
            'description' => 'nullable|string|max:20000', 'raw_data' => 'nullable|array',
        ];
        foreach (['account_identifier', 'operation_id', 'operation_type', 'reference', 'counterparty_name', 'counterparty_account'] as $field) {
            $rules[$field] = 'nullable|string|max:255';
        }
        $this->attributes = Validator::make($attributes, $rules)->validate();
    }

    public function fingerprint(): string
    {
        $fields = ['bank', 'account_identifier', 'transaction_date', 'value_date', 'direction', 'amount', 'currency', 'operation_type', 'reference', 'counterparty_name', 'counterparty_account', 'description'];

        return hash('sha256', json_encode(array_map(fn ($field) => $this->attributes[$field], $fields), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function deduplicationKey(): string
    {
        if ($this->attributes['operation_id'] !== null) {
            return self::operationKey($this->attributes['bank'], $this->attributes['account_identifier'], $this->attributes['currency'], $this->attributes['operation_id']);
        }

        return hash('sha256', 'fingerprint:'.$this->fingerprint());
    }

    public static function operationKey(string $bank, ?string $account, string $currency, string $id): string
    {
        return hash('sha256', json_encode(['operation', $bank, $bank === 'BOG' ? BogAccountIdentifier::normalize($account) : $account, $currency, $id], JSON_THROW_ON_ERROR));
    }
}
