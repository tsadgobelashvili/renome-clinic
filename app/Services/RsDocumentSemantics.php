<?php

namespace App\Services;

/** Explicit source text only. Numeric RS enums require a separately verified mapping. */
class RsDocumentSemantics
{
    public const STATUS_HEADERS = ['status', 'document status', 'rs status', 'სტატუსი'];

    public const TYPE_HEADERS = ['document type'];

    public const WAYBILL_HEADERS = ['waybill type', 'ზედნადების ტიპი'];

    public const WAYBILL_TYPES = ['ტრანსპორტირებით', 'ტრანსპორტირების გარეშე', 'ქვე-ზედნადები'];

    public const CANCELLED = ['cancelled', 'canceled', 'deleted', 'invalid', 'გაუქმებული', 'წაშლილი', 'არავალიდური'];

    public const RETURNS = ['return', 'credit', 'credit note', 'correction', 'დაბრუნება', 'კორექტირება'];

    public function kind(mixed $status, mixed $type, mixed $waybillType = null): string
    {
        if (in_array($this->normalize($status), self::CANCELLED, true)) {
            return 'cancelled';
        }
        if (in_array($this->normalize($type), self::RETURNS, true) || in_array($this->normalize($status), self::RETURNS, true)
            || in_array($this->normalize($waybillType), self::RETURNS, true)) {
            return 'return';
        }
        if (! in_array($this->normalize($waybillType), ['', ...self::WAYBILL_TYPES], true)
            || ! in_array($this->normalize($status), ['', 'active', 'completed', 'აქტიური', 'დასრულებული'], true)
            || ! in_array($this->normalize($type), ['', 'purchase', 'normal purchase', 'შესყიდვა'], true)) {
            return 'review'; // Includes undocumented numeric status/type enums.
        }

        return 'purchase';
    }

    public function payloadKind(?array $payload): string
    {
        $kind = $payload['document_kind'] ?? 'purchase';
        foreach ($payload['source_rows'] ?? [] as $row) {
            $status = $type = $waybillType = null;
            foreach ($row['raw']['headers'] ?? [] as $index => $header) {
                if (in_array($this->normalize($header), self::STATUS_HEADERS, true)) {
                    $status = $row['raw']['cells'][$index] ?? null;
                }
                if (in_array($this->normalize($header), self::TYPE_HEADERS, true)) {
                    $type = $row['raw']['cells'][$index] ?? null;
                }
                if (in_array($this->normalize($header), self::WAYBILL_HEADERS, true)) {
                    $waybillType = $row['raw']['cells'][$index] ?? null;
                }
            }
            $found = $this->kind($status, $type, $waybillType);
            if ($found === 'cancelled') {
                return $found;
            }
            if ($found === 'return') {
                $kind = $found;
            } elseif ($found === 'review' && $kind === 'purchase') {
                $kind = $found;
            }
        }

        return $kind;
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
