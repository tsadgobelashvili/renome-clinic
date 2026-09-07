<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="renome-lab-segments" role="radiogroup" aria-label="{{ $getLabel() }}">
        @foreach ($getOptions() as $value => $label)
            <label>
                <input type="radio" value="{{ $value }}" name="{{ $getId() }}"
                    {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}"
                    @disabled($isDisabled() || $isOptionDisabled($value, $label))>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
</x-dynamic-component>
