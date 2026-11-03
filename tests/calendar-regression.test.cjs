process.env.TZ='UTC';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const Planning=require('../planning.js'),Assistant=require('../assistant-runs.js');
const html=fs.readFileSync(require('path').join(__dirname,'../index.html'),'utf8');
const code=html.match(/<script type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/)[1];
const baseTask=(overrides={})=>({id:'a',title:'Tarea sintética',list:'inbox',due:'',project:'',quadrant:'q2',tags:[],subtasks:[],done:false,seconds:0,pomos:0,estPomos:1,_owner_id:1,_shared_user_ids:[],_revision:'r1',...overrides});
const plan={scheduledStart:'2026-11-03T09:00:00.000Z',scheduledEnd:'2026-11-03T10:00:00.000Z',scheduleTimeZone:'UTC'};
function makeApp(){
 const context=vm.createContext({DCLogic:class{props={};setState(update){this.state={...this.state,...(typeof update==='function'?update(this.state):update)};}},KaizenPlanning:Planning,KaizenAssistantRuns:Assistant,console,Date,Intl,setTimeout,clearTimeout,localStorage:{getItem(){return null},setItem(){}},window:{confirm(){return false}},URLSearchParams});
 vm.runInContext(code+'\nglobalThis.App=Component;',context);
 const app=new context.App();app.setState({auth:{id:1,role:'member'},workspaceId:10,workspaces:[{id:10,member_role:'admin'}],workspaceMembers:[{id:1,name:'Ana'},{id:2,name:'Beto'},{id:3,name:'Carla'}],view:'calendar',dailyDate:'2026-11-03',tasks:[baseTask({...plan})]});
 return {app,context};
}
function ids(items){return Array.from(items,t=>t.id)}
test('scheduled ranges appear only when explicitly requested, without changing task data',()=>{
 const task=baseTask(plan),before=JSON.stringify(task);
 assert.equal(Planning.hourlyDay([task],'2026-11-03').blocks.length,0);
 const day=Planning.hourlyDay([task],'2026-11-03',0,{includeSchedule:true});
 assert.equal(day.blocks.length,1);assert.equal(day.blocks[0].source,'schedule');assert.equal(day.blocks[0].duration,60);assert.equal(JSON.stringify(task),before);
});
test('explicit blocks take precedence and do not double-book the full timeline',()=>{
 const task=baseTask({...plan,workBlocks:[{id:'b1',start:'2026-11-04T09:00:00Z',end:'2026-11-04T10:00:00Z'}]});
 assert.equal(Planning.hourlyDay([task],'2026-11-03',0,{includeSchedule:true}).blocks.length,0);
 const next=Planning.hourlyDay([task],'2026-11-04',0,{includeSchedule:true});assert.equal(next.blocks.length,1);assert.equal(next.blocks[0].source,'workBlock');
});
test('invalid dates, midnight boundaries, and trash remain excluded',()=>{
 const tasks=[baseTask({scheduledStart:'bad',scheduledEnd:'bad'}),baseTask({...plan,list:'trash'}),baseTask({scheduledStart:'2026-11-02T23:00:00Z',scheduledEnd:'2026-11-03T00:00:00Z'})];
 assert.equal(Planning.hourlyDay(tasks,'2026-11-03',0,{includeSchedule:true}).blocks.length,0);
});
test('overnight schedules are clipped without changing their timestamps',()=>{
 const task=baseTask({scheduledStart:'2026-11-02T23:00:00Z',scheduledEnd:'2026-11-03T01:00:00Z'});
 const block=Planning.hourlyDay([task],'2026-11-03',0,{includeSchedule:true}).blocks[0];assert.equal(block.duration,60);assert.equal(block.continuesBefore,true);
});
test('people filter includes creator OR confirmed delegate, deduplicates, rejects unknown members',()=>{
 const {app}=makeApp();const tasks=[baseTask({id:'created',_owner_id:2}),baseTask({id:'assigned',_owner_id:3,_shared_user_ids:[2]}),baseTask({id:'both',_owner_id:2,_shared_user_ids:[2]}),baseTask({id:'none',_owner_id:1})];
 assert.deepEqual(ids(app.filterTasksByResponsible(tasks,'2')),['created','assigned','both']);assert.equal(app.filterTasksByResponsible(tasks,'99').length,0);assert.deepEqual(ids(app.filterTasksByResponsible(tasks,'__unassigned__')),['created','none']);
});
test('administrative calendar collection never enters editable tasks or sync payload',async()=>{
 const {app}=makeApp(),calls=[];app.setState({dailyResponsibleId:'2'});const personal=app.state.tasks;
 app.api=async(action,payload)=>{calls.push({action,payload});return action==='team_tasks'?{ok:true,tasks:[baseTask({id:'private',_owner_id:2,_team_read_only:true})],projects:[]}:{ok:true}};
 await app.loadCalendarReadTasks();assert.equal(app.state.tasks,personal);assert.deepEqual(ids(app.calendarSourceTasks('calendar')),['private']);
 await app.syncRemoteTasks(10,app.state.tasks,[]);assert.equal(calls.at(-1).action,'tasks_sync');assert.deepEqual(ids(calls.at(-1).payload.tasks),['a']);
});
test('non-admin never requests or consumes administrative data',async()=>{
 const {app}=makeApp();app.setState({dailyResponsibleId:'2',workspaces:[{id:10,member_role:'member'}]});app.api=()=>{throw Error('forbidden call')};await app.loadCalendarReadTasks();assert.equal(app.calendarReadContext(),'');assert.equal(app.calendarSourceTasks('calendar'),app.state.tasks);
});
test('stale person response cannot overwrite newer selection',async()=>{
 const {app}=makeApp();app.setState({dailyResponsibleId:'2'});let finish;app.api=()=>new Promise(r=>finish=r);const pending=app.loadCalendarReadTasks();app.setState({dailyResponsibleId:'3'});finish({ok:true,tasks:[baseTask({id:'stale',_owner_id:2})]});await pending;assert.equal(app.state.calendarReadTasks.length,0);assert.equal(app.calendarSourceTasks('calendar').length,0);
});
test('logout, workspace changes and permission downgrades invalidate administrative reads',async()=>{
 for(const update of [{auth:null},{workspaceId:11},{workspaces:[{id:10,member_role:'member'}]}]){
  const {app}=makeApp();app.setState({dailyResponsibleId:'2'});let finish;app.api=()=>new Promise(r=>finish=r);const pending=app.loadCalendarReadTasks();app.setState(update);app.updateCalendarReadContext();finish({ok:true,tasks:[baseTask({id:'stale'})]});await pending;assert.equal(app.state.calendarReadTasks.length,0);
 }
});
test('failed calendar fetch is visible and never silently falls back to incomplete data',async()=>{
 const {app}=makeApp();app.setState({dailyResponsibleId:'2'});app.api=async()=>({ok:false,error:'Acceso retirado'});await app.loadCalendarReadTasks();assert.equal(app.state.calendarReadStatus,'error');assert.equal(app.state.calendarReadError,'Acceso retirado');assert.equal(app.calendarSourceTasks('calendar').length,0);
});
test('both calendar and timeline remain read-only for selected people',async()=>{
 const {app}=makeApp();app.setState({view:'timeline',timelineMode:'day',timelineAnchor:Date.parse(plan.scheduledStart),timelineResponsibleId:'2'});app.api=async()=>({ok:true,tasks:[baseTask({...plan,id:'readonly',_owner_id:2})]});await app.loadCalendarReadTasks();const vals=app.renderVals();assert.equal(vals.timelineRows.length,1);assert.equal(vals.timelineRows[0].disabled,true);vals.timelineRows[0].onOpen();assert.equal(app.state.selId,null);
 app.setState({view:'calendar',dailyResponsibleId:'2'});await app.loadCalendarReadTasks();const daily=app.renderVals();assert.equal(daily.hourlyBlocks.length,1);assert.equal(daily.hourlyBlocks[0].canResize,false);daily.hourlyBlocks[0].onOpen();assert.equal(app.state.selId,null);
});
test('schedule resize changes only scheduledEnd, not work history or seconds',()=>{
 const {app}=makeApp(),snapshot=app.hourlyResizeSnapshot('a','__schedule__','schedule');assert.ok(snapshot);assert.equal(app.commitHourlyResize(snapshot,app.hourlyResizeEnd(snapshot,35)),true);assert.equal(app.state.tasks[0].scheduledEnd,'2026-11-03T10:30:00.000Z');assert.equal(app.state.tasks[0].seconds,0);assert.equal(app.state.tasks[0].workBlocks,undefined);
});
test('explicit block resize preserves schedule, metadata and other blocks',()=>{
 const {app}=makeApp();app.patch('a',{workBlocks:[{id:'b1',start:plan.scheduledStart,end:plan.scheduledEnd,timeZone:'UTC',note:'keep'},{id:'b2',start:'2026-11-04T09:00:00Z',end:'2026-11-04T10:00:00Z'}]});const snapshot=app.hourlyResizeSnapshot('a','b1','workBlock');app.commitHourlyResize(snapshot,app.hourlyResizeEnd(snapshot,15));assert.equal(app.state.tasks[0].scheduledEnd,plan.scheduledEnd);assert.equal(app.state.tasks[0].workBlocks[0].note,'keep');assert.equal(app.state.tasks[0].workBlocks[1].end,'2026-11-04T10:00:00Z');
});
test('resize clamps to 15 minutes minimum and current local midnight',()=>{
 const {app}=makeApp(),snapshot=app.hourlyResizeSnapshot('a','__schedule__','schedule');assert.equal(app.hourlyResizeEnd(snapshot,-500),Date.parse(plan.scheduledStart)+15*60000);assert.equal(app.hourlyResizeEnd(snapshot,99999),Date.parse('2026-11-04T00:00:00Z'));
});
test('resize rejects stale revision, identity, date, read-only view and trashed tasks',()=>{
 for(const change of [a=>a.patch('a',{_revision:'r2'}),a=>a.setState({workspaceId:11}),a=>a.setState({auth:{id:2}}),a=>a.setState({dailyDate:'2026-11-04'}),a=>a.setState({dailyResponsibleId:'2'}),a=>a.patch('a',{list:'trash'})]){
  const {app}=makeApp(),snapshot=app.hourlyResizeSnapshot('a','__schedule__','schedule');change(app);assert.equal(app.commitHourlyResize(snapshot,snapshot.end+15*60000),false);
 }
});
test('mouse row drag does not start swipe or delete; touch delete asks and cancel preserves task',()=>{
 const {app,context}=makeApp(),row=app.taskRow(app.state.tasks[0]);row.onPointerDown({pointerType:'mouse',clientX:0,clientY:0});row.onPointerUp({pointerType:'mouse',clientX:140,clientY:0});assert.equal(app.state.tasks[0].list,'inbox');assert.equal(app.swipeId,undefined);
 let confirmations=0;context.window.confirm=()=>{confirmations++;return false};row.onPointerDown({pointerType:'touch',clientX:0,clientY:0});row.onPointerUp({pointerType:'touch',clientX:140,clientY:0});assert.equal(confirmations,1);assert.equal(app.state.tasks[0].list,'inbox');
 context.window.confirm=()=>true;app.moveToTrash('a');assert.equal(app.state.tasks[0].list,'trash');app.restoreTask('a');assert.notEqual(app.state.tasks[0].list,'trash');
});

test('schedule resize keeps an open unedited detail draft aligned',()=>{
 const {app}=makeApp();app.openTask('a');const snapshot=app.hourlyResizeSnapshot('a','__schedule__','schedule');assert.ok(snapshot);app.commitHourlyResize(snapshot,snapshot.end+60*60000);assert.equal(app.state.scheduleEndDraft,'2026-11-03T11:00');app.saveTaskSchedule();assert.equal(app.state.tasks[0].scheduledEnd,'2026-11-03T11:00:00.000Z');
});
test('pending detail edits prevent resize without discarding the draft',()=>{
 const {app}=makeApp();app.openTask('a');app.setState({scheduleEndDraft:'2026-11-03T12:00'});assert.equal(app.hourlyResizeSnapshot('a','__schedule__','schedule'),null);assert.equal(app.renderVals().hourlyBlocks[0].canResize,false);assert.equal(app.state.scheduleEndDraft,'2026-11-03T12:00');assert.equal(app.state.tasks[0].scheduledEnd,plan.scheduledEnd);
});

test('timeline resize supports schedules without changing their work blocks',()=>{
 const {app}=makeApp();app.setState({view:'timeline',timelineMode:'day',timelineAnchor:Date.parse(plan.scheduledStart)});app.patch('a',{workBlocks:[{id:'b1',start:plan.scheduledStart,end:plan.scheduledEnd}]});const snapshot=app.timelineResizeSnapshot('a');assert.ok(snapshot);assert.equal(app.commitHourlyResize(snapshot,app.hourlyResizeEnd(snapshot,60)),true);assert.equal(app.state.tasks[0].scheduledEnd,'2026-11-03T11:00:00.000Z');assert.equal(app.state.tasks[0].workBlocks[0].end,plan.scheduledEnd);
});
test('timeline keyboard supports left/right and preserves identity, period and person restrictions',()=>{
 const {app}=makeApp();app.setState({view:'timeline',timelineMode:'week',timelineAnchor:Date.parse(plan.scheduledStart)});app.keyHourlyResize('a','__schedule__','schedule',{key:'ArrowRight',preventDefault(){},stopPropagation(){}},'timeline');assert.equal(app.state.tasks[0].scheduledEnd,'2026-11-03T10:15:00.000Z');const snapshot=app.timelineResizeSnapshot('a');app.setState({timelineMode:'day'});assert.equal(app.commitHourlyResize(snapshot,snapshot.end+3600000),false);app.setState({timelineResponsibleId:'1'});assert.equal(app.timelineResizeSnapshot('a'),null);
});

test('a full-day schedule can create its first explicit block without changing the schedule',()=>{
 const {app}=makeApp();app.patch('a',{scheduledStart:'2026-11-02T09:00:00.000Z',scheduledEnd:'2026-11-05T18:00:00.000Z'});const before=JSON.stringify(app.state.tasks);let view=app.renderVals();assert.equal(view.hourlySlots.length,0);assert.equal(view.hourlyBlocks[0].canAddBlock,true);view.hourlyBlocks[0].onAddBlock();assert.equal(app.state.slotTaskId,'a');assert.equal(app.state.slotStartDraft,'2026-11-03T09:00');app.cancelSlotCreate();assert.equal(JSON.stringify(app.state.tasks),before);
 view=app.renderVals();view.hourlyBlocks[0].onAddBlock();app.createSlotTask();assert.equal(app.state.tasks.length,1);assert.equal(app.state.tasks[0].workBlocks.length,1);assert.equal(app.state.tasks[0].scheduledEnd,'2026-11-05T18:00:00.000Z');assert.equal(app.state.tasks[0].seconds,0);assert.ok(app.renderVals().hourlySlots.length>0);
 app.setState({dailyResponsibleId:'2'});app.openScheduleBlock('a');assert.equal(app.state.slotCreateOpen,false);
});
