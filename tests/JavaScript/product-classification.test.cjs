const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function state() {
    const data = {
        pendingClassifications: { 1: { expense_direction_id: 10, purchase_product_group_id: 100 }, 2: { expense_direction_id: null, purchase_product_group_id: null } },
        classificationOriginals: { 1: { expense_direction_id: 10, purchase_product_group_id: 100 }, 2: { expense_direction_id: null, purchase_product_group_id: null } },
        classificationGroups: { 100: { name: 'Therapy group', direction: 10, selectable: true }, 200: { name: 'Surgery group', direction: 20, selectable: true } },
        classificationDirections: {},
    };
    const context = { window: {} };
    vm.runInNewContext(fs.readFileSync('public/js/purchase-classification.js', 'utf8'), context);
    const calls = [];
    const result = context.window.purchaseClassificationState({ $entangle(name, live = false) { calls.push([name, live]); return data[name]; } });
    assert.ok(calls.every(([, live]) => live === false));
    return result;
}

test('local Direction changes filter groups and clear incompatible selection without changing originals', () => {
    const s = state();
    s.edits[1].expense_direction_id = '20';
    s.directionChanged(1);
    assert.equal(s.edits[1].purchase_product_group_id, null);
    assert.equal(s.originals[1].purchase_product_group_id, 100);
    assert.deepEqual(Array.from(s.groupOptions(1), group => group.id), ['200']);
});

test('dirty rows count once each, reverted changes clear, and saved state clears indicators', () => {
    const s = state();
    assert.equal(s.dirtyCount(), 0);
    s.edits[1].purchase_product_group_id = null;
    s.edits[2].expense_direction_id = 20;
    assert.equal(s.dirtyCount(), 2);
    s.edits[1].purchase_product_group_id = '100';
    assert.equal(s.dirtyCount(), 1);
    s.originals[2] = { ...s.edits[2] };
    assert.equal(s.dirtyCount(), 0);
});

test('newly created group is selectable and missing Direction offers no groups', () => {
    const s = state();
    assert.equal(s.groupOptions(2).length, 0);
    s.groups[300] = { name: 'New group', direction: 10, selectable: true };
    s.edits[1].purchase_product_group_id = 300;
    assert.ok(s.groupOptions(1).some(group => group.id === '300'));
    assert.equal(s.dirtyCount(), 1);
});

test('inline-created option selects after rendering while other pending edits remain dirty', () => {
    const s = state();
    const ticks = [];
    s.$nextTick = callback => ticks.push(callback);
    s.edits[1].purchase_product_group_id = null;
    s.edits[2].expense_direction_id = 20;
    s.originals[3] = { expense_direction_id: null, purchase_product_group_id: null };
    s.edits[3] = { expense_direction_id: 10, purchase_product_group_id: 300 };
    const untouchedEdits = JSON.stringify([s.edits[1], s.edits[2]]);
    const originals = JSON.stringify(s.originals);
    // Native selects cannot display a value before its option exists.
    const select = {
        options: new Set(['', '100']), selected: '',
        set value(value) { this.selected = this.options.has(value) ? value : ''; },
        get value() { return this.selected; },
    };
    select.value = '300';
    assert.equal(select.value, '');
    s.groups[300] = { name: 'Zircon', direction: 10, selectable: true };
    s.syncGroupSelection(3, select);
    assert.equal(select.value, '');
    // Alpine renders x-for options before flushing nextTick callbacks.
    select.options = new Set(['', ...s.groupOptions(3).map(group => group.id)]);
    ticks.splice(0).forEach(callback => callback());
    assert.equal(select.value, '300');
    assert.equal(s.edits[3].purchase_product_group_id, 300);
    assert.equal(s.dirtyCount(), 3);
    assert.equal(JSON.stringify([s.edits[1], s.edits[2]]), untouchedEdits);
    assert.equal(JSON.stringify(s.originals), originals);
    assert.ok(!s.groupOptions(2).some(group => group.id === '300'));
});

test('the native selector resynchronizes after Direction clears an incompatible new group', () => {
    const s = state(), ticks = [];
    s.$nextTick = callback => ticks.push(callback);
    s.groups[300] = { name: 'Zircon', direction: 10, selectable: true };
    s.edits[1].purchase_product_group_id = 300;
    s.edits[1].expense_direction_id = 20;
    s.directionChanged(1);
    const select = { value: '300' };
    s.syncGroupSelection(1, select);
    ticks.forEach(callback => callback());
    assert.equal(select.value, '');
    assert.equal(s.edits[1].purchase_product_group_id, null);
    assert.match(fs.readFileSync('resources/views/filament/resources/purchases/classification-group.blade.php', 'utf8'), /x-effect="syncGroupSelection\(/);
});

for (const origin of ['1', '2']) {
    test(`completion from row ${origin} sets only that pending path without another row interaction`, () => {
        const s = state(), ticks = [], touched = [];
        s.$nextTick = callback => ticks.push(callback);
        for (const id of ['1', '2']) {
            s.edits[id] = { expense_direction_id: 20, purchase_product_group_id: null };
            s.originals[id] = { ...s.edits[id] };
        }
        s.edits[3] = { expense_direction_id: 10, purchase_product_group_id: null };
        s.originals[3] = { expense_direction_id: 10, purchase_product_group_id: 100 };
        const other = origin === '1' ? '2' : '1';
        const before = JSON.stringify([s.edits[other], s.edits[3]]);
        const lockedGroups = JSON.stringify(s.groups);
        const select = { value: '', _x_model: { set(value) { touched.push(origin); s.edits[origin].purchase_product_group_id = value; } } };
        const root = { querySelector(selector) {
            assert.equal(selector, `[data-classification-product-group-row="${origin}"]`);
            return select;
        } };
        s.syncGroupSelection(origin, select); // An earlier queued effect must not restore the empty value.
        s.groupCreated({ rowId: origin, direction: 20, statePath: `pendingClassifications.${origin}.purchase_product_group_id`,
            group: { id: 300, name: 'Implants', direction: 20, selectable: true } }, root);
        ticks.splice(0).forEach(callback => callback());
        assert.equal(select.value, '300');
        assert.equal(s.edits[origin].purchase_product_group_id, 300);
        assert.equal(s.edits[other].purchase_product_group_id, null);
        assert.equal(JSON.stringify([s.edits[other], s.edits[3]]), before);
        assert.equal(JSON.stringify(s.groups), lockedGroups);
        assert.ok(s.groupOptions(other).some(group => group.id === '300'));
        assert.ok(!s.groupOptions(3).some(group => group.id === '300'));
        assert.ok(s.isDirty(origin));
        assert.ok(!s.isDirty(other));
        assert.deepEqual(touched, [origin]);
    });
}

test('a completion with an incompatible Direction or wrong row path cannot replace pending edits', () => {
    const s = state();
    const before = JSON.stringify(s.edits);
    const root = { querySelector() { assert.fail('Must not target a field'); } };
    const payload = { rowId: 1, direction: 20, statePath: 'pendingClassifications.1.purchase_product_group_id',
        group: { id: 300, name: 'Implants', direction: 20, selectable: true } };
    s.groupCreated(payload, root);
    s.groupCreated({ ...payload, direction: 10, statePath: 'pendingClassifications.2.purchase_product_group_id' }, root);
    assert.equal(JSON.stringify(s.edits), before);
});
