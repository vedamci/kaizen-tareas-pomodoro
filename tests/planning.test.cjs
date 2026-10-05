const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const planning = require('../planning.js');
const html = fs.readFileSync(require('node:path').join(__dirname, '../Gestor de Tareas.dc.html'), 'utf8');
const source = html.match(/<script type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/)[1];
function component() {
  class Logic { props = {focusMinutes:25,breakMinutes:5,showQuadrantColors:true}; setState(next) { Object.assign(this.state, typeof next === 'function' ? next(this.state) : next); } }
  const context = vm.createContext({ DCLogic:Logic, KaizenPlanning:planning, console, setTimeout, clearTimeout, Intl, Date, localStorage:{setItem(){}} });
  return new (vm.runInContext(source+'; Component',context))();
}
test('component parses and renders project and timeline views',()=>{
  const c=component(); Object.assign(c.state,c.seed()); c.state.view='timeline'; const view=c.renderVals(); assert.equal(view.isTimeline,true);assert.equal(view.timelineRows.length,0);assert.equal(view.unscheduledCount,c.state.tasks.length); c.state.view='project';c.state.projFilter='p1';assert.ok(c.renderVals().listTasks.length>0);
});
test('native project options have plain text instead of span children',()=>{
  const runtime=fs.readFileSync(require('node:path').join(__dirname,'../support.js'),'utf8');
  const walk=runtime.slice(runtime.indexOf('  function walkText(node) {'),runtime.indexOf('  function walkFor('));
  const context=vm.createContext({resolve:(vals,p)=>vals[p.trim()],h:(tag,props,...children)=>({tag,children}),getReact:()=>({Fragment:'fragment',isValidElement:()=>false}),warnUnresolved(){}});
  const fn=vm.runInContext(walk+'; walkText',context);
  const result=fn({nodeValue:'{{ name }}',parentElement:{tagName:'OPTION'}})({name:'Proyecto'},null,0);
  assert.ok(result.children.includes('Proyecto'));assert.ok(!result.children.some(c=>c?.tag==='span'));
});
test('existing tasks never gain invented schedule dates',()=>{
  const c=component();const tasks=c.seed().tasks;c.normalizeTaskBuckets(tasks).forEach(t=>assert.equal(t.scheduledStart,undefined));
});
test('schedule round trips through UTC and rejects incomplete or reversed ranges',()=>{
  const value=planning.schedule('2026-10-05T09:30','2026-10-05T11:00');assert.equal(planning.localInput(value.scheduledStart),'2026-10-05T09:30');assert.equal(planning.localInput(value.scheduledEnd),'2026-10-05T11:00');assert.ok(value.scheduledStart.endsWith('Z'));assert.throws(()=>planning.schedule('2026-10-05T09:30',''));assert.throws(()=>planning.schedule('2026-10-05T09:30','2026-10-05T09:30'));assert.throws(()=>planning.schedule('2026-02-30T09:30','2026-03-01T09:30'));assert.equal(planning.schedule('','').scheduledStart,'');
});
test('timezone changes preserve the planned instant; DST gaps fail',()=>{
  const previous=process.env.TZ;try{process.env.TZ='America/Mexico_City';const value=planning.schedule('2026-10-05T09:30','2026-10-05T11:00');assert.equal(value.scheduledStart,'2026-10-05T15:30:00.000Z');process.env.TZ='UTC';assert.equal(planning.localInput(value.scheduledStart),'2026-10-05T15:30');process.env.TZ='America/New_York';assert.throws(()=>planning.schedule('2026-03-08T02:30','2026-03-08T04:00'));}finally{if(previous===undefined)delete process.env.TZ;else process.env.TZ=previous;}
});
test('timeline filters by project or none, keeps undated and completed tasks, excludes trash',()=>{
  const tasks=[{id:'a',title:'A',project:'p',scheduledStart:'2026-10-05T10:00:00.000Z',scheduledEnd:'2026-10-05T11:00:00.000Z',done:true},{id:'b',title:'B',project:'p'},{id:'c',title:'C',project:''},{id:'d',title:'D',project:'p',list:'trash'}];assert.equal(planning.timeline(tasks,'p').scheduled.length,1);assert.equal(planning.timeline(tasks,'p').unscheduled[0].id,'b');assert.equal(planning.timeline(tasks,'__none__').unscheduled[0].id,'c');assert.equal(planning.timeline(tasks).unscheduled.length,2);
});
test('members receive project tasks even without direct delegation',()=>{
  const c=component();c.state.auth={id:2};assert.equal(c.visibleRemoteTasks([{id:'a',_owner_id:1,_project_member:true},{id:'b',_owner_id:1,_shared_user_ids:[]}]).length,1);
});
test('task can move between projects and back to no project without losing fields',async()=>{
  const c=component();c.state.tasks=[{id:'t',project:'a',title:'Task',seconds:99,notes:'keep',scheduledStart:'2026-10-05T10:00:00.000Z'}];c.state.projects=[{id:'a'},{id:'b'}];await c.moveTaskProject('t','b');assert.equal(c.state.tasks[0].project,'b');assert.equal(c.state.tasks[0].seconds,99);await c.moveTaskProject('t','');assert.equal(c.state.tasks[0].project,'');assert.equal(c.state.tasks[0].notes,'keep');
});
test('moving to a forbidden project or moving as collaborator is rejected',async()=>{
  const c=component();c.state.tasks=[{id:'t',project:'a',_can_move:false}];c.state.projects=[{id:'a'},{id:'b',_can_assign:false}];await c.moveTaskProject('t','b');assert.equal(c.state.tasks[0].project,'a');c.state.tasks[0]._can_move=true;await c.moveTaskProject('t','b');assert.equal(c.state.tasks[0].project,'a');
});
test('failed move rolls back and sync errors remain visible',async()=>{
  const c=component();c.state.auth={id:1};c.state.workspaceId=1;c.state.tasks=[{id:'t',project:'a',_owner_id:1}];c.state.projects=[{id:'a'},{id:'b'}];c.api=async()=>({ok:false,error:'Denied'});await c.moveTaskProject('t','b');assert.equal(c.state.tasks[0].project,'a');assert.equal(c.state.syncError,'Denied');assert.equal(c.state.toast,'Denied');
});
test('writes queue in order and never send client membership definitions',async()=>{
  const c=component();c.state.auth={id:1};c.state.workspaceId=1;const calls=[];c.api=async(action,payload)=>{calls.push(payload);await new Promise(r=>setTimeout(r,2));return {ok:true};};await Promise.all([c.syncRemoteTasks(1,[{id:'1'}],[{id:'p',member_ids:[9]}]),c.syncRemoteTasks(1,[{id:'2'}],[])]);assert.equal(calls[0].tasks[0].id,'1');assert.equal(calls[1].tasks[0].id,'2');assert.equal(calls[0].projects,undefined);
});
test('unchanged tasks are not resent; later queued edits use the acknowledged revision',async()=>{
  const c=component();c.state.auth={id:1};c.state.workspaceId=1;c.state.tasks=[{id:'t',title:'First',_revision:'old'}];const calls=[];
  c.api=async(action,payload)=>{calls.push(payload);return {ok:true,revisions:{t:'next-'+calls.length}};};
  await c.syncRemoteTasks(1,c.state.tasks,[]);await c.syncRemoteTasks(1,c.state.tasks,[]);assert.equal(calls.length,1);assert.equal(c.state.tasks[0]._revision,'next-1');
  c.state.tasks[0].title='Changed';await c.syncRemoteTasks(1,c.state.tasks,[]);assert.equal(calls[1].tasks[0]._revision,'next-1');assert.equal(calls[1].tasks[0].title,'Changed');
});
test('schedule changes do not alter real Pomodoro time or completion',()=>{
  const c=component();c.state.tasks=[{id:'t',seconds:1800,pomos:2,done:false}];c.openTask('t');c.state.scheduleStartDraft='2026-10-05T09:00';c.state.scheduleEndDraft='2026-10-05T10:00';c.saveTaskSchedule();const t=c.state.tasks[0];assert.equal(t.seconds,1800);assert.equal(t.pomos,2);assert.equal(t.done,false);assert.ok(t.scheduledStart);const saved=JSON.parse(JSON.stringify(t));assert.equal(saved.scheduledStart,t.scheduledStart);c.state.scheduleEndDraft='2026-10-05T08:00';c.saveTaskSchedule();assert.match(c.state.scheduleError,/posterior/);assert.equal(c.state.tasks[0].scheduledEnd,t.scheduledEnd);
});
