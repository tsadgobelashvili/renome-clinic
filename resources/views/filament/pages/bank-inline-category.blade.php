<form wire:submit="saveInlineClassification" class="flex flex-col flex-wrap gap-2 sm:flex-row sm:items-end">
    @foreach(['direction' => ['expenseDirectionId', $directionOptions], 'type' => ['expenseTypeId', $typeOptions]] as $dimension => [$property, $options])
        <label class="w-full text-xs sm:w-64">{{ __('expense-dimensions.'.$dimension) }}
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="{{ $property }}" required>
                <option value="">{{ __('expense-dimensions.review') }}</option>
                @foreach($options as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
    @endforeach
    <label class="flex items-center gap-2 py-2 text-xs">
        <input type="checkbox" wire:model="rememberRule" @disabled(blank($transactionDetail->counterparty_name) && blank($transactionDetail->counterparty_account) && blank($ruleKeyword))>
        {{ __('bank-rules.remember') }}
    </label>
    <label class="flex items-center gap-2 py-2 text-xs text-gray-500" title="{{ __('bank-accounting.exclusion_help') }}">
        <input type="checkbox" @checked($transactionDetail->exclude_from_pnl)
            wire:change="markAlreadyRecorded({{ $transactionDetail->id }}, $event.target.checked)"
            wire:loading.attr="disabled" wire:target="markAlreadyRecorded">
        {{ __('bank-accounting.already_recorded') }}
    </label>
    <x-filament::button type="submit" size="xs" class="self-start sm:self-auto" wire:loading.attr="disabled" wire:target="saveInlineClassification">{{ __('bank.save') }}</x-filament::button>
    @if($errors->any())<div class="w-full" role="alert">@foreach($errors->all() as $error)<p class="text-xs text-rose-600">{{ $error }}</p>@endforeach</div>@endif
</form>
