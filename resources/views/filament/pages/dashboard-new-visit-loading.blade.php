{{-- Paint the modal shell locally while Filament mounts the searchable form. --}}
<div
    class="contents"
    x-data="{
        pending: false,
        async openVisit() {
            if (this.pending) return
            this.pending = true
            this.$dispatch('open-modal', { id: 'dashboard-new-visit-loading-' + this.$wire.$id })
            try {
                await this.$wire.mountAction('newVisit')
            } finally {
                this.$dispatch('close-modal', { id: 'dashboard-new-visit-loading-' + this.$wire.$id })
                this.pending = false
            }
        },
    }"
    x-on:dashboard-new-visit.window="if ($event.detail.id === $wire.$id) openVisit()"
>
    <x-filament::modal
        :id="'dashboard-new-visit-loading-'.$this->getId()"
        heading="ახალი ვიზიტი"
        width="3xl"
        :autofocus="false"
        :restores-focus="false"
        :close-button="false"
        :close-by-clicking-away="false"
        :close-by-escaping="false"
    >
        <div aria-busy="true" aria-label="{{ __('filament-forms::components.select.loading_message') }}" class="space-y-5">
            <span class="sr-only" role="status">{{ __('filament-forms::components.select.loading_message') }}</span>
            <div aria-hidden="true" class="space-y-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                    <div class="h-10 rounded-lg bg-gray-100 dark:bg-white/10 md:col-span-6"></div>
                    <div class="h-10 rounded-lg bg-gray-100 dark:bg-white/10 md:col-span-4"></div>
                    <div class="h-10 rounded-lg bg-gray-100 dark:bg-white/10 md:col-span-2"></div>
                </div>
                <div class="h-9 w-56 rounded-lg bg-gray-100 dark:bg-white/10"></div>
                <div class="h-24 rounded-lg bg-gray-100 dark:bg-white/10"></div>
                <div class="h-32 rounded-lg bg-gray-100 dark:bg-white/10"></div>
            </div>
        </div>
    </x-filament::modal>
</div>
