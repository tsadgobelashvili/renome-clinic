<?php

namespace App\Services;

use App\Models\ProductCategory;

class PurchaseCategoryResolver
{
    public function resolve(string $productName, ?string $importedCategory = null): ProductCategory
    {
        if (filled($importedCategory)) {
            $mapped = $this->categoryFromLabel($importedCategory);
            if ($mapped) {
                return $mapped;
            }
        }

        $name = mb_strtolower($productName);
        $slug = match (true) {
            $this->contains($name, ['implant', 'იმპლანტ', 'abutment', 'აბატმენტ', 'sinus lift']) => 'surgery',
            $this->contains($name, ['glove', 'ხელთათ', 'mask', 'ნიღაბ', 'suction', 'ejector', 'bib', 'სალფეთქ']) => 'general-consumables',
            $this->contains($name, ['orthodont', 'ორთოდონტ', 'crown', 'გვირგვინ']) => 'orthopedics',
            $this->contains($name, ['steril', 'სტერილ', 'autoclave']) => 'sterilization',
            $this->contains($name, ['office', 'printer', 'paper', 'საკანცელარ']) => 'office',
            $this->contains($name, ['zircon', 'zirconia', 'pmma', 'exocad', 'ცირკონ']) => 'laboratory',
            default => ProductCategory::NEEDS_REVIEW_SLUG,
        };

        return ProductCategory::query()->where('slug', $slug)->firstOrFail();
    }

    private function categoryFromLabel(string $label): ?ProductCategory
    {
        $label = mb_strtolower(trim($label));
        $slug = match ($label) {
            'surgery', 'implantology', 'ქირურგია', 'იმპლანტოლოგია' => 'surgery',
            'orthopedics', 'ორთოპედია' => 'orthopedics',
            'therapy', 'თერაპია' => 'therapy',
            'general consumables', 'consumables', 'disposables', 'სახარჯი მასალები' => 'general-consumables',
            'office', 'ოფისი' => 'office',
            'laboratory', 'lab', 'ლაბორატორია' => 'laboratory',
            'sterilization', 'სტერილიზაცია' => 'sterilization',
            'other', 'სხვა' => 'other',
            default => null,
        };

        return $slug ? ProductCategory::query()->where('slug', $slug)->first() : null;
    }

    /** @param array<int, string> $needles */
    private function contains(string $value, array $needles): bool
    {
        return collect($needles)->contains(fn (string $needle): bool => str_contains($value, $needle));
    }
}
