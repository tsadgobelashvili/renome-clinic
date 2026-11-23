const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const { spawn } = require('node:child_process');

// FinancialAmountInputUiTest exports the real rendered Filament inputs first.
const fixture = process.env.FINANCIAL_INPUT_UI_FIXTURE || 'storage/framework/testing/financial-amount-inputs.json';
const browser = process.env.FINANCIAL_INPUT_TEST_BROWSER || [
    'C:/Program Files/Google/Chrome/Application/chrome.exe',
    'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
].find(file => fs.existsSync(file));

async function waitFor(predicate) {
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
        const value = await predicate();
        if (value) return value;
        await new Promise(resolve => setTimeout(resolve, 25));
    }
    throw new Error('Browser fixture timed out');
}

test('financial amounts resist trusted keyboard and wheel stepping while preserving decimal entry', { skip: !browser, timeout: 90000 }, async t => {
    assert.ok(fs.existsSync(fixture), 'Run FinancialAmountInputUiTest first to export the rendered inputs');
    const inputs = JSON.parse(fs.readFileSync(fixture, 'utf8'));
    assert.deepEqual(Object.keys(inputs).sort(), ['doctor-salary', 'patient-create', 'patient-edit']);
    const cache = path.resolve('storage/framework/testing');
    const directory = fs.mkdtempSync(path.join(cache, 'financial-input-browser-'));
    const profile = path.join(directory, 'profile');
    const html = path.join(directory, 'fixture.html');
    fs.writeFileSync(html, `<!doctype html><html><body style="height:4000px">
        <div data-fixture="native"><input type="number" step="0.01" min="0.01"></div>
        ${Object.entries(inputs).map(([name, input]) => `<div data-fixture="${name}">${input.html}</div>`).join('')}
        <style>div{padding:30px}input{width:240px;height:36px}</style></body></html>`);
    const child = spawn(browser, ['--headless=new', '--disable-gpu', '--disable-background-networking', '--disable-background-mode',
        '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'],
    { windowsHide: true, stdio: 'ignore' });
    let socket;
    let send;
    try {
        const portFile = path.join(profile, 'DevToolsActivePort');
        await waitFor(() => fs.existsSync(portFile));
        const port = fs.readFileSync(portFile, 'utf8').split('\n')[0];
        const targets = await fetch(`http://127.0.0.1:${port}/json/list`).then(response => response.json());
        socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
        let sequence = 0;
        const pending = new Map();
        socket.onmessage = event => {
            const message = JSON.parse(event.data);
            const call = pending.get(message.id);
            if (!call) return;
            pending.delete(message.id);
            message.error ? call.reject(new Error(message.error.message)) : call.resolve(message.result);
        };
        send = (method, params = {}) => new Promise((resolve, reject) => {
            const id = ++sequence;
            pending.set(id, { resolve, reject });
            socket.send(JSON.stringify({ id, method, params }));
        });
        const evaluate = async expression => {
            const response = await send('Runtime.evaluate', { expression, returnByValue: true });
            assert.ok(!response.exceptionDetails, JSON.stringify(response.exceptionDetails));
            return response.result.value;
        };
        await send('Page.navigate', { url: pathToFileURL(html).href });
        await waitFor(() => evaluate('document.querySelectorAll("input").length === 4'));
        const key = async (name, code, modifiers = 0) => {
            for (const type of ['keyDown', 'keyUp']) {
                await send('Input.dispatchKeyEvent', { type, key: name, code: name, windowsVirtualKeyCode: code, modifiers });
            }
        };
        const element = name => `document.querySelector('[data-fixture="${name}"] input')`;
        const value = name => evaluate(`${element(name)}.value`);
        const focus = name => evaluate(`${element(name)}.scrollIntoView({block:'center'});${element(name)}.focus()`);
        const enter = async (name, amount) => {
            await focus(name);
            await key('a', 65, 2); // Ctrl+A, followed by trusted text insertion.
            await send('Input.insertText', { text: amount });
        };
        const wheel = async (name, deltaY) => {
            await focus(name);
            const position = await evaluate(`(()=>{const r=${element(name)}.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', ...position });
            await send('Input.dispatchMouseEvent', { type: 'mouseWheel', ...position, deltaX: 0, deltaY });
            await new Promise(resolve => setTimeout(resolve, 100));
        };

        await t.test('unprotected native number input reproduces a one-cent increment', async () => {
            await enter('native', '2520.00');
            await key('ArrowUp', 38);
            assert.equal(await value('native'), '2520.01');
            await key('ArrowDown', 40);
            assert.equal(Number(await value('native')), 2520);
            await enter('native', '2520.00');
            await wheel('native', -100);
            t.diagnostic(`Native wheel result: ${await value('native')}`);
        });

        for (const name of Object.keys(inputs)) {
            await t.test(`${name}: manual cents survive arrow keys, wheel and ordinary editing`, async () => {
                assert.equal(inputs[name].type, 'text');
                assert.equal(inputs[name].inputmode, 'decimal');
                for (const amount of ['2520.00', '2520.01', '1998.00', '2000.00', '2002.00', '0.01']) {
                    await enter(name, amount);
                    assert.equal(await value(name), amount, 'Manual decimal entry changed');
                    await key('ArrowUp', 38);
                    await key('ArrowDown', 40);
                    await wheel(name, -100);
                    await wheel(name, 100);
                    assert.equal(await value(name), amount, 'Arrow/wheel altered an amount');
                }
                await enter(name, '2520.00');
                await key('Backspace', 8);
                await send('Input.insertText', { text: '1' });
                assert.equal(await value(name), '2520.01', 'Manual cent correction is blocked');
                await key('Tab', 9);
                assert.equal(await evaluate(`document.activeElement === ${element(name)}`), false, 'Tab navigation is blocked');
                assert.equal(await value(name), '2520.01');
            });
        }
    } finally {
        if (socket?.readyState === WebSocket.OPEN) {
            await send('Browser.close').catch(() => {});
            socket.close();
        }
        if (child.exitCode === null) {
            await Promise.race([new Promise(resolve => child.once('exit', resolve)), new Promise(resolve => setTimeout(resolve, 2000))]);
        }
        if (child.exitCode === null) child.kill();
        // Remove only this newly created fixture/profile beneath the verified test cache.
        assert.equal(path.dirname(fs.realpathSync(directory)), fs.realpathSync(cache));
        fs.rmSync(directory, { recursive: true, force: true, maxRetries: 15, retryDelay: 100 });
    }
});
