const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawnSync } = require('node:child_process');

const browser = process.env.CATALOG_TEST_BROWSER || [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(file => fs.existsSync(file));

test('real Alpine/native selects handle completion on A then B without interacting with any row', { skip: !browser }, () => {
    const cache = path.resolve('storage/framework/testing');
    fs.mkdirSync(cache, { recursive: true });
    const directory = fs.mkdtempSync(path.join(cache, 'catalog-origin-browser-'));
    const htmlPath = path.join(directory, 'fixture.html');
    const source = file => pathToFileURL(path.resolve(file)).href;
    const view = fs.readFileSync('resources/views/filament/resources/purchases/classification-table.blade.php', 'utf8');
    const handler = view.match(/x-on:purchase-classification-group-created\.window="([^"]+)"/)[1];
    const rows = [1, 2, 3].map(id => `<select data-classification-product-group-row="${id}"
        x-model="edits[${id}].purchase_product_group_id" x-effect="syncGroupSelection('${id}', $el)">
        <option value="">Unclassified</option>
        <template x-for="group in groupOptions('${id}')" :key="group.id">
            <option :value="group.id" x-text="group.name" :selected="key(edits[${id}].purchase_product_group_id) === group.id"></option>
        </template></select>`).join('');
    fs.writeFileSync(htmlPath, `<!doctype html><html><head><meta charset="utf-8">
        <script>window.livewireScriptConfig = {};</script>
        <script src="${source('vendor/livewire/livewire/dist/livewire.js')}"></script>
        <script src="${source('public/js/purchase-classification.js')}"></script></head><body>
        <script>
        const data = {
            pendingClassifications: {1:{expense_direction_id:20,purchase_product_group_id:null},2:{expense_direction_id:20,purchase_product_group_id:null},3:{expense_direction_id:10,purchase_product_group_id:null}},
            classificationOriginals: {1:{expense_direction_id:10,purchase_product_group_id:100},2:{expense_direction_id:20,purchase_product_group_id:null},3:{expense_direction_id:10,purchase_product_group_id:100}},
            classificationGroups: {100:{name:'Therapy',direction:10,selectable:true}}, classificationDirections: {}
        };
        window.fixtureWire = {$entangle(name){return data[name];}};
        </script>
        <div id="catalog" x-data="purchaseClassificationState(fixtureWire)" x-on:purchase-classification-group-created.window="${handler}">${rows}</div>
        <pre id="result" data-result="pending"></pre>
        <script>
        const result = document.getElementById('result');
        window.addEventListener('error', event => {result.dataset.result='failed';result.textContent=String(event.error || event.message);});
        Alpine.start();
        (async () => {
            const check = (condition, message) => {if(!condition) throw new Error(message);};
            const tick = () => new Promise(resolve => Alpine.nextTick(resolve));
            const select = id => document.querySelector('[data-classification-product-group-row="'+id+'"]');
            await tick();
            const state = Alpine.$data(document.getElementById('catalog'));
            const original = JSON.stringify(state.originals);
            const thirdRow = JSON.stringify(state.edits[3]);
            const created = (rowId, id, name) => window.dispatchEvent(new CustomEvent('purchase-classification-group-created', {detail:{
                rowId, direction:20, statePath:'pendingClassifications.'+rowId+'.purchase_product_group_id',
                group:{id,name,direction:20,selectable:true}
            }}));
            created(1,301,'Implants A');
            await tick();
            check(select(1).value==='301' && select(1).selectedOptions[0].textContent==='Implants A','Row A did not immediately display its new group');
            check(select(2).value==='' && state.edits[2].purchase_product_group_id===null,'Row B was incorrectly selected');
            check([...select(2).options].some(option=>option.value==='301'),'Row B cannot see the new compatible option');
            check(![...select(3).options].some(option=>option.value==='301'),'New group leaked into Therapy');
            check(state.isDirty(1) && !state.isDirty(2),'Dirty state is not isolated to the originating row');
            created(2,302,'Implants B');
            await tick();
            check(select(2).value==='302' && select(2).selectedOptions[0].textContent==='Implants B','Row B creation did not target B');
            check(select(1).value==='301','Row B creation overwrote A');
            check(JSON.stringify(state.edits[3])===thirdRow && JSON.stringify(state.originals)===original,'Pending edits or originals were overwritten');
            check(state.dirtyCount()===3,'Pending batch edits were lost');
            result.dataset.result='passed';result.textContent='Both originating native selects updated without row interactions; pending edits and filtering preserved.';
        })().catch(error=>{result.dataset.result='failed';result.textContent=error.stack;});
        </script></body></html>`);
    try {
        const run = spawnSync(browser, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--disable-background-mode',
            '--no-first-run', '--no-default-browser-check', `--user-data-dir=${path.join(directory, 'profile')}`,
            '--virtual-time-budget=5000', '--dump-dom', pathToFileURL(htmlPath).href],
        { encoding: 'utf8', windowsHide: true, timeout: 30000, maxBuffer: 4 * 1024 * 1024 });
        assert.ifError(run.error);
        assert.equal(run.status, 0, run.stderr);
        const result = run.stdout.match(/<pre id="result"[^>]*>[\s\S]*?<\/pre>/)?.[0] || run.stdout.slice(-2000);
        assert.match(result, /data-result="passed"/, result);
    } finally {
        // Only this generated fixture/profile beneath the known test cache is removable.
        if (path.dirname(fs.realpathSync(directory)) !== fs.realpathSync(cache)) throw new Error('Unexpected test directory');
        fs.rmSync(directory, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 });
    }
});
