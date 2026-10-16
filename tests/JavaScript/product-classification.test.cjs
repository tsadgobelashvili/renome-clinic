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
