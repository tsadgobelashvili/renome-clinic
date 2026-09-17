{{-- Use the native sidebar store; group disclosure state remains owned by Filament. --}}
<span hidden x-data="{
    init() {
        this.rail = this.$el.closest('.fi-main-sidebar');
        this.desktop = window.matchMedia('(min-width: 1024px)');
        this.scrollTop = 0;
        this.enter = () => {
            if (!this.desktop.matches || this.$store.sidebar.isOpen) return;
            this.$store.sidebar.open();
            this.$nextTick(() => { this.rail.querySelector('.fi-sidebar-nav').scrollTop = this.scrollTop; });
        };
        this.leave = () => {
            if (this.desktop.matches && !this.rail.querySelector(':focus-visible')) {
                this.scrollTop = this.rail.querySelector('.fi-sidebar-nav').scrollTop;
                this.$store.sidebar.close();
            }
        };
        this.focusOut = (event) => {
            if (!this.rail.contains(event.relatedTarget) && !this.rail.matches(':hover')) this.leave();
        };
        this.reset = () => { if (this.desktop.matches) this.$store.sidebar.close(); };
        this.rail.addEventListener('mouseenter', this.enter);
        this.rail.addEventListener('mouseleave', this.leave);
        this.rail.addEventListener('focusin', this.enter);
        this.rail.addEventListener('focusout', this.focusOut);
        this.desktop.addEventListener('change', this.reset);
        this.reset();
    },
    destroy() {
        this.rail.removeEventListener('mouseenter', this.enter);
        this.rail.removeEventListener('mouseleave', this.leave);
        this.rail.removeEventListener('focusin', this.enter);
        this.rail.removeEventListener('focusout', this.focusOut);
        this.desktop.removeEventListener('change', this.reset);
    }
}"></span>
