<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Parent categories and child expense types in the existing registry. */
class ExpenseDimensions
{
    private ?Collection $categories = null;

    private ?Collection $subcategories = null;

    private ?Collection $bankCategories = null;

    public function reset(): void
    {
        $this->categories = null;
        $this->subcategories = null;
        $this->bankCategories = null;
    }

    public const DIRECTIONS = ['surgery' => 'ქირურგია', 'therapy' => 'თერაპია', 'orthopedics' => 'ორთოპედია',
        'laboratory' => 'ლაბორატორია', 'administration' => 'ადმინისტრაცია', 'general' => 'საერთო'];

    public const TYPES = ['salary' => 'ხელფასი', 'materials' => 'მასალები', 'rent' => 'ქირა', 'utilities' => 'კომუნალური',
        'bank_fee' => 'ბანკის საკომისიო', 'taxes' => 'გადასახადები', 'equipment' => 'ტექნიკა', 'office' => 'საოფისე',
        'it' => 'პროგრამები / IT', 'marketing' => 'რეკლამა / მარკეტინგი', 'services' => 'მომსახურება', 'other' => 'სხვა'];

    public function registry(): Collection
    {
        return $this->categories ??= ExpenseCategory::all()->keyBy('id');
    }

    public function id(string $dimension, ?string $code, ?int $parent = null): ?int
    {
        return $this->registry()->first(fn ($row) => $row->classification_dimension === $dimension && $row->classification_code === $code && $row->parent_id == $parent)?->id;
    }

    private function hierarchical(): bool
    {
        return array_key_exists('parent_id', $this->registry()->first()?->getAttributes() ?? []);
    }

    public function childOptions(?int $parent, ?int $selected = null): array
    {
        if (! $parent) {
            return [];
        }

        return $this->registry()->filter(fn ($row) => $row->classification_dimension === 'type' && $row->parent_id === $parent && ($row->active || $row->id === $selected))
            ->sortBy('sort_order')->mapWithKeys(fn ($row) => [$row->id => self::label($row)])->all();
    }

    /** Translate a legacy type to a distinct child, retaining its name and source ID. */
    public function childForType(int $parent, int $type): ?int
    {
        if (! $this->hierarchical()) {
            return $type;
        }
        $direction = $this->registry()->get($parent);
        $legacy = $this->registry()->get($type);
        if ($direction?->classification_dimension !== 'direction' || $legacy?->classification_dimension !== 'type') {
            return null;
        }
        if ($legacy->parent_id) {
            return $legacy->parent_id === $parent ? $legacy->id : null;
        }
        $existing = $this->registry()->first(fn ($row) => $row->parent_id === $parent && $row->legacy_type_id === $type);
        if ($existing) {
            return $existing->id;
        }
        $child = new ExpenseCategory;
        $child->forceFill(['parent_id' => $parent, 'legacy_type_id' => $type, 'classification_dimension' => 'type',
            'classification_code' => $legacy->classification_code, 'name' => $legacy->name,
            'active' => $legacy->active, 'sort_order' => $legacy->sort_order])->save();

        return $child->id;
    }

    /** Existing monetary rows are updated in place; incomplete classifications stay intact. */
    public function backfillHierarchy(): void
    {
        foreach (['finance_transactions', 'partner_finance_transactions', 'direct_expenses', 'bank_transactions', 'bank_categorization_rules'] as $table) {
            DB::table($table)->whereNotNull('expense_direction_id')->whereNotNull('expense_type_id')->orderBy('id')->chunkById(250, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $child = $this->childForType($row->expense_direction_id, $row->expense_type_id);
                    if ($child && $child !== $row->expense_type_id) {
                        DB::table($table)->where('id', $row->id)->update(['expense_type_id' => $child]);
                    }
                }
            });
        }
        // Small editable initial child lists, plus every historical pair above.
        foreach (['surgery' => ['materials', 'salary', 'equipment'], 'therapy' => ['materials', 'salary', 'equipment'],
            'orthopedics' => ['materials', 'salary', 'equipment'], 'laboratory' => ['materials', 'salary', 'equipment'],
            'administration' => ['salary', 'office', 'it', 'marketing'],
            'general' => ['bank_fee', 'rent', 'utilities', 'taxes', 'services', 'other']] as $code => $types) {
            foreach ($types as $type) {
                if (($parent = $this->id('direction', $code)) && ($legacy = $this->id('type', $type))) {
                    $this->childForType($parent, $legacy);
                }
            }
        }
    }

    public function options(string $dimension, ?int $selected = null): array
    {
        $rows = $this->registry()->filter(fn ($row) => $row->classification_dimension === $dimension && ($row->active || $row->id === $selected))->sortBy('sort_order');
        if ($dimension === 'type') {
            $rows = $rows->unique('name');
        }

        return $rows->mapWithKeys(fn ($row) => [$row->id => self::label($row)])->all();
    }

    public function typeIdsForFilter(int $id): array
    {
        $type = $this->registry()->get($id);

        return $this->registry()->filter(fn ($row) => $row->classification_dimension === 'type' && $row->name === $type?->name)->pluck('id')->all();
    }

    public static function label(?object $row): string
    {
        if (! $row) {
            return __('expense-dimensions.review');
        }
        $direction = $row->classification_dimension === 'direction';
        $known = $direction ? self::directionCode($row->name) : self::typeCode($row->name);

        return $known === $row->classification_code ? (($direction ? self::DIRECTIONS : self::TYPES)[$known] ?? $row->name) : $row->name;
    }

    public function labelById(?int $id): string
    {
        return self::label($this->registry()->get($id));
    }

    public function summary(Model $record): string
    {
        return $this->labelById($record->expense_direction_id).' → '.$this->labelById($record->expense_type_id);
    }

    public function validate(array $data, ?Model $record = null, bool $required = false): void
    {
        foreach (['direction', 'type'] as $dimension) {
            $field = 'expense_'.$dimension.'_id';
            $id = $data[$field] ?? null;
            $category = $id ? $this->registry()->get($id) : null;
            if (($required && ! $id) || ($id && (! $category || $category->classification_dimension !== $dimension
                || (! $category->active && $id != $record?->$field)))) {
                throw ValidationException::withMessages([$field => __('expense-dimensions.invalid')]);
            }
        }
        if ($this->hierarchical() && filled($data['expense_type_id'] ?? null)) {
            $child = $this->registry()->get($data['expense_type_id']);
            $parent = $data['expense_direction_id'] ?? null;
            $unchanged = $record && $record->expense_type_id == $child?->id && $record->expense_direction_id == $parent;
            if (! $unchanged && $parent && ! $this->registry()->get($parent)?->active) {
                throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
            }
            if ((! $child?->parent_id || $child->parent_id != $parent) && (! $unchanged || $required)) {
                throw ValidationException::withMessages(['expense_type_id' => __('expense-dimensions.invalid')]);
            }
        }
    }

    public static function directionCode(?string $name): ?string
    {
        return match (mb_strtolower(trim($name ?? ''))) {
            'ქირურგია', 'ქირურგი', 'surgery', 'surgeon' => 'surgery',
            'თერაპია', 'თერაპევტი', 'therapy', 'therapist' => 'therapy',
            'ორთოპედია', 'ორთოპედი', 'orthopedics', 'orthopedist' => 'orthopedics',
            'ლაბორატორია', 'laboratory', 'lab technician' => 'laboratory',
            'ადმინისტრაცია', 'ადმინისტრაციული', 'ადმინისტრატორი', 'administrative', 'administrator', 'administration' => 'administration',
            'საერთო', 'general', 'general clinical' => 'general',
            default => null,
        };
    }

    public static function typeCode(?string $name): ?string
    {
        return match (mb_strtolower(trim($name ?? ''))) {
            'salary', 'doctor_salary', 'lab_salary', 'technician', 'ხელფასი', 'ხელფასები', 'salaries' => 'salary',
            'materials', 'supplier', 'მასალა', 'მასალები', 'მასალები / მომწოდებელი' => 'materials',
            'rent', 'ქირა' => 'rent', 'utilities', 'კომუნალური' => 'utilities',
            'bank_fee', 'bank_fees', 'ბანკის საკომისიო' => 'bank_fee',
            'taxes', 'payroll_tax', 'გადასახადები', 'სახელფასო გადასახადი / პენსია' => 'taxes',
            'equipment', 'მოწყობილობა', 'ტექნიკა' => 'equipment', 'office', 'ოფისი', 'საოფისე' => 'office',
            'it', 'პროგრამები / it' => 'it', 'marketing', 'მარკეტინგი', 'რეკლამა / მარკეტინგი' => 'marketing',
            'services', 'მომსახურება' => 'services', 'other', 'other_expense', 'operating_expense', 'სხვა', 'სხვა საოპერაციო ხარჯი' => 'other',
            default => null,
        };
    }

    /** Only explicit legacy names/codes are mapped. Unknowns remain NULL (review required). */
    public function infer(array $row): array
    {
        $parent = $this->registry()->get($row['expense_category_id'] ?? null);
        if (! $parent && ! empty($row['bank_category_id'])) {
            $bankCategories = $this->bankCategories ??= DB::table('bank_categories')->get()->keyBy('id');
            $parent = $this->registry()->get($bankCategories->get($row['bank_category_id'])?->expense_category_id);
        }
        $children = $this->subcategories ??= DB::table('expense_subcategories')->get()->keyBy('id');
        $child = $children->get($row['expense_subcategory_id'] ?? null);
        $parentDirection = self::directionCode($parent?->name);
        $childDirection = self::directionCode($child?->name);
        $direction = $parentDirection && $childDirection && $parentDirection !== $childDirection ? null : ($parentDirection ?? $childDirection);
        if (! $parentDirection && ! $childDirection) {
            $direction = self::directionCode($row['category'] ?? null);
        }
        $parentType = self::typeCode($parent?->reporting_code ?? $parent?->name);
        $childType = self::typeCode($child?->name);
        $type = $parentType && $childType && $parentType !== $childType && $parentType !== 'other'
            ? null : ($childType ?? $parentType ?? self::typeCode($row['category'] ?? null));
        if (($row['operation_type'] ?? '') === 'COM' && ($row['direction'] ?? '') === 'outflow') {
            $type = 'bank_fee';
        }
        if ($type === 'bank_fee') {
            $direction ??= 'general';
        }
        if (! empty($row['lab_salary_settlement_id']) || in_array($row['category'] ?? '', ['lab_salary', 'technician'], true)) {
            $direction = 'laboratory';
            $type = 'salary';
        }
        if (! empty($row['payroll_entry_id']) || ! empty($row['employee_salary_settlement_id'])) {
            $table = ! empty($row['payroll_entry_id']) ? 'payroll_entries' : 'employee_salary_settlements';
            $id = $row['payroll_entry_id'] ?? $row['employee_salary_settlement_id'];
            $position = DB::table($table.' as s')->join('employees as e', 'e.id', '=', 's.employee_id')
                ->join('employee_positions as p', 'p.id', '=', 'e.position_id')->where('s.id', $id)->select('p.name', 'p.is_technician')->first();
            $direction ??= $position?->is_technician ? 'laboratory' : self::directionCode($position?->name);
            $type = 'salary';
        }
        if (! empty($row['salary_settlement_id'])) {
            $specialty = DB::table('salary_settlements as s')->join('doctors as d', 'd.id', '=', 's.doctor_id')
                ->where('s.id', $row['salary_settlement_id'])->value('d.specialty');
            $direction ??= self::directionCode($specialty);
            $type = 'salary';
        }
        if (! empty($row['salary_payout_allocation_id'])) {
            $specialty = DB::table('salary_payout_allocations as a')->join('salary_payouts as p', 'p.id', '=', 'a.salary_payout_id')
                ->join('salary_settlements as s', 's.id', '=', 'p.salary_settlement_id')->join('doctors as d', 'd.id', '=', 's.doctor_id')
                ->where('a.id', $row['salary_payout_allocation_id'])->value('d.specialty');
            $direction ??= self::directionCode($specialty);
            $type = 'salary';
        } elseif ($type === 'salary' && ! empty($row['doctor_id'])) {
            $direction ??= self::directionCode(DB::table('doctors')->where('id', $row['doctor_id'])->value('specialty'));
        }

        $parentId = $this->id('direction', $direction);
        $typeId = $this->id('type', $type);

        return ['expense_direction_id' => $parentId, 'expense_type_id' => $parentId && $typeId ? $this->childForType($parentId, $typeId) : $typeId];
    }

    public function apply(Model $record): void
    {
        // Keep original legacy links; normalize system-generated classifications separately.
        if (! $record->expense_direction_id && ! $record->expense_type_id
            && ! $record->isDirty('expense_direction_id') && ! $record->isDirty('expense_type_id')) {
            $record->forceFill($this->infer($record->getAttributes()));
            // An unknown department is preserved for review, not guessed or dropped.
            if (! $record->expense_direction_id || ! $record->expense_type_id) {
                return;
            }
        }
        // Compatibility for trusted legacy model callers; form/service validation is strict.
        $type = $this->registry()->get($record->expense_type_id);
        if ($record->expense_direction_id && $type?->classification_dimension === 'type' && ! $type->parent_id) {
            $record->expense_type_id = $this->childForType($record->expense_direction_id, $type->id);
        }
        $this->validate($record->getAttributes(), $record->exists ? $record->replicate()->forceFill($record->getOriginal()) : null);
    }

    public function backfill(): array
    {
        $counts = [];
        foreach (['finance_transactions', 'partner_finance_transactions', 'direct_expenses', 'bank_transactions', 'bank_categorization_rules'] as $table) {
            $counts[$table] = 0;
            DB::table($table)->where(fn ($q) => $q->whereNull('expense_direction_id')->orWhereNull('expense_type_id'))->orderBy('id')
                ->chunkById(250, function ($rows) use ($table, &$counts) {
                    foreach ($rows as $row) {
                        $inferred = $this->infer((array) $row);
                        $data = array_filter($inferred, fn ($id, $key) => $id !== null && $row->$key === null, ARRAY_FILTER_USE_BOTH);
                        if ($data !== []) {
                            DB::table($table)->where('id', $row->id)->update($data);
                            $counts[$table]++;
                        }
                    }
                });
        }

        return $counts;
    }
}
