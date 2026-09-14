<div class="space-y-3">
    <section class="renome-visits-toolbar flex-wrap" aria-label="{{ __('bank.filters') }}">
        <div class="renome-visits-toolbar__period">
            <label class="renome-visits-toolbar__date"><input type="date" wire:model.live="dateFrom" aria-label="{{ __('bank.from') }}"></label><span>—</span>
            <label class="renome-visits-toolbar__date"><input type="date" wire:model.live="dateUntil" aria-label="{{ __('bank.to') }}"></label>
        </div>
        <label class="renome-visits-toolbar__doctor"><select wire:model.live="period" aria-label="{{ __('bank.period') }}">@foreach(__('finance-overview.periods') as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        <label class="renome-visits-toolbar__doctor"><select wire:model.live="businessSource" title="{{ __('finance-overview.business_source_help') }}" aria-label="{{ __('finance-overview.source') }}">@foreach(['all', 'clinic', 'israeli'] as $value)<option value="{{ $value }}">{{ __('finance-overview.'.$value) }}</option>@endforeach</select></label>
        <label class="renome-visits-toolbar__doctor"><select wire:model.live="overviewCurrency" aria-label="{{ __('bank.currency') }}"><option value="">{{ __('bank.all_currencies') }}</option>@foreach($overviewCurrencies as $code)<option>{{ $code }}</option>@endforeach</select></label>
    </section>
    @if($dateError)<p role="alert" class="text-sm text-rose-600">{{ $dateError }}</p>@endif

    @foreach(['liquidity' => ['cash', 'bank'], 'performance' => ['revenue', 'expenses', 'profit', 'cash_outflow']] as $section => $cards)
        <section aria-label="{{ __('finance-overview.'.$section) }}" class="space-y-1">
            <h2 class="text-xs font-medium text-gray-500">{{ $section === 'liquidity' ? __('finance-overview.current_balance') : ($dateError ? __('finance-overview.selected_period') : \Carbon\Carbon::parse($dateFrom)->format('d.m.Y').' — '.\Carbon\Carbon::parse($dateUntil)->format('d.m.Y')) }}</h2>
            <div class="grid gap-2 {{ $section === 'liquidity' ? 'sm:grid-cols-2' : 'sm:grid-cols-2 xl:grid-cols-4' }}">
                @foreach($cards as $metric)
                    <button type="button" data-finance-card="{{ $metric }}" wire:click="selectOverviewCard('{{ $metric }}')" aria-expanded="{{ $overviewCard === $metric ? 'true' : 'false' }}" aria-controls="finance-overview-details"
                        class="rounded-xl border bg-white p-3 text-left transition-colors hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-white/5 {{ $overviewCard === $metric ? 'border-primary-500 ring-1 ring-primary-500' : 'border-gray-200 dark:border-white/10' }}">
                        <div class="flex items-center justify-between text-xs font-medium text-gray-500"><span>{{ __('finance-overview.'.$metric) }}</span><span aria-hidden="true">{{ $overviewCard === $metric ? '▾' : '▸' }}</span></div>
                        @foreach($figures as $code => $values)
                            <div class="mt-1 flex items-baseline justify-between gap-3 {{ in_array($metric, ['expenses', 'cash_outflow']) || ($metric === 'profit' && $values[$metric] < 0) ? 'text-rose-600 dark:text-rose-400' : (in_array($metric, ['revenue', 'profit']) ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-950 dark:text-white') }}">
                                <span class="text-xs">{{ $code }}</span><span class="text-right text-lg font-semibold tabular-nums">{{ $values[$metric] === null ? '—' : number_format($values[$metric], 2) }}</span>
                            </div>
                        @endforeach
                        @if($metric === 'bank')
                            @foreach($liquidity['accounts'] as $account)
                                @continue($overviewCurrency !== '' && $overviewCurrency !== $account->currency)
                                @include('filament.pages.bank-balance-updated', ['balance' => $account])
                            @endforeach
                        @endif
                    </button>
                @endforeach
            </div>
        </section>
    @endforeach

    @if($overviewCard !== '')
        <section id="finance-overview-details" class="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-white/10" wire:key="overview-{{ $overviewCard }}">
            <div class="flex items-center justify-between gap-2"><h2 class="text-sm font-semibold">{{ __('finance-overview.'.$overviewCard) }}</h2><x-filament::button size="xs" color="gray" wire:click="selectOverviewCard('')">{{ __('bank.close') }}</x-filament::button></div>
            @if(in_array($overviewCard, ['cash', 'bank', 'available']))
                @if(in_array($overviewCard, ['cash', 'available']))
                    @foreach($liquidity['cash'] as $code => $cash)
                        @continue($overviewCurrency !== '' && $overviewCurrency !== $code)
                        <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                            <div class="flex flex-wrap justify-between gap-2"><span>{{ __('finance-overview.cash') }} · {{ $code }} · {{ $cash['as_of'] }}</span><strong class="tabular-nums">{{ number_format($cash['amount'], 2) }} {{ $code }}</strong></div>
                            <p class="mt-1 text-xs text-gray-500">@if($businessSource === 'all'){{ __('finance-overview.clinic') }} {{ number_format($cash['clinic'], 2) }} + {{ __('finance-overview.israeli') }} {{ number_format($cash['israeli'], 2) }}@else{{ __('finance-overview.'.$businessSource) }}@endif</p>
                            <p class="mt-1 text-xs tabular-nums text-gray-500">{{ __('finance-overview.opening') }} {{ number_format($cash['opening'], 2) }} + {{ __('finance-overview.cash_inflows') }} {{ number_format($cash['received'], 2) }} − {{ __('finance-overview.cash_outflow') }} {{ number_format($cash['spent'], 2) }} = {{ number_format($cash['amount'], 2) }} {{ $code }}</p>
                        </div>
                    @endforeach
                @endif
                @if(in_array($overviewCard, ['bank', 'available']))
                    @forelse($liquidity['accounts'] as $account)
                        @continue($overviewCurrency !== '' && $overviewCurrency !== $account->currency)
                        <div class="rounded-lg bg-gray-50 px-3 py-2 text-sm dark:bg-white/5">
                            <div class="flex flex-wrap justify-between gap-2"><span>{{ $account->bank }} · {{ $account->account_identifier ?: '—' }} · {{ $account->currency }}</span><strong class="tabular-nums">{{ $account->reported_balance === null ? '—' : number_format($account->reported_balance, 2) }} {{ $account->currency }}</strong></div>
                            @include('filament.pages.bank-balance-updated', ['balance' => $account])
                        </div>
                    @empty<p class="text-xs text-gray-500">{{ __('finance-overview.no_bank_balance') }}</p>@endforelse
                    <x-filament::button tag="a" :href="\App\Filament\Pages\Bank::getUrl()" size="xs" color="gray">{{ __('bank.transaction_history') }}</x-filament::button>
                @endif
            @endif
            @if($overviewCard === 'profit')
                @foreach($figures as $code => $values)
                    <dl class="max-w-md space-y-1 text-sm">
                        @foreach(['revenue' => '', 'expenses' => '−', 'profit' => '='] as $metric => $sign)
                            <div class="flex justify-between gap-5 {{ $metric === 'profit' ? 'border-t border-gray-200 pt-2 font-semibold dark:border-white/10' : '' }}"><dt>{{ $sign }} {{ __('finance-overview.'.$metric) }}</dt><dd class="tabular-nums">{{ number_format($values[$metric], 2) }} {{ $code }}</dd></div>
                        @endforeach
                    </dl>
                @endforeach
            @elseif($overviewCard === 'cash_outflow')
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($outflowGroups->groupBy('group_key') as $group => $rows)
                        <div wire:key="cash-outflow-{{ $group }}">
                            <button type="button" wire:click="selectCashOutflowGroup('{{ $group }}')" aria-expanded="{{ $overviewCategory === $group ? 'true' : 'false' }}" class="flex w-full justify-between gap-3 rounded-lg px-2 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                <span>{{ $overviewCategory === $group ? '▾' : '▸' }} {{ __('finance-overview.outflow_groups.'.$group) }}</span>
                                <span class="flex flex-wrap gap-3 text-right font-semibold tabular-nums {{ $group === 'expenses' ? 'text-rose-600' : 'text-gray-700 dark:text-gray-300' }}">@foreach($rows as $row)<span>{{ number_format($row->amount, 2) }} {{ $row->currency }}</span>@endforeach</span>
                            </button>
                            @if($overviewCategory === $group && $overviewDetails)@include('filament.pages.finance-cash-outflow-entries', ['details' => $overviewDetails])@endif
                        </div>
                    @empty<p class="py-2 text-sm text-gray-500">{{ __('bank.empty') }}</p>@endforelse
                </div>
            @elseif($overviewCard === 'expenses')
                <div class="divide-y divide-gray-100 dark:divide-white/5">
                    @forelse($expenseGroups->groupBy('category_key') as $key => $rows)
                        <div wire:key="expense-group-{{ $key }}">
                            <button type="button" wire:click="selectExpenseCategory('{{ $key }}')" aria-expanded="{{ $overviewCategory === $key ? 'true' : 'false' }}" class="flex w-full flex-wrap items-center justify-between gap-2 rounded-lg px-2 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5">
                                <span><span aria-hidden="true">{{ $overviewCategory === $key ? '▾' : '▸' }}</span> {{ $rows->first()->category_name ?: __($key === 'uncategorized' ? 'bank-rules.uncategorized' : 'finance-overview.other_expense') }}</span>
                                <span class="flex flex-wrap gap-3 text-right font-semibold tabular-nums text-rose-600 dark:text-rose-400">@foreach($rows as $row)<span>{{ number_format($row->amount, 2) }} {{ $row->currency }}</span>@endforeach</span>
                            </button>
                            @if($overviewCategory === $key)
                                @if($expenseSubgroups->every(fn ($row) => $row->subcategory_key === 'none'))
                                    @if($overviewDetails)@include('filament.pages.finance-overview-entries', ['details' => $overviewDetails])@endif
                                @else
                                    <div class="ml-3 border-l border-gray-100 pl-2 dark:border-white/10">
                                        @foreach($expenseSubgroups->groupBy('subcategory_key') as $subkey => $subrows)
                                            <div wire:key="expense-subgroup-{{ $key }}-{{ $subkey }}">
                                                <button type="button" wire:click="selectExpenseSubcategory('{{ $subkey }}')" aria-expanded="{{ $overviewSubcategory === $subkey ? 'true' : 'false' }}" class="flex w-full justify-between gap-2 rounded-lg px-2 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5"><span>{{ $overviewSubcategory === $subkey ? '▾' : '▸' }} {{ $subrows->first()->subcategory_name ?: __('bank-rules.no_subcategory') }}</span><span class="flex gap-3 text-right font-semibold tabular-nums text-rose-600">@foreach($subrows as $subrow)<span>{{ number_format($subrow->amount, 2) }} {{ $subrow->currency }}</span>@endforeach</span></button>
                                                @if($overviewSubcategory === $subkey && $overviewDetails)@include('filament.pages.finance-overview-entries', ['details' => $overviewDetails])@endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            @endif
                        </div>
                    @empty<p class="py-2 text-sm text-gray-500">{{ __('bank.empty') }}</p>@endforelse
                </div>
            @elseif($overviewDetails)
                @if($overviewCard === 'cash')<p class="text-xs text-gray-500">{{ __('finance-overview.clinic') }} · {{ __('finance-overview.movements') }}</p>@endif
                @include('filament.pages.finance-overview-entries', ['details' => $overviewDetails])
            @endif
        </section>
    @endif
</div>
