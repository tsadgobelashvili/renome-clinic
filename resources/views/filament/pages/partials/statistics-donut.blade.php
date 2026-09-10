<section data-statistics-donut="{{ $chartKey }}" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>
    <x-analytics-donut :rows="$rows" :currency="$currency" :title="$title" :chart-key="$chartKey" />
</section>
