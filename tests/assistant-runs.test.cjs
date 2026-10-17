const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const runs = require('../assistant-runs.js');
const demo = require('../assistant-runs-demo.js');
const planning = require('../planning.js');
const html = fs.readFileSync(path.join(__dirname, '../Gestor de Tareas.dc.html'), 'utf8');
const source = html.match(/<script type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/)[1];
const markup = html.slice(html.indexOf('<sc-if value="{{ isAssistant }}"'), html.indexOf('<sc-if value="{{ isTeamView }}">'));
const supervisionMarkup = html.slice(html.indexOf('<sc-if value="{{ isSupervisionView }}"'), html.indexOf('<sc-if value="{{ isAdminView }}"'));
const css = html.slice(html.indexOf('.assistant-view {'), html.indexOf('.team-view {'));

const NOW = Date.parse('2026-10-16T12:00:00Z');
const iso = minutesAgo => new Date(NOW - minutesAgo * 60000).toISOString();
const ev = (id, type, at, summary = '') => ({ id, type, at, summary, actor: 'Luna (prueba)' });
const run = (extra = {}, events = [ev('e0', 'delegated', iso(120))]) => ({ kind: 'assistant_run', id: 'r1', assistant: 'Luna (prueba)', title: 'Ejecución sintética', status: 'in_progress', delegated_at: iso(120), events, ...extra });

function component(calls) {
  class Logic { props = {focusMinutes:25,breakMinutes:5,showQuadrantColors:true}; setState(next) { Object.assign(this.state, typeof next === 'function' ? next(this.state) : next); } }
  const fetch = () => { calls.fetch++; throw new Error('network disabled in tests'); };
  const context = vm.createContext({ DCLogic:Logic, KaizenPlanning:planning, KaizenAssistantRuns:runs, KaizenAssistantDemo:demo, console, setTimeout, clearTimeout, Intl, Date, Promise, fetch, localStorage:{setItem(){}} });
  const c = new (vm.runInContext(source + '; Component', context))();
  c.api = async (action,payload) => {
    calls.api++;
    if(action==='assistant_runs')return {ok:true,runs:calls.assistantRuns||[]};
    if(action==='supervision_people')return {ok:true,people:calls.supervisionPeople||[]};
    if(action==='supervision_tasks')return {ok:true,tasks:(calls.supervisionTasks||{})[Number(payload.person_id)]||[]};
    return { ok:true };
  };
  return c;
}

function workspaceRole(c, memberRole, id = 1) {
  c.state.auth = { id, role:'user' };
  c.state.workspaceId = 7;
  c.state.workspaces = [{ id:7, member_role:memberRole }];
  return c;
}

test('timestamps must be explicit valid ISO instants', () => {
  assert.equal(runs.parseTime('2026-10-16T12:00:00Z'), NOW);
  assert.equal(runs.parseTime('2026-10-16T06:00:00-06:00'), NOW);
  assert.equal(runs.parseTime('2026-10-16T12:00Z'), NOW);
  for (const bad of [NOW, '2026-10-16T12:00:00', '2026-10-16', '2026-02-30T10:00:00Z', '2026-10-16T25:00:00Z', '', null, undefined, 'ayer'])
    assert.equal(runs.parseTime(bad), null, String(bad));
});

test('only explicit assistant delegations become runs; Kaizen task fields never create one', () => {
  const kaizenTask = { id:'t1', title:'Tarea Kaizen', done:true, doneAt:NOW, _owner_id:1, sharedWith:[2], _shared_user_ids:[2], _delegated_at:iso(60), scope:'team', pomos:4, seconds:6000, _revision:'abc', status:'completed', events:[ev('x','delegated',iso(10))] };
  assert.equal(runs.isAssistantRun(kaizenTask), false);
  assert.equal(runs.isAssistantRun(run({}, [ev('e1', 'progress_note', iso(5), 'x')])), false, 'needs a delegated event');
  assert.equal(runs.isAssistantRun(run({ assistant:'' })), false);
  assert.equal(runs.isAssistantRun(run()), true);
  assert.deepEqual(runs.summarizeAll([kaizenTask, run(), null, 'x'], NOW).map(r => r.id), ['r1']);
});

test('open runs age from delegated_at; completed runs show resolution from verified timestamps only', () => {
  const open = runs.summarize(run({ seconds:99999, pomos:9, doneAt:NOW, _revision:'r' }), NOW);
  assert.equal(open.elapsed.kind, 'open'); assert.equal(open.elapsed.ms, 120 * 60000);
  assert.equal(open.active.intervals, 0, 'seconds/pomos never count as active time');
  const doneEvents = [ev('e0', 'delegated', iso(300)), ev('e1', 'completed', iso(60))];
  const done = runs.summarize(run({ status:'completed', delegated_at:iso(300), completed_at:iso(60), seconds:5, doneAt:NOW }, doneEvents), NOW);
  assert.equal(done.elapsed.kind, 'resolution'); assert.equal(done.elapsed.ms, 240 * 60000); assert.equal(done.resolved, true);
  assert.equal(runs.duration(done.elapsed.ms), '4 h');
});

test('cancelled runs stay cancelled and are never counted as resolved', () => {
  const events = [ev('e0', 'delegated', iso(300)), ev('e1', 'cancelled', iso(100))];
  const cancelled = runs.summarize(run({ status:'cancelled', closed_at:iso(100), completed_at:iso(100) }, events), NOW);
  assert.equal(cancelled.status, 'cancelled'); assert.equal(cancelled.resolved, false); assert.equal(cancelled.elapsed.kind, 'cancelled');
  const { counts } = runs.filter([cancelled], {});
  assert.equal(counts.cancelled, 1); assert.equal(counts.completed, 0);
});

test('missing, invalid or contradictory timestamps produce Sin dato instead of invented durations', () => {
  const cases = [
    run({ delegated_at:'' }),
    run({ delegated_at:'2026-13-01T00:00:00Z' }),
    run({ delegated_at:new Date(NOW + 60000).toISOString() }),
    run({ status:'completed', completed_at:iso(10) }), // no completed event
    run({ status:'completed', completed_at:'' }, [ev('e0', 'delegated', iso(120)), ev('e1', 'completed', iso(10))]),
    run({ status:'completed', completed_at:iso(200) }, [ev('e0', 'delegated', iso(120)), ev('e1', 'completed', iso(10))]),
    run({ status:'done' })
  ];
  for (const record of cases) {
    const summary = runs.summarize(record, NOW);
    assert.equal(summary.elapsed.ms, null, JSON.stringify(record));
    assert.equal(runs.duration(summary.elapsed.ms), 'Sin dato');
    assert.equal(summary.resolved, false);
  }
  assert.equal(runs.summarize(run({ status:'done' }), NOW).statusLabel, 'Estado sin dato');
  const noEvents = runs.summarize(run({}, [ev('e0', 'delegated', 'nope')]), NOW);
  assert.equal(noEvents.lastActivityAt, null); assert.equal(runs.absolute(noEvents.lastActivityAt), 'Sin dato');
});

test('latest progress, blocker, next step and deliverables come only from registered events', () => {
  const events = [
    ev('e0', 'delegated', iso(200)),
    ev('e1', 'progress_note', iso(50), 'Más reciente'),
    ev('e2', 'progress_note', iso(90), 'Anterior'),
    ev('e3', 'progress_note', 'sin hora', 'Sin marca'),
    ev('e4', 'blocker_set', iso(80), 'Falta un dato'),
    ev('e5', 'next_step_set', iso(70), 'Pedir el dato'),
    ev('e6', 'deliverable_added', iso(60), 'Borrador'),
    ev('e7', 'deliverable_added', iso(55), '')
  ];
  const summary = runs.summarize(run({ status:'blocked' }, events), NOW);
  assert.equal(summary.progress.summary, 'Más reciente'); assert.equal(summary.progress.at, Date.parse(iso(50)));
  assert.equal(summary.blocker.summary, 'Falta un dato'); assert.equal(summary.nextStep.summary, 'Pedir el dato');
  assert.deepEqual(summary.deliverables.map(d => d.label), ['Borrador']);
  assert.equal(summary.lastActivityAt, Date.parse(iso(50)));
  const cleared = runs.summarize(run({}, [...events, ev('e8', 'blocker_cleared', iso(40))]), NOW);
  assert.equal(cleared.blocker, null);
  const bare = runs.summarize(run(), NOW);
  assert.equal(bare.progress, null); assert.equal(bare.blocker, null); assert.equal(bare.nextStep, null); assert.deepEqual(bare.deliverables, []);
  assert.equal('percent' in bare || 'owner' in bare || 'deadline' in bare, false);
});

test('active time needs explicit valid start/pause pairs; open, reversed, duplicated and unmatched records add nothing', () => {
  assert.deepEqual(runs.activeTime([]), { ms:0, intervals:0, open:false, ignored:0 });
  const valid = runs.activeTime([ev('a', 'work_started', iso(100)), ev('b', 'work_paused', iso(70)), ev('c', 'work_started', iso(50)), ev('d', 'work_paused', iso(40))]);
  assert.equal(valid.ms, 40 * 60000); assert.equal(valid.intervals, 2); assert.equal(valid.ignored, 0);
  // iso(m) is m minutes BEFORE NOW: a larger m is an earlier instant.
  const messy = runs.activeTime([
    ev('p0', 'work_paused', iso(200)),                                     // 08:40 pause with no start: ignored
    ev('s1', 'work_started', iso(180)), ev('p1', 'work_paused', iso(190)), // 09:00 → 08:50 reversed: ignored
    ev('s2', 'work_started', iso(150)), ev('p2', 'work_paused', iso(140)), // 09:30 → 09:40 valid: 10 min
    ev('p2', 'work_paused', iso(130)),                                     // repeated id p2: ignored
    ev('s3', 'work_started', iso(120)), ev('s3b', 'work_started', iso(120)), // repeated start 10:00: ignored
    ev('p3', 'work_paused', iso(110)),                                     // 10:00 → 10:10 valid: 10 min
    ev('s4', 'work_started', 'bad'),                                       // invalid timestamp: ignored
    ev('s5', 'work_started', iso(20))                                      // 11:40 still open: not counted
  ]);
  assert.equal(messy.ms, 20 * 60000); assert.equal(messy.intervals, 2); assert.equal(messy.open, true);
  assert.ok(messy.ignored >= 5);
  const summary = runs.summarize(run({ seconds:3600, pomos:3 }, [ev('e0', 'delegated', iso(120)), ev('s', 'work_started', iso(30))]), NOW);
  assert.equal(summary.active.intervals, 0); assert.equal(summary.active.ms, 0); assert.equal(summary.active.open, true);
});

test('timeline is reverse chronological with unverifiable times last', () => {
  const summary = runs.summarize(run({}, [ev('e0', 'delegated', iso(300)), ev('e1', 'progress_note', 'x', 'sin hora'), ev('e2', 'progress_note', iso(10), 'b'), ev('e3', 'next_step_set', iso(100), 'c'), ev('e3', 'progress_note', iso(5), 'dup id')]), NOW);
  assert.deepEqual(summary.timeline.map(e => e.id), ['e2', 'e3', 'e0', 'e1']);
});

test('status filters and search update list and counters without mutating runs', () => {
  const records = demo.demoRuns(NOW);
  const before = JSON.stringify(records);
  const summaries = runs.summarizeAll(records, NOW);
  const all = runs.filter(summaries, {});
  assert.equal(all.counts.all, records.length); assert.equal(all.list.length, records.length);
  const blocked = runs.filter(summaries, { status:'blocked' });
  assert.ok(blocked.list.length > 0 && blocked.list.every(r => r.status === 'blocked'));
  assert.equal(blocked.counts.all, records.length, 'status filter leaves counters for every status');
  const searched = runs.filter(summaries, { query:'PLANTILLA' });
  assert.ok(searched.list.length > 0); assert.equal(searched.counts.all, searched.list.length);
  assert.equal(runs.filter(summaries, { query:'verificacion' }).list.length, 1, 'accent-insensitive');
  assert.equal(runs.filter(summaries, { query:'zzz-no-match' }).counts.all, 0);
  assert.equal(JSON.stringify(records), before);
});

test('demo fixture is synthetic, flagged and covers missing data and imperfect intervals', () => {
  assert.equal(demo.demo, true); assert.equal(demo.label, 'DEMO · datos ficticios');
  const records = demo.demoRuns(NOW);
  assert.ok(records.every(r => /\(demo\)/.test(r.title) && r.workspace_id === 'demo'));
  const text = JSON.stringify(records);
  assert.doesNotMatch(text, /@|https?:|token|password|secret|paciente|clínic/i);
  const summaries = runs.summarizeAll(records, NOW);
  assert.deepEqual([...new Set(summaries.map(s => s.status))].sort(), ['blocked', 'cancelled', 'completed', 'in_progress', 'queued']);
  assert.ok(summaries.some(s => s.status === 'completed' && s.elapsed.ms === null));
  assert.ok(summaries.some(s => s.active.open) && summaries.some(s => s.active.ignored > 0));
});

test('component loads private server records and keeps explicit demo fixtures labeled; filters never edit runs', async () => {
  const calls = { api:0, fetch:0, assistantRuns:demo.demoRuns(NOW) }, c = component(calls);
  Object.assign(c.state, c.seed()); workspaceRole(c, 'admin');
  let v = c.renderVals();
  const nav = v.secondaryNavItems.find(n => n.id === 'assistant');
  assert.ok(nav, 'assistant section in navigation'); assert.equal(v.primaryNavItems.length, 6);
  const tasksBefore = JSON.stringify(c.state.tasks);
  nav.onClick(); assert.equal(c.state.view, 'assistant');
  v = c.renderVals();
  assert.equal(v.assistantNotLoaded, true); assert.equal(v.assistantLoading, false);
  assert.equal(v.assistantDemoLabel, 'SIN CARGAR');
  assert.equal(calls.api, 0, 'opening the section does not automatically load live records');
  await c.loadAssistantRuns('live');
  v = c.renderVals();
  assert.equal(v.isAssistant, true); assert.equal(v.assistantReady, true); assert.equal(v.assistantDemoLabel, 'REGISTROS PRIVADOS');
  assert.ok(v.assistantCards.length > 0);
  assert.ok(calls.api >= 1, 'authorized live mode calls the admin API');
  await c.loadAssistantRuns('data'); v=c.renderVals();
  assert.equal(v.assistantDemoLabel,'DEMO · datos ficticios','synthetic records remain explicitly labeled');
  assert.match(v.assistantDataMessage,/ficticias/i);
  assert.equal(c.renderVals().navItems.find(n => n.id === 'assistant').count, v.assistantCards.filter(card => ['queued','in_progress','blocked'].includes(card.status)).length);
  const runsBefore = JSON.stringify(c.state.assistantRuns);
  v.assistantStatusFilters.find(f => f.key === 'completed').onClick();
  v = c.renderVals();
  assert.ok(v.assistantCards.every(card => card.status === 'completed'));
  assert.equal(v.assistantStatusFilters.find(f => f.key === 'completed').pressed, 'true');
  v.onAssistantQuery({ target:{ value:'zzz' } }); v = c.renderVals();
  assert.equal(v.assistantNoMatches, true); assert.equal(v.assistantEmpty, false);
  v.onAssistantClearFilters(); v = c.renderVals();
  assert.equal(v.assistantCards.length, c.state.assistantRuns.length);
  assert.equal(JSON.stringify(c.state.assistantRuns), runsBefore);
  assert.equal(JSON.stringify(c.state.tasks), tasksBefore, 'Kaizen tasks untouched');
  assert.equal(calls.fetch,0);
});

test('detail shows reverse timeline, distinguishes elapsed and active time and closes with Escape', async () => {
  const calls = { api:0, fetch:0 }, c = component(calls);
  workspaceRole(c, 'admin'); c.state.view = 'assistant'; await c.loadAssistantRuns('data');
  let v = c.renderVals();
  const queued = v.assistantCards.find(card => card.status === 'queued');
  queued.onOpen(); v = c.renderVals();
  assert.equal(v.assistantHasDetail, true); assert.equal(v.assistantLayoutDetail, 'true');
  assert.equal(v.detailActiveValue, 'Sin registro');
  assert.match(v.detailElapsedLabel, /transcurrido/i);
  const progress = v.assistantCards.find(card => card.status === 'in_progress');
  progress.onOpen(); v = c.renderVals();
  assert.notEqual(v.detailActiveValue, 'Sin registro'); assert.equal(v.detailHasWarnings, true);
  const times = v.detailEvents.map(e => Date.parse(e.iso)).filter(Number.isFinite);
  assert.deepEqual(times, [...times].sort((a, b) => b - a));
  assert.ok(v.detailEvents.every(e => e.abs && e.iso));
  const cancelled = v.assistantCards.find(card => card.status === 'cancelled');
  assert.match(cancelled.elapsedLabel, /no cuenta como resuelta/);
  const missing = v.assistantCards.find(card => card.delegatedMissing);
  assert.equal(missing.elapsedValue, 'Sin dato');
  c.handleEscape({ key:'Escape', target:{ closest:() => null }, preventDefault(){} });
  assert.equal(c.state.assistantSelId, null); assert.equal(c.renderVals().assistantHasDetail, false);
  assert.equal(calls.api,0); assert.equal(calls.fetch,0);
});

test('loading, empty and error states are distinct and retry restores demo data', async () => {
  const c = component({ api:0, fetch:0 }); workspaceRole(c, 'admin'); c.state.view = 'assistant';
  await c.loadAssistantRuns('loading'); let v = c.renderVals();
  assert.equal(v.assistantLoading, true); assert.equal(v.assistantReady, false);
  await c.loadAssistantRuns('empty'); v = c.renderVals();
  assert.equal(v.assistantEmpty, true); assert.equal(v.assistantHasCards, false); assert.equal(v.assistantNoMatches, false);
  await c.loadAssistantRuns('error'); v = c.renderVals();
  assert.equal(v.assistantHasError, true); assert.equal(v.assistantCards.length, 0); assert.ok(v.assistantError);
  await v.onAssistantRetry(); v = c.renderVals();
  assert.equal(v.assistantReady, true); assert.ok(v.assistantCards.length > 0);
});

test('assistant section requires active workspace admin access, including direct-view and loader guards', async () => {
  const calls = { api:0, fetch:0 }, c = component(calls);
  Object.assign(c.state, c.seed()); workspaceRole(c, 'member', 2);
  c.state.view = 'assistant';
  c.state.assistantRuns = demo.demoRuns(NOW);
  c.state.assistantStatus = 'ready';
  c.state.assistantSelId = 'demo-run-1';

  let v = c.renderVals();
  assert.equal(v.isAssistant, false, 'a manually selected assistant view is not rendered for a member');
  assert.equal(v.viewTitle, 'Hoy', 'the hidden view title is not exposed');
  assert.equal(v.assistantCards.length, 0, 'demo records are omitted from the render model');
  assert.ok(!v.navItems.some(item => item.id === 'assistant'), 'no assistant navigation item for a member');

  c.state.assistantSelId = null;
  c.openAssistantRun('demo-run-1');
  assert.equal(c.state.assistantSelId, null, 'opening details is blocked for a member');
  c.state.assistantSelId = 'demo-run-1';
  c.componentDidUpdate();
  assert.equal(c.state.view, 'today', 'losing access exits the direct view');
  assert.equal(c.state.assistantRuns.length, 0, 'losing access clears loaded demo records');
  assert.equal(c.state.assistantSelId, null);

  c.state.view = 'assistant';
  await c.loadAssistantRuns('data');
  assert.equal(c.state.view, 'today', 'a member is redirected from a direct view');
  assert.equal(c.state.assistantRuns.length, 0);
  assert.equal(c.state.assistantStatus, 'idle');
  assert.equal(c.state.assistantSelId, null);
  assert.deepEqual({api:calls.api,fetch:calls.fetch}, { api:0, fetch:0 });

  const admin = component({ api:0, fetch:0 });
  Object.assign(admin.state, admin.seed()); workspaceRole(admin, 'admin');
  admin.state.view = 'assistant';
  const adminView = admin.renderVals();
  assert.ok(adminView.navItems.some(item => item.id === 'assistant'), 'workspace admins can see the section');
  assert.equal(adminView.isAssistant, true);
  await admin.loadAssistantRuns('data');
  assert.ok(admin.renderVals().assistantCards.length > 0, 'workspace admins can load the synthetic fixture');
});

test('person supervision uses one task identity for timeline and explicit hourly blocks', async () => {
  const day=new Date(),key=`${day.getFullYear()}-${String(day.getMonth()+1).padStart(2,'0')}-${String(day.getDate()).padStart(2,'0')}`;
  const from=new Date(`${key}T09:00:00`),until=new Date(`${key}T17:00:00`),blockStart=new Date(`${key}T10:00:00`),blockEnd=new Date(`${key}T11:00:00`);
  const block={id:'block-1',start:blockStart.toISOString(),end:blockEnd.toISOString(),timeZone:'UTC'};
  const task={id:'assigned-1',title:'Synthetic scheduled task',list:'today',done:false,project_name:'Project Alpha',scheduledStart:from.toISOString(),scheduledEnd:until.toISOString(),workBlocks:[block,{...block}]};
  const collisionA={id:'a/b',title:'Synthetic unscheduled A',done:false,project_name:'Project Alpha',scheduledStart:'',scheduledEnd:'',workBlocks:[]};
  const collisionB={id:'a?b',title:'Synthetic unscheduled B',done:false,project_name:'Project Alpha',scheduledStart:'',scheduledEnd:'',workBlocks:[]};
  const calls={api:0,fetch:0,supervisionPeople:[{id:1,name:'Synthetic Admin',workspace_role:'admin'},{id:2,name:'Synthetic Member',workspace_role:'member'}],supervisionTasks:{1:[],2:[task,{...task,title:'Synthetic duplicate'},collisionA,collisionB]}};
  const c=component(calls);workspaceRole(c,'admin');c.state.view='supervision';
  await c.loadSupervisionPeople();
  let v=c.renderVals();
  assert.equal(v.isSupervisionView,true);assert.equal(v.viewTitle,'Por persona');assert.equal(c.state.supervisionPersonId,'');
  assert.equal(v.supervisionNeedsPerson,true,'loading people does not silently select a member');
  await v.onSupervisionPerson({target:{value:'2'}});v=c.renderVals();
  assert.equal(c.state.supervisionTasks.length,3,'duplicate task IDs are removed at the API boundary');
  assert.equal(v.supervisionTimelineRows.length,1);assert.equal(v.supervisionUnscheduledRows.length,2);assert.equal(v.supervisionBlockRows.length,1,'duplicate workBlock IDs do not produce duplicate rows');
  assert.equal(v.supervisionTimelineRows[0].anchor,v.supervisionBlockRows[0].anchor,'both views link to the same canonical task');
  assert.ok(v.supervisionTimelineRows[0].start&&v.supervisionTimelineRows[0].end,'timeline keeps both full schedule bounds');
  assert.notEqual(v.supervisionUnscheduledRows[0].anchor,v.supervisionUnscheduledRows[1].anchor,'distinct task IDs cannot collide after anchor encoding');
  assert.equal(v.supervisionBlockRows[0].start,'10:00','hourly view comes from the explicit workBlock only');
  assert.ok(v.supervisionTimeZone);assert.match(supervisionMarkup,/El rango completo del cronograma no se convierte en horas trabajadas/);
  assert.match(supervisionMarkup,/aria-live="polite"/);assert.match(supervisionMarkup,/datetime="\{\{ t\.startIso \}\}"/);
  await v.onSupervisionPerson({target:{value:''}});v=c.renderVals();
  assert.equal(c.state.supervisionPersonId,'');assert.equal(v.supervisionNeedsPerson,true);assert.equal(v.supervisionError,'','clearing the person selection is not an error');
  assert.equal(calls.api,2);
});

test('markup keeps the demo label persistent and controls accessible from 320 px', () => {
  assert.ok(markup.length > 0 && css.length > 0);
  const banner = markup.indexOf('assistant-demo-banner');
  assert.ok(banner >= 0 && banner < markup.indexOf('assistantLoading') && banner < markup.indexOf('assistantReady'), 'label outside state branches');
  assert.match(markup, /<label for="assistant-search">/); assert.match(markup, /id="assistant-search"/);
  assert.match(markup, /aria-pressed="\{\{ f\.pressed \}\}"/); assert.match(markup, /role="group" aria-label="Filtrar por estado"/);
  assert.match(markup, /role="status" aria-live="polite"/); assert.match(markup, /role="alert"/);
  assert.match(markup, /option value="none">Servidor · sin cargar/);
  assert.match(markup, /assistantNotLoaded/);
  assert.match(markup, /id="assistant-detail-heading" tabIndex="-1"/); assert.match(markup, /<ol class="assistant-timeline">/);
  assert.match(markup, /<time datetime="\{\{ card\.delegatedIso \}\}">/);
  for (const selector of ['.assistant-filter', '.assistant-open', '.assistant-search .input', '.assistant-state .btn', '.assistant-detail-top .btn', '.assistant-demo-states summary'])
    assert.match(css, new RegExp(selector.replace(/[.*]/g, '\\$&') + '[^{]*\\{[^}]*min-height:(4[4-9]|5\\d)px'), selector);
  assert.match(css, /\.assistant-view :focus-visible \{ outline:3px solid/);
  assert.match(css, /minmax\(min\(100%,300px\),1fr\)/, 'cards collapse to one column below 300 px');
  assert.match(css, /@media \(max-width:900px\)[\s\S]*\.assistant-filters \{ grid-template-columns:repeat\(2,minmax\(0,1fr\)\); \}/);
  assert.match(html, /<script src="\.\/assistant-runs\.js\?v=[^"]+"><\/script>/);
  assert.match(source.slice(source.indexOf('loadAssistantRuns('), source.indexOf('focusAssistant(')), /this\.api\('assistant_runs'/);
  assert.match(source.slice(source.indexOf('loadSupervisionTasks('),source.indexOf('memberName(')),/this\.api\('supervision_tasks'/);
  assert.match(html,/\.supervision-toolbar \.btn \{ min-height:44px; \}/);
  assert.match(supervisionMarkup,/<strong>\{\{ t\.title \}\}<\/strong>/,'timeline titles are plain text rather than self-links');
});
