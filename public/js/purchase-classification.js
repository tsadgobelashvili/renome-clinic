// Small Alpine state adapter. Dropdown edits never call the server; entanglement is deferred.
window.purchaseClassificationState = function (wire) {
    return {
        edits: wire.$entangle('pendingClassifications'),
        originals: wire.$entangle('classificationOriginals'),
        groups: wire.$entangle('classificationGroups'),
        directions: wire.$entangle('classificationDirections'),
        inlineGroups: {},
        key(value) { return value === null || value === undefined || value === '' ? '' : String(value); },
        isDirty(id) {
            const original = this.originals[id], row = this.edits[id];
            return !!original && !!row && ['expense_direction_id', 'purchase_product_group_id']
                .some(field => this.key(row[field]) !== this.key(original[field]));
        },
        dirtyCount() { return Object.keys(this.edits).filter(id => this.isDirty(id)).length; },
        groupOptions(id) {
            const direction = this.key(this.edits[id]?.expense_direction_id);
            if (!direction) return [];
            return Object.entries({ ...this.groups, ...this.inlineGroups }).filter(([, group]) => group.selectable && this.key(group.direction) === direction)
                .map(([id, group]) => ({ id, name: group.name }));
        },
        syncGroupSelection(id, select) {
            // Track options as well as selection: Livewire can deliver the new ID
            // before Alpine's x-for has created its native <option> element.
            this.groupOptions(id);
            this.key(this.edits[id]?.purchase_product_group_id);
            this.$nextTick(() => { select.value = this.key(this.edits[id]?.purchase_product_group_id); });
        },
        groupCreated({ rowId, direction, statePath, group }, root) {
            const id = this.key(rowId), row = this.edits[id];
            if (!/^\d+$/.test(id) || !row || statePath !== `pendingClassifications.${id}.purchase_product_group_id`
                || this.key(row.expense_direction_id) !== this.key(direction)
                || this.key(group.direction) !== this.key(direction)) return;
            // Local overlay avoids writing back the locked server option registry.
            // The completion event explicitly targets the row that opened the modal,
            // even when its deferred entanglement/native select still has old state.
            this.inlineGroups = { ...this.inlineGroups, [group.id]: group };
            row.purchase_product_group_id = group.id;
            this.$nextTick(() => {
                const select = root.querySelector(`[data-classification-product-group-row="${id}"]`);
                if (!select || this.key(this.edits[id]?.expense_direction_id) !== this.key(direction)) return;
                select._x_model?.set(group.id);
                select.value = this.key(this.edits[id]?.purchase_product_group_id);
            });
        },
        directionChanged(id) {
            const row = this.edits[id], group = this.inlineGroups[this.key(row.purchase_product_group_id)] || this.groups[this.key(row.purchase_product_group_id)];
            if (!this.key(row.expense_direction_id) || !group?.selectable || this.key(group.direction) !== this.key(row.expense_direction_id)) {
                row.purchase_product_group_id = null;
            }
        },
        legacyGroup(id) {
            const row = this.edits[id], original = this.originals[id];
            const selected = this.key(row?.purchase_product_group_id);
            return selected && selected === this.key(original?.purchase_product_group_id)
                && this.key(row.expense_direction_id) === this.key(original.expense_direction_id)
                && !this.groupOptions(id).some(group => group.id === selected)
                ? (this.groups[selected]?.name || '#' + selected) : '';
        },
    };
};
