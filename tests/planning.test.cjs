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
function delegationFixture() {
  const c=component(); c.state.auth={id:1,role:'user'};c.state.workspaceId=1;
  c.state.workspaceMembers=[{id:1,name:'Owner'},{id:2,name:'Recipient'},{id:3,name:'Other'}];
  c.state.tasks=[{id:'t',title:'Task',_owner_id:1,sharedWith:[2],seconds:99,subtasks:[],tags:[],list:'inbox'}];
  c.syncRemoteTasks=async()=>({ok:true});return c;
}
test('delegation drafts do not change saved recipients; cancel discards them',()=>{
  const c=delegationFixture();c.openDelegate('t');c.toggleDelegationDraft(2);c.toggleDelegationDraft(3);assert.deepEqual([...c.state.delegationDraft],[3]);assert.deepEqual(c.state.tasks[0].sharedWith,[2]);c.closeDelegation();c.openDelegate('t');assert.deepEqual([...c.state.delegationDraft],[2]);assert.equal(c.state.pomo.running,false);assert.equal(c.state.activeId,null);
});
test('delegation saves exactly once while pending and applies the server result',async()=>{
  const c=delegationFixture();c.openDelegate('t');c.toggleDelegationDraft(3);const calls=[];let finish;
  c.api=async(action,payload)=>{calls.push({action,payload});return new Promise(r=>{finish=r;});};
  const saving=c.saveDelegation();await Promise.resolve();await c.saveDelegation();c.toggleDelegationDraft(2);c.closeDelegation();assert.equal(c.state.delegationBusy,true);assert.equal(c.state.delegatingId,'t');assert.equal(calls.length,1);assert.deepEqual([...calls[0].payload.user_ids],[2,3]);assert.deepEqual(c.state.tasks[0].sharedWith,[2]);
  finish({ok:true,shared_user_ids:[2,3],delegated_at:'2026-10-05 12:00:00'});await saving;assert.equal(c.state.delegationBusy,false);assert.equal(c.state.delegatingId,null);assert.deepEqual(c.state.tasks[0]._shared_user_ids,[2,3]);assert.equal(c.state.tasks[0].seconds,99);
});
test('delegation server and network errors preserve recipients and allow retry',async()=>{
  const c=delegationFixture();c.openDelegate('t');c.toggleDelegationDraft(3);c.api=async()=>({ok:false,error:'Membership changed'});await c.saveDelegation();assert.equal(c.state.delegationError,'Membership changed');assert.equal(c.state.delegatingId,'t');assert.deepEqual(c.state.tasks[0].sharedWith,[2]);c.api=async()=>{throw new Error('Connection interrupted');};await c.saveDelegation();assert.equal(c.state.delegationError,'Connection interrupted');assert.equal(c.state.delegationBusy,false);c.api=async()=>({ok:true,shared_user_ids:[2,3]});await c.saveDelegation();assert.equal(c.state.delegatingId,null);assert.deepEqual(c.state.tasks[0].sharedWith,[2,3]);
});
test('new task must sync before delegation; failed sync never sends task_share',async()=>{
  const c=delegationFixture();c.openDelegate('t');const actions=[];c.syncRemoteTasks=async()=>{actions.push('sync');return {ok:false,error:'Task not saved'};};c.api=async()=>{actions.push('share');return {ok:true};};await c.saveDelegation();assert.deepEqual(actions,['sync']);assert.equal(c.state.delegationError,'Task not saved');assert.deepEqual(c.state.tasks[0].sharedWith,[2]);c.syncRemoteTasks=async()=>{actions.push('sync');return {ok:true};};await c.saveDelegation();assert.deepEqual(actions,['sync','sync','share']);
});
test('delegation candidates obey owner and project membership; empty save revokes direct shares',async()=>{
  const c=delegationFixture();c.state.tasks[0].project='p';c.state.projects=[{id:'p',member_ids:[1,2]}];c.openDelegate('t');c.toggleDelegationDraft(3);assert.deepEqual([...c.state.delegationDraft],[2]);assert.equal(c.renderVals().delegationOptions.length,1);c.toggleDelegationDraft(2);let sent;c.api=async(_,payload)=>{sent=payload.user_ids;return {ok:true,shared_user_ids:[]};};await c.saveDelegation();assert.deepEqual([...sent],[]);assert.deepEqual(c.state.tasks[0].sharedWith,[]);c.state.auth.id=2;c.openDelegate('t');assert.equal(c.state.delegatingId,null);
});

test('calendar uses local day, Monday week and actual month boundaries',()=>{
  const anchor=new Date(2026,9,7,14,30).getTime();
  const day=planning.calendar('day',anchor,anchor),week=planning.calendar('week',anchor,anchor),month=planning.calendar('month',anchor,anchor);
  assert.equal(new Date(day.start).getHours(),0);assert.equal(new Date(day.end).getDate(),8);
  assert.equal(new Date(week.start).getDay(),1);assert.equal(new Date(week.start).getDate(),5);assert.equal(week.columns.length,7);
  assert.equal(new Date(month.start).getDate(),1);assert.equal(new Date(month.end).getMonth(),10);assert.equal(month.columns.length,31);
  assert.ok(day.today>0&&day.today<100);assert.equal(planning.calendar('week',anchor,week.end).today,null);
  assert.equal(planning.calendar('other',anchor).mode,'week');
});
test('period navigation handles short months and year boundaries without skipping',()=>{
  const jan=new Date(2026,0,31,12).getTime();
  const feb=planning.shiftCalendar('month',jan,1);assert.equal(new Date(feb).getMonth(),1);assert.equal(new Date(feb).getDate(),1);
  assert.equal(planning.calendar('month',feb).columns.length,28);
  const previous=planning.shiftCalendar('month',jan,-1);assert.equal(new Date(previous).getFullYear(),2025);assert.equal(new Date(previous).getMonth(),11);
  const next=planning.shiftCalendar('week',jan,1);assert.equal(next,planning.calendar('week',jan).end);
  const day=planning.shiftCalendar('day',jan,1);assert.equal(new Date(day).getMonth(),1);assert.equal(new Date(day).getDate(),1);
});
test('calendar geometry follows DST boundaries, including partial-hour transitions',()=>{
  const previous=process.env.TZ;
  try {
    process.env.TZ='America/New_York';
    for(const [month,date,hours] of [[2,8,23],[10,1,25]]){
      const day=planning.calendar('day',new Date(2026,month,date,12).getTime());
      assert.equal((day.end-day.start)/3600000,hours);assert.equal(day.columns.length,hours);
      assert.ok(Math.abs(day.columns.reduce((n,c)=>n+c.width,0)-100)<0.00001);
      assert.equal(day.columns.at(-1).end,day.end);
    }
    const fall=planning.calendar('day',new Date(2026,10,1,12).getTime());
    assert.notEqual(fall.columns[1].label,fall.columns[2].label);
    const week=planning.calendar('week',new Date(2026,2,8,12).getTime());assert.equal((week.end-week.start)/3600000,167);
    process.env.TZ='Australia/Lord_Howe';
    const partial=planning.calendar('day',new Date(2026,9,4,12).getTime());assert.equal((partial.end-partial.start)/3600000,23.5);assert.equal(partial.columns.at(-1).end,partial.end);
    assert.ok(Math.abs(partial.columns.reduce((n,c)=>n+c.width,0)-100)<0.00001);
  } finally { if(previous===undefined)delete process.env.TZ;else process.env.TZ=previous; }
});
test('visible tasks clip to the period; midnight boundaries and invalid dates stay out',()=>{
  const range=planning.calendar('day',new Date(2026,9,6,12).getTime());
  const make=(id,start,end)=>({id,title:id,scheduledStart:new Date(start).toISOString(),scheduledEnd:new Date(end).toISOString()});
  const tasks=[make('spans',range.start-3600000,range.end+3600000),make('before',range.start-3600000,range.start),make('after',range.end,range.end+3600000),make('late',range.end-60000,range.end),{id:'bad',scheduledStart:'bad',scheduledEnd:'bad'}];
  const result=planning.calendarTasks(tasks,range);
  assert.deepEqual(result.visible.map(v=>v.task.id),['spans','late']);assert.deepEqual(result.outside.map(t=>t.id),['before','after']);
  const spans=result.visible[0];assert.equal(spans.left,0);assert.equal(spans.width,100);assert.ok(spans.continuesBefore&&spans.continuesAfter);
  const late=result.visible[1];assert.ok(late.width>0&&late.left<100);assert.equal(late.left+late.width,100);
});
test('invalid ranges cannot inflate the Cronograma navigation count',()=>{
  const c=component();c.state.view='timeline';
  const task={id:'valid',title:'Valid',list:'inbox',tags:[],subtasks:[],scheduledStart:'2026-10-06T10:00:00Z',scheduledEnd:'2026-10-06T11:00:00Z'};
  c.state.tasks=[task,{...task,id:'backwards',scheduledEnd:'2026-10-06T09:00:00Z'},{...task,id:'missing',scheduledEnd:''},{...task,id:'bad',scheduledStart:'bad'},{...task,id:'trash',list:'trash'}];
  c.state.timelineAnchor=Date.parse(task.scheduledStart);
  const v=c.renderVals();assert.equal(v.navItems.find(n=>n.label==='Cronograma').count,1);assert.equal(v.timelineScheduledCount,1);assert.equal(v.unscheduledCount,3);assert.equal(v.timelineVisibleCount,1);
  assert.equal(v.unscheduledRows[0].action,'Revisar fechas');
});
test('short tasks retain an accessible target and exact duration beside multi-month tasks',()=>{
  const c=component();c.state.view='timeline';c.state.timelineMode='month';c.state.timelineAnchor=new Date(2026,9,6).getTime();
  const task={id:'short',title:'30 minutos',list:'inbox',project:'',tags:[],subtasks:[],seconds:900,pomos:2,scheduledStart:'2026-10-06T10:00:00Z',scheduledEnd:'2026-10-06T10:30:00Z'};
  c.state.tasks=[task,{...task,id:'long',title:'Dos meses',scheduledEnd:'2026-12-06T10:00:00Z'}];
  const before=JSON.stringify(c.state.tasks),v=c.renderVals(),row=v.timelineRows.find(t=>t.title==='30 minutos');
  assert.match(row.barStyle.width,/32px/);assert.match(row.openLabel,/30 minutos/);assert.ok(parseFloat(row.durationStyle.width)<1);assert.equal(row.duration,c.fmtDur(1800));assert.equal(row.actual,c.fmtDur(900));
  assert.equal(JSON.stringify(c.state.tasks),before);row.onOpen();assert.equal(c.state.selId,'short');assert.equal(c.state.pomo.running,false);assert.equal(c.state.activeId,null);
});
test('period controls, project filters and locating an outside task preserve underlying data',()=>{
  const c=component();c.state.view='timeline';c.state.timelineAnchor=new Date(2026,9,6,12).getTime();
  const task={id:'a',title:'A',list:'inbox',tags:[],subtasks:[],project:'p',seconds:900,scheduledStart:'2026-10-06T10:00:00Z',scheduledEnd:'2026-10-06T11:00:00Z'};
  c.state.tasks=[task,{...task,id:'b',title:'B',project:'q'},{...task,id:'outside',title:'Outside',project:'p',scheduledStart:'2027-02-01T10:00:00Z',scheduledEnd:'2027-02-01T11:00:00Z'},{id:'undated',title:'No dates',list:'inbox',tags:[],subtasks:[],project:''}];
  const before=JSON.stringify(c.state.tasks);
  let v=c.renderVals();assert.equal(v.timelineVisibleCount,2);assert.equal(v.outsideCount,1);assert.equal(v.unscheduledCount,1);
  v.onTimelineProject({target:{value:'p'}});v=c.renderVals();assert.equal(v.timelineVisibleCount,1);assert.equal(v.outsideCount,1);assert.equal(v.unscheduledCount,0);
  v.onTimelineNext();v=c.renderVals();assert.equal(v.timelineVisibleCount,0);assert.equal(v.outsideCount,2);assert.match(v.timelineEmptyMessage,/periodo/);
  v.onTimelinePrevious();v=c.renderVals();assert.equal(v.timelineVisibleCount,1);
  v.outsideRows.find(t=>t.title==='Outside').onLocate();v=c.renderVals();assert.equal(v.timelineRows[0].title,'Outside');
  v.onTimelineToday();assert.ok(Math.abs(c.state.timelineAnchor-Date.now())<1000);
  v.onTimelineMode({target:{value:'day'}});assert.equal(c.renderVals().timelineMode,'day');
  v.onTimelineProject({target:{value:'__none__'}});v=c.renderVals();assert.equal(v.timelineScheduledCount,0);assert.equal(v.unscheduledCount,1);assert.match(v.timelineEmptyMessage,/Añade/);
  assert.equal(JSON.stringify(c.state.tasks),before);
});
test('planning, editing and removing dates update the calendar without changing project or tracked work',()=>{
  const c=component();c.state.view='timeline';c.state.timelineMode='day';c.state.timelineAnchor=new Date(2026,9,6,12).getTime();
  c.state.tasks=[{id:'t',title:'Task',list:'inbox',project:'p',tags:[],subtasks:[],seconds:1234,pomos:2,sharedWith:[2],done:false}];
  let v=c.renderVals();assert.equal(v.timelineRows.length,0);assert.equal(v.unscheduledCount,1);v.unscheduledRows[0].onOpen();
  c.state.scheduleStartDraft='2026-10-06T10:00';c.state.scheduleEndDraft='2026-10-06T10:30';c.renderVals().onSaveSchedule();
  v=c.renderVals();assert.equal(v.timelineRows.length,1);assert.equal(v.unscheduledCount,0);const width=parseFloat(v.timelineRows[0].durationStyle.width);
  c.state.scheduleEndDraft='2026-10-06T11:00';v.onSaveSchedule();v=c.renderVals();assert.equal(parseFloat(v.timelineRows[0].durationStyle.width),2*width);
  c.state.scheduleEndDraft='2026-10-06T09:00';v.onSaveSchedule();v=c.renderVals();assert.match(v.scheduleError,/posterior/);assert.equal(parseFloat(v.timelineRows[0].durationStyle.width),2*width);
  v.onClearSchedule();v=c.renderVals();assert.equal(v.timelineRows.length,0);assert.equal(v.unscheduledCount,1);assert.equal(v.navItems.find(n=>n.label==='Cronograma').count,0);
  const task=c.state.tasks[0];assert.equal(task.project,'p');assert.equal(task.seconds,1234);assert.equal(task.pomos,2);assert.deepEqual([...task.sharedWith],[2]);assert.equal(task.done,false);assert.equal(c.state.pomo.running,false);
});

test('simplified navigation retains all destinations and applies admin visibility',()=>{
  const c=component();Object.assign(c.state,c.seed());c.state.auth={id:1,role:'user'};
  let v=c.renderVals();assert.equal(v.primaryNavItems.length,6);
  assert.deepEqual([...v.primaryNavItems.map(n=>n.id)].sort(),['calendar','delegated','inbox','projects','timeline','today']);
  assert.deepEqual([...v.primaryNavItems,...v.secondaryNavItems].map(n=>n.id).sort(),[...v.navItems.map(n=>n.id)].sort());
  assert.ok(!v.secondaryNavItems.some(n=>n.id==='admin'));
  v.secondaryNavItems.find(n=>n.id==='stats').onClick();v=c.renderVals();assert.equal(v.isStats,true);assert.match(v.secondaryNavLabel,/Estadísticas/);
  c.state.auth.role='admin';v=c.renderVals();assert.ok(v.secondaryNavItems.some(n=>n.id==='admin'));
  v.primaryNavItems.find(n=>n.id==='projects').onClick();assert.equal(c.renderVals().isProjects,true);
});
test('opening row options never selects or swipes the task; text still opens it',()=>{
  const c=component();Object.assign(c.state,c.seed());const t=c.state.tasks[0],row=c.taskRow(t);
  for(const tag of ['summary','details','input','button']){
    const target={closest:selector=>selector.split(',').includes(tag)?{}:null};
    row.onRowOpen({target});c.beginSwipe(t.id,10,20,target);
    assert.equal(c.state.selId,null);assert.equal(c.swipeId,undefined);
  }
  row.onRowOpen({target:{closest:()=>null}});assert.equal(c.state.selId,t.id);
  assert.equal(c.state.pomo.running,false);
});
test('Escape closes the focused disclosure and restores focus before exiting full screen',()=>{
  const c=component();c.state.full=true;let focused=0,prevented=0;
  const disclosure={open:true,querySelector:()=>({focus:()=>focused++})};
  const target={closest:()=>disclosure};
  c.handleEscape({key:'Enter',target});assert.equal(disclosure.open,true);
  c.handleEscape({key:'Escape',target,preventDefault:()=>prevented++});
  assert.equal(disclosure.open,false);assert.equal(focused,1);assert.equal(prevented,1);assert.equal(c.state.full,true);
  c.handleEscape({key:'Escape',target:{closest:()=>null}});assert.equal(c.state.full,false);
});
test('compact calendar labels distinguish single-day, multi-day and cross-year ranges',()=>{
  const c=component();c.state.view='timeline';c.state.timelineMode='month';c.state.timelineAnchor=new Date(2026,9,6).getTime();
  const task={id:'a',title:'Task',list:'inbox',tags:[],subtasks:[],project:'',scheduledStart:new Date(2026,9,6,10).toISOString(),scheduledEnd:new Date(2026,9,6,11).toISOString()};
  c.state.tasks=[task,{...task,id:'b',scheduledEnd:new Date(2026,9,7,11).toISOString()},{...task,id:'c',scheduledEnd:new Date(2027,0,7,11).toISOString()}];
  const rows=c.renderVals().timelineRows;
  assert.doesNotMatch(rows[0].timeLabel,/oct/);assert.match(rows[1].timeLabel,/6.*oct.*7.*oct/);assert.match(rows[2].timeLabel,/2026.*2027/);
  assert.match(rows[2].openLabel,/2026.*2027/);
});

test('calendar responsibility uses confirmed recipients instead of creator or project membership',()=>{
  const c=delegationFixture();
  c.state.projects=[{id:'p',member_ids:[1,2,3]}];
  const t={_owner_id:1,project:'p',sharedWith:[]};
  assert.equal(c.responsibleLabel(t),'Sin asignar');
  assert.equal(c.responsibleLabel({...t,sharedWith:[2]}),'Responsable: Recipient');
  assert.equal(c.responsibleLabel({...t,sharedWith:[2,3,2]}),'Responsables: Recipient, Other');
  assert.equal(c.responsibleLabel({...t,sharedWith:[2],_shared_user_ids:[]}),'Sin asignar');
  assert.equal(c.responsibleLabel({...t,_shared_user_ids:[3],sharedWith:[2]}),'Responsable: Other');
  assert.equal(c.responsibleLabel({...t,sharedWith:[99]}),'Responsable: nombre no disponible');
  assert.equal(c.responsibleLabel({...t,sharedWith:[0,-1,'bad',null]}),'Sin asignar');
});
test('responsibility changes only after confirmed delegation; failed save and drafts preserve the label',async()=>{
  const c=delegationFixture();c.state.tasks[0].scheduledStart='2026-10-06T10:00:00Z';c.state.tasks[0].scheduledEnd='2026-10-06T11:00:00Z';c.state.timelineAnchor=Date.parse(c.state.tasks[0].scheduledStart);
  const label=()=>c.renderVals().timelineRows[0].responsibleLabel;
  assert.equal(label(),'Responsable: Recipient');c.openDelegate('t');c.toggleDelegationDraft(2);c.toggleDelegationDraft(3);assert.equal(label(),'Responsable: Recipient');
  c.api=async()=>({ok:false,error:'Denied'});await c.saveDelegation();assert.equal(label(),'Responsable: Recipient');
  c.api=async()=>({ok:true,shared_user_ids:[3]});await c.saveDelegation();assert.equal(label(),'Responsable: Other');
  c.state.workspaceMembers.find(m=>m.id===3).name='Renamed';assert.equal(label(),'Responsable: Renamed');
  c.openDelegate('t');c.toggleDelegationDraft(3);c.api=async()=>({ok:true,shared_user_ids:[]});await c.saveDelegation();assert.equal(label(),'Sin asignar');
});
test('every calendar list includes responsibility without changing dates or task data',()=>{
  const c=delegationFixture();const base=c.state.tasks[0];c.state.timelineAnchor=new Date(2026,9,6).getTime();
  c.state.tasks=[{...base,scheduledStart:new Date(2026,9,6,10).toISOString(),scheduledEnd:new Date(2026,9,6,11).toISOString()},{...base,id:'outside',scheduledStart:new Date(2027,0,1,10).toISOString(),scheduledEnd:new Date(2027,0,1,11).toISOString()},{...base,id:'undated',sharedWith:[]}];
  const before=JSON.stringify(c.state.tasks),v=c.renderVals();
  assert.equal(v.timelineRows[0].responsibleLabel,'Responsable: Recipient');assert.match(v.timelineRows[0].openLabel,/Responsable: Recipient/);
  assert.equal(v.outsideRows[0].responsibleLabel,'Responsable: Recipient');assert.equal(v.unscheduledRows[0].responsibleLabel,'Sin asignar');assert.equal(JSON.stringify(c.state.tasks),before);
});
test('personal dashboards and counters isolate users and admins while preserving project collaboration',()=>{
  const c=component(),base={title:'Task',list:'inbox',quadrant:'q2',project:'p',tags:[],subtasks:[],seconds:0,pomos:0,done:false,sharedWith:[]};
  c.state.projects=[{id:'p',name:'Shared',member_ids:[1,2,4]}];
  c.state.tasks=[{...base,id:'owner',_owner_id:1},{...base,id:'member',_owner_id:2},{...base,id:'assigned',_owner_id:1,sharedWith:[2],_shared_user_ids:[2]},{...base,id:'admin',_owner_id:4},{...base,id:'stale',_owner_id:1,sharedWith:[2],_shared_user_ids:[]}];
  c.state.view='inbox';
  for(const [id,role,expected] of [[1,'user',['assigned','owner','stale']],[2,'user',['assigned','member']],[4,'admin',['admin']]]){
    c.state.auth={id,role};const v=c.renderVals();assert.deepEqual([...v.listTasks.map(t=>t.id)].sort(),expected);assert.equal(v.navItems.find(n=>n.id==='inbox').count,expected.length);
    assert.equal(v.quadCells.flatMap(q=>q.tasks).length,expected.length);
  }
  c.state.view='project';c.state.projFilter='p';assert.equal(c.renderVals().listTasks.length,5);
});
test('team overview is permission-gated and its tasks never enter personal lists or sync',async()=>{
  const c=delegationFixture();c.state.auth={id:4,role:'admin'};c.state.workspaces=[{id:1,member_role:'member'},{id:2,member_role:'admin'}];
  let calls=0;c.api=async()=>{calls++;return {ok:true,tasks:[],projects:[]};};
  await c.loadTeamTasks();assert.equal(calls,0);assert.ok(!c.renderVals().navItems.some(n=>n.id==='team'));
  c.state.workspaces[0].member_role='admin';const before=JSON.stringify(c.state.tasks);
  c.api=async(action)=>{assert.equal(action,'team_tasks');calls++;return {ok:true,tasks:[{...c.state.tasks[0],id:'peer',_owner_id:2}],projects:[]};};
  await c.loadTeamTasks();assert.equal(calls,1);assert.equal(c.state.teamTasks[0].id,'peer');assert.equal(JSON.stringify(c.state.tasks),before);
  c.state.view='team';assert.equal(c.renderVals().isTeamView,true);assert.equal(c.renderVals().teamRows.length,1);
  c.state.view='inbox';assert.equal(c.renderVals().listTasks.length,0);
  c.state.auth.role='user';c.state.workspaces[0].member_role='member';c.state.view='team';assert.equal(c.renderVals().isTeamView,false);assert.equal(c.renderVals().teamRows.length,0);
});
test('late team responses cannot reveal data after switching user or workspace',async()=>{
  const c=delegationFixture();c.state.workspaces=[{id:1,member_role:'admin'}];let finish;
  c.api=()=>new Promise(resolve=>{finish=resolve;});const loading=c.loadTeamTasks();
  c.state.auth={id:2,role:'user'};c.state.workspaces[0].member_role='member';finish({ok:true,tasks:[{id:'private',title:'Private'}],projects:[]});await loading;
  assert.equal(c.state.teamTasks.length,0);assert.equal(c.renderVals().teamRows.length,0);
});
test('late collaboration reads cannot replace tasks after an identity switch',async()=>{
  const c=delegationFixture();let finish;c.api=()=>new Promise(resolve=>{finish=resolve;});
  const loading=c.loadRemoteTasks(1);c.state.auth={id:2,role:'user'};c.state.tasks=[];
  finish({ok:true,tasks:[{id:'private',title:'Private',_owner_id:1}],projects:[]});await loading;
  assert.equal(c.state.tasks.length,0);
});
test('team navigation count follows loaded rows and refreshes without changing personal totals',async()=>{
  const c=delegationFixture();c.state.workspaces=[{id:1,member_role:'admin'}];c.state.view='inbox';
  const base={...c.state.tasks[0],_owner_id:2,sharedWith:[],_shared_user_ids:[]};
  let fixture=[{...base,id:'one'},{...base,id:'two'},{...base,id:'done',done:true,list:'done'},{...base,id:'trash',list:'trash'}];
  c.api=async action=>{assert.equal(action,'team_tasks');return {ok:true,tasks:fixture,projects:[]};};
  const count=v=>v.navItems.find(n=>n.id==='team')?.count;
  assert.equal(count(c.renderVals()),0);await c.loadTeamTasks();
  let v=c.renderVals();assert.equal(v.teamRows.length,4);assert.equal(count(v),4);assert.equal(v.listTasks.length,1);assert.equal(v.navItems.find(n=>n.id==='inbox').count,1);
  fixture=fixture.slice(0,2);await c.loadTeamTasks();v=c.renderVals();assert.equal(v.teamRows.length,2);assert.equal(count(v),2);assert.equal(v.listTasks.length,1);
  fixture=[];await c.loadTeamTasks();assert.equal(count(c.renderVals()),0);
  c.state.workspaces[0].member_role='member';assert.equal(count(c.renderVals()),undefined);
});
test('daily calendar uses valid local dates, month boundaries and DST days',()=>{
  assert.equal(planning.validDateKey('2028-02-29'),true);
  for(const key of ['2026-02-29','2026-04-31','2026-13-01','2026-1-01','invalid'])assert.equal(planning.validDateKey(key),false);
  const previous=process.env.TZ;
  try {
    process.env.TZ='America/New_York';
    const start=new Date(2026,2,8,0).toISOString(),end=new Date(2026,2,9,0).toISOString();
    const task={id:'dst',title:'DST',list:'future',scheduledStart:start,scheduledEnd:end};
    assert.deepEqual(planning.dailyAgenda([task],'2026-03-08').map(t=>t.id),['dst']);
    assert.equal(planning.dailyAgenda([task],'2026-03-09').length,0);
    const month=planning.dailyMonth([task],'2026-03-08');
    assert.equal(month.days.length,42);assert.equal(month.days.find(d=>d.key==='2026-03-08').count,1);
    assert.equal(planning.shiftDailyMonth('2026-01-31',1),'2026-02-01');
    assert.equal(planning.shiftDailyMonth('2026-01-31',-1),'2025-12-01');
    assert.equal(planning.shiftDailyDay('2026-03-08',1),'2026-03-09');
    assert.equal(planning.shiftDailyDay('2026-11-01',-1),'2026-10-31');
  } finally { if(previous===undefined)delete process.env.TZ;else process.env.TZ=previous; }
});
test('daily agenda includes deadlines and planned intervals once, excludes trash and invalid ranges',()=>{
  const tasks=[
    {id:'both',title:'Both',list:'future',due:'2026-10-07',scheduledStart:'2026-10-07T08:00:00.000Z',scheduledEnd:'2026-10-07T10:00:00.000Z'},
    {id:'due',title:'Due',list:'inbox',due:'2026-10-07'},
    {id:'invalid',title:'Invalid',list:'future',scheduledStart:'bad',scheduledEnd:'2026-10-08T00:00:00.000Z'},
    {id:'trash',title:'Trash',list:'trash',due:'2026-10-07'}
  ];
  assert.deepEqual(planning.dailyAgenda(tasks,'2026-10-07').map(t=>t.id),['both','due']);
  assert.equal(planning.dailyAgenda(tasks,'2026-02-30').length,0);
  assert.equal(planning.dailyMonth(tasks,'2026-10-07').agenda.length,2);
});
test('daily view isolates personal work for members and admins; team data stays separate',()=>{
  const c=component();c.state.auth={id:1,role:'admin'};c.state.workspaceId=1;c.state.workspaces=[{id:1,member_role:'admin'}];c.state.dailyDate='2026-10-07';c.state.view='calendar';
  const base={title:'Task',list:'future',due:'2026-10-07',done:false,project:'p',sharedWith:[],subtasks:[],tags:[]};
  c.state.tasks=[{...base,id:'own',_owner_id:1},{...base,id:'assigned',_owner_id:2,_shared_user_ids:[1]},{...base,id:'project-only',_owner_id:2,_shared_user_ids:[],_project_member:true}];
  c.state.teamTasks=[{...base,id:'team-only',_owner_id:3}];
  let v=c.renderVals();assert.deepEqual(v.dailyRows.map(t=>t.title),['Task','Task']);assert.equal(v.dailyCount,2);
  c.state.auth={id:2,role:'user'};v=c.renderVals();assert.equal(v.dailyCount,2);
  c.state.auth={id:3,role:'user'};v=c.renderVals();assert.equal(v.dailyCount,0);
});
test('assigning an existing task from a day rejects project-only and team tasks',()=>{
  const c=component();c.state.auth={id:1,role:'admin'};c.state.dailyDate='2026-10-07';c.state.view='calendar';
  const base={title:'Task',list:'inbox',due:'',done:false,sharedWith:[],subtasks:[],tags:[]};
  c.state.tasks=[{...base,id:'own',_owner_id:1},{...base,id:'collaborator',_owner_id:2,_project_member:true,_shared_user_ids:[]}];
  c.state.teamTasks=[{...base,id:'team',_owner_id:3}];
  let v=c.renderVals();assert.deepEqual(v.dailyAssignOptions.map(t=>t.id),['own']);
  c.state.dailyAssignId='collaborator';c.assignDailyTask();assert.equal(c.state.tasks[1].due,'');
  c.state.dailyAssignId='own';c.assignDailyTask();v=c.renderVals();assert.equal(c.state.tasks[0].due,'2026-10-07');assert.equal(v.dailyCount,1);assert.equal(v.dailyAssignOptions.length,0);
});
test('creating, dating and completing from a day update the same task without changing planning or time',()=>{
  const c=component();c.state.auth={id:1};c.state.dailyDate='2026-10-07';c.state.view='calendar';c.state.dailyTitle='  Prepare report  ';c.state.dailyProject='p';c.state.projects=[{id:'p',_can_assign:true}];
  c.addDailyTask();assert.equal(c.state.tasks.length,1);const id=c.state.tasks[0].id;
  assert.equal(c.state.tasks[0].due,'2026-10-07');assert.equal(c.state.tasks[0].title,'Prepare report');
  c.patch(id,{scheduledStart:'2026-10-07T08:00:00.000Z',scheduledEnd:'2026-10-07T09:00:00.000Z',seconds:120});
  c.changeDailyDue(id,'2026-10-08');assert.equal(c.state.tasks[0].list,'future');assert.equal(c.state.tasks[0].due,'2026-10-08');
  c.changeDailyDue(id,'2026-02-30');assert.equal(c.state.tasks[0].due,'2026-10-08');
  c.state.dailyDate='2026-10-08';let v=c.renderVals();assert.equal(v.dailyCount,1);assert.match(v.dailyRows[0].responsibleLabel,/Sin asignar/);
  v.dailyRows[0].onToggle();assert.equal(c.state.tasks[0].done,true);assert.equal(c.state.tasks[0].seconds,120);assert.equal(c.state.tasks[0].scheduledStart,'2026-10-07T08:00:00.000Z');
  assert.equal(c.renderVals().dailyPending,0);
  c.changeDailyDue(id,'');assert.equal(c.state.tasks[0].due,'');assert.equal(c.state.tasks[0].project,'p');
});
test('today-first navigation keeps the agenda separate from month browsing and restores a valid date',()=>{
  const c=component();c.state.view='calendar';c.state.dailyDate='2026-10-31';
  let v=c.renderVals();v.onDailyNext();assert.equal(c.state.dailyDate,'2026-10-31');assert.equal(c.state.dailyBrowseMonth,'2026-11-01');
  v=c.renderVals();assert.equal(v.dailyMonthLabel,'Noviembre de 2026');assert.match(v.dailyDayLabel,/31 de octubre/);
  v.onDailyNextDay();assert.equal(c.state.dailyDate,'2026-11-01');assert.equal(c.state.dailyBrowseMonth,'');
  v=c.renderVals();assert.equal(v.dailyDays.some(d=>d.selected),true);assert.equal(v.dailyHeroLabel,'Día seleccionado');
  assert.equal(v.navItems.find(n=>n.id==='calendar').label,'Calendario de hoy');
  v.navItems.find(n=>n.id==='calendar').onClick();assert.equal(c.state.dailyDate,c.dkey(Date.now()));assert.equal(c.renderVals().dailyHeroLabel,'Hoy');
  c.state.dailyDate='bad';assert.equal(c.selectedDailyDate(),c.dkey(Date.now()));
  assert.match(html,/class="daily-hero"/);assert.ok(html.indexOf('class="daily-hero"')<html.indexOf('class="daily-month-picker"'));
  assert.match(html,/dailyDate:s\.dailyDate/);
  assert.match(html,/KaizenPlanning\.validDateKey\(base\.dailyDate/);
});
