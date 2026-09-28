<?php

namespace App\Support;

use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;

final class CashboxMovementPresentation
{
    public static function color(CashboxTransaction $transaction): string
    {
        return match ($transaction->type) {
            'expense' => 'danger',
            'patient_payment', 'other_income', 'product_sale' => 'success',
            default => 'gray',
        };
    }

    public static function type(CashboxTransaction $transaction): string
    {
        return match (self::color($transaction)) {
            'danger' => 'ხარჯი',
            'success' => 'შემოსავალი',
            default => CashboxTransaction::TYPE_LABELS[$transaction->type] ?? $transaction->type,
        };
    }

    public static function category(CashboxTransaction $transaction): string
    {
        if ($transaction->type === 'expense') {
            return $transaction->expenseClassification();
        }

        $finance = $transaction->financeTransaction;
        $category = $finance?->expenseCategory?->name ?? (FinanceTransaction::CATEGORIES[$finance?->category ?? ''] ?? null);

        return $category ? implode(' → ', array_filter([$category, $finance?->expenseSubcategory?->name])) : '—';
    }

    public static function description(CashboxTransaction $transaction): string
    {
        $description = $transaction->financeTransaction?->description ?? $transaction->description;

        $parts = [];
        if (in_array($transaction->type, ['patient_payment', 'product_sale'], true)) {
            $patient = $transaction->patient;
            if ($transaction->type === 'product_sale') {
                $patient ??= $transaction->productSale?->patient;
                foreach ($transaction->productSale?->items ?? [] as $item) {
                    $parts[] = ($item->product?->name ?: 'პროდუქტი #'.$item->product_id).' × '.$item->quantity;
                }
            }
            if ($patient) {
                array_unshift($parts, $patient->full_name);
            }
        }
        if (filled($description)) {
            $parts[] = $description;
        }

        return $parts ? implode(' · ', $parts) : '—';
    }

    public static function sign(CashboxTransaction $transaction): string
    {
        return in_array($transaction->type, ['expense', 'cash_withdrawal', 'cash_transfer_out'], true) ? '−' : '+';
    }
}
