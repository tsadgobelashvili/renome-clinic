<x-filament-panels::page>
    <div class="space-y-3">
        <div class="flex flex-wrap gap-2"><x-filament::button tag="a" :href="\App\Filament\Pages\ExpenseCategories::getUrl()" color="gray">{{ __('expense-categories.title') }}</x-filament::button><x-filament::button wire:click="edit">{{ __('bank-accounting.add_rule') }}</x-filament::button>{{ $this->applyRulesAction }}</div>
        <p class="text-xs text-gray-500">{{ __('bank-rules.priority_help') }}</p>
        @if($editing)
            <form wire:submit="save" class="space-y-2 rounded-xl border border-gray-200 p-3 dark:border-white/10">
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="text-xs">{{ __('bank-rules.counterparty') }}<x-filament::input.wrapper><x-filament::input wire:model="counterparty" maxlength="255" /></x-filament::input.wrapper></label>
                    <label class="text-xs">{{ __('bank-rules.keyword') }}<x-filament::input.wrapper><x-filament::input wire:model="keyword" maxlength="255" /></x-filament::input.wrapper></label>
                    <label class="text-xs">{{ __('expense-categories.category') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="categoryId" required><option value="">—</option>@foreach($categories as $category)@if($category->active || $categoryId === $category->id)<option value="{{ $category->id }}">{{ $category->name }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="text-xs">{{ __('expense-categories.subcategory') }}<x-filament::input.wrapper><x-filament::input.select wire:model="subcategoryId" :disabled="!$categoryId"><option value="">—</option>@foreach($subcategories->where('expense_category_id', $categoryId) as $subcategory)@if($subcategory->active || $subcategoryId === $subcategory->id)<option value="{{ $subcategory->id }}">{{ $subcategory->name }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                </div>
                <details><summary class="cursor-pointer text-xs text-gray-500">{{ __('bank.additional_details') }}</summary><label class="text-xs">{{ __('bank-rules.account') }}<x-filament::input.wrapper><x-filament::input wire:model="account" maxlength="255" /></x-filament::input.wrapper></label></details>
                <label class="flex items-start gap-2 text-xs"><input type="checkbox" wire:model="confirmCompanyDefault">{{ __('bank-rules.confirm_default') }}</label>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="applyExisting">{{ __('bank-rules.apply_existing') }}</label>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="active">{{ __('bank.active') }}</label>
                @foreach($errors->all() as $error)<p class="text-xs text-rose-600">{{ $error }}</p>@endforeach
                <div class="flex gap-2"><x-filament::button type="submit">{{ __('bank.save') }}</x-filament::button><x-filament::button color="gray" wire:click="$set('editing', false)">{{ __('bank.close') }}</x-filament::button></div>
            </form>
        @endif
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10"><table class="w-full text-left text-sm">
            <thead class="text-xs text-gray-500"><tr><th class="p-2">{{ __('bank-rules.counterparty') }}</th><th class="p-2">{{ __('bank-rules.keyword') }}</th><th class="p-2">{{ __('expense-categories.category') }}</th><th class="p-2">{{ __('expense-categories.subcategory') }}</th><th class="p-2">{{ __('bank.active') }}</th><th></th></tr></thead>
            <tbody>@foreach($rules as $rule)<tr wire:key="bank-rule-{{ $rule->id }}" class="even:bg-gray-50 dark:even:bg-white/5">
                <td class="p-2">{{ $rule->counterparty ?: '—' }}@if($rule->counterparty_account)<span class="block text-xs text-gray-500">{{ $rule->counterparty_account }}</span>@endif</td><td class="p-2">{{ $rule->purpose_keyword ?: '—' }}</td>
                <td class="p-2">{{ $rule->category?->name ?: __('bank-rules.uncategorized') }}</td><td class="p-2">{{ $rule->subcategory?->name ?: '—' }}</td>
                <td class="p-2"><x-filament::button size="xs" color="gray" wire:click="toggleActive({{ $rule->id }})">{{ __('bank.'.($rule->active ? 'active' : 'inactive')) }}</x-filament::button></td>
                <td class="p-2"><div class="flex gap-2"><x-filament::button size="xs" color="gray" wire:click="edit({{ $rule->id }})">{{ __('bank.edit') }}</x-filament::button>{{ ($this->deleteRuleAction)(['rule' => $rule->id]) }}</div></td>
            </tr>@endforeach</tbody>
        </table></div>
    </div>
</x-filament-panels::page>
