// Run: node tests/clockout-flow.test.cjs. Ajax/UI doubles exercise the real handlers.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const elements = new Map();
const posts = [];
function $(selector) {
    if (selector && selector.__element) return selector;
    if (!elements.has(selector)) {
        const state = { data: {}, value: '', text: '', events: {}, props: {} };
        const element = new Proxy({ __element: true, state }, {
            get(target, key) {
                if (key in target) return target[key];
                if (key === 'ready') return fn => fn($);
                if (key === 'on') return (event, ...args) => { state.events[event] = args.at(-1); return element; };
                if (key === 'val' || key === 'text' || key === 'html') return function(value) {
                    const field = key === 'val' ? 'value' : 'text';
                    if (!arguments.length) return state[field];
                    state[field] = value; return element;
                };
                if (key === 'data' || key === 'prop') return function(name, value) {
                    const bag = key === 'data' ? state.data : state.props;
                    if (arguments.length === 1) return bag[name];
                    bag[name] = value; return element;
                };
                if (key === 'css') return (name, value) => { state[name] = value; return element; };
                if (key === 'toggle') return visible => { state.visible = visible; return element; };
                if (key === 'is') return () => false;
                return () => element;
            }
        });
        elements.set(selector, element);
    }
    return elements.get(selector);
}
$.post = (url, data) => { posts.push(data); return { fail() {} }; };
$.trim = value => String(value).trim();
$.each = () => {};
const context = {
    jQuery: $, document: {}, window: {}, setInterval() {}, setTimeout() {},
    matAjax: { ajaxurl: '/ajax', nonce: 'test', breakSteps: [{ id: 1, minutes: 0 }, { id: 2, minutes: 45 }, { id: 3, minutes: 60 }] },
    alert(message) { throw new Error(message); }, confirm() { return true; }
};
let source = fs.readFileSync(__dirname + '/../js/main.js', 'utf8');
const end = source.lastIndexOf('});');
source = source.slice(0, end) + '\n globalThis.testFlow = { startClockoutFlow, handlePrepareResult, applyPunchButtons, submitClockout };\n' + source.slice(end);
vm.runInNewContext(source, context);
const flow = context.testFlow;
flow.startClockoutFlow($('#checkout'));
const short = { target_date: '2026-09-24', clock_out: '14:00', needs_short_break_fix: true, fixed_break_minutes: 60, short_break_standard: 45, kousoku_minutes: 360, job_break_active: true };
flow.handlePrepareResult(short);
assert.equal($('#mat-short-break-modal').state.display, 'flex');
assert.equal($('#mat-short-break-minutes').val(), 45);
const before = posts.length;
$('#mat-short-break-minutes').val('60');
$('#mat-short-break-ok').state.events.click();
assert.equal(posts.length, before, 'unchanged fixed break does not proceed');
$('#mat-short-break-minutes').val('45');
$('#mat-short-break-ok').state.events.click();
assert.equal(posts.at(-1).job_break_minutes, 45, 'corrected break sent to prepare');
flow.handlePrepareResult({ ...short, needs_short_break_fix: false });
assert.equal(posts.at(-1).action, 'mat_attendance_update');
assert.equal(posts.at(-1).job_break_minutes, 45, 'correction also sent to final save');
flow.startClockoutFlow($('#checkout'));
assert.equal(posts.at(-1).job_break_minutes, '', 'new attempt clears old correction');
flow.applyPunchButtons({ job_break_active: true, job_break_fallback: true });
assert.equal($('.mat-break-box').state.visible, false);
assert.equal($('#mat-job-break-warning').state.visible, true);
flow.applyPunchButtons({ job_break_active: false, job_break_fallback: false });
assert.equal($('.mat-break-box').state.visible, true);
assert.equal($('#mat-job-break-warning').state.visible, false);
console.log('PASS: clockout correction, final payload, reset, and fallback UI');
