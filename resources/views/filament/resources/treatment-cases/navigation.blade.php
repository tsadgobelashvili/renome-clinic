@php
    $sections = [
        'index' => ['კატალოგი', \App\Filament\Resources\TreatmentCases\Pages\ListTreatmentCases::class],
        'categories' => ['კატეგორიები', \App\Filament\Resources\TreatmentCases\Pages\ManageCategories::class],
        'groups' => ['სტატისტიკის ჯგუფები', \App\Filament\Resources\TreatmentCases\Pages\ManageGroups::class],
        'uncategorized' => ['დაუჯგუფებელი', \App\Filament\Resources\TreatmentCases\Pages\UncategorizedProcedures::class],
    ];
@endphp
<nav class="inline-flex flex-wrap items-center gap-1 rounded-xl border border-gray-200 bg-white p-1 shadow-sm dark:border-white/10 dark:bg-gray-900" aria-label="კატალოგის სექციები">
    @foreach ($sections as $section => [$label, $page])
        @php($active = get_class($this) === $page)
        <a href="{{ \App\Filament\Resources\TreatmentCases\TreatmentCaseResource::getUrl($section) }}"
           @if($active) aria-current="page" style="color: #fff" @endif
           class="fi-btn fi-btn-size-sm rounded-lg border px-4 py-2 text-sm font-semibold transition-colors {{ $active ? 'border-primary-600 bg-primary-600 text-white shadow-sm hover:bg-primary-500' : 'border-transparent bg-transparent text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-white/5 dark:hover:text-white' }}">
            {{ $label }}
        </a>
    @endforeach
</nav>
