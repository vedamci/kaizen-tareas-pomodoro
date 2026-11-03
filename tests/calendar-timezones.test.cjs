const test=require('node:test'),assert=require('node:assert/strict'),{execFileSync}=require('node:child_process'),path=require('node:path');
const planning=path.resolve(__dirname,'../planning.js');
function run(zone,body){return JSON.parse(execFileSync(process.execPath,['-e',`const P=require(${JSON.stringify(planning)});${body}`],{env:{...process.env,TZ:zone},encoding:'utf8'}));}
test('New York spring-forward has 23 hours and clips by elapsed time',()=>{
 const result=run('America/New_York',`const d=P.hourlyDay([{id:'a',title:'DST',scheduledStart:'2026-03-08T06:30:00Z',scheduledEnd:'2026-03-08T07:30:00Z'}],'2026-03-08',0,{includeSchedule:true});console.log(JSON.stringify({minutes:d.minutes,ticks:d.ticks.length,top:d.blocks[0].top,duration:d.blocks[0].duration,end:new Date(d.end).toISOString()}));`);
 assert.deepEqual(result,{minutes:1380,ticks:23,top:90,duration:60,end:'2026-03-09T04:00:00.000Z'});
});
test('New York fall-back has 25 hours and retains both repeated 01:30 instants',()=>{
 const result=run('America/New_York',`const tasks=[{id:'a',title:'First',scheduledStart:'2026-11-01T05:30:00Z',scheduledEnd:'2026-11-01T06:00:00Z'},{id:'b',title:'Second',scheduledStart:'2026-11-01T06:30:00Z',scheduledEnd:'2026-11-01T07:00:00Z'}];const d=P.hourlyDay(tasks,'2026-11-01',0,{includeSchedule:true});console.log(JSON.stringify({minutes:d.minutes,ticks:d.ticks.length,tops:d.blocks.map(b=>b.top),end:new Date(d.end).toISOString()}));`);
 assert.deepEqual(result,{minutes:1500,ticks:25,tops:[90,150],end:'2026-11-02T05:00:00.000Z'});
});
test('datetime editor preserves the selected offset during a repeated hour',()=>{
 const result=run('America/New_York',`console.log(JSON.stringify(P.schedule('2026-11-01T01:30','2026-11-01T02:00',{start:'2026-11-01T06:30:00Z',end:'2026-11-01T07:00:00Z'})));`);
 assert.equal(result.scheduledStart,'2026-11-01T06:30:00.000Z');assert.equal(result.scheduledEnd,'2026-11-01T07:00:00.000Z');
});
test('Mexico local day boundaries differ from UTC without losing overnight planning',()=>{
 const result=run('America/Mexico_City',`const d=P.hourlyDay([{id:'a',title:'Local midnight',scheduledStart:'2026-11-04T05:30:00Z',scheduledEnd:'2026-11-04T06:30:00Z'}],'2026-11-03',0,{includeSchedule:true});console.log(JSON.stringify({minutes:d.minutes,top:d.blocks[0].top,duration:d.blocks[0].duration,continuesAfter:d.blocks[0].continuesAfter,end:new Date(d.end).toISOString()}));`);
 assert.deepEqual(result,{minutes:1440,top:1410,duration:30,continuesAfter:true,end:'2026-11-04T06:00:00.000Z'});
});

test('actual save handler preserves repeated-hour offsets after timeline resize',()=>{
 const index=path.resolve(__dirname,'../index.html');
 const result=run('America/New_York',`const fs=require('fs'),vm=require('vm'),html=fs.readFileSync(${JSON.stringify(index)},'utf8'),code=html.match(/<script type="text\\/x-dc"[^>]*>([\\s\\S]*?)<\\/script>/)[1];const context=vm.createContext({KaizenPlanning:P,Date,Intl,DCLogic:class{setState(u){this.state={...this.state,...(typeof u==='function'?u(this.state):u)}}}});vm.runInContext(code+';globalThis.App=Component;',context);const app=new context.App();app.setState({view:'timeline',timelineMode:'day',timelineAnchor:Date.parse('2026-11-01T05:30:00Z'),tasks:[{id:'a',title:'Repeated hour',list:'inbox',scheduledStart:'2026-11-01T05:30:00.000Z',scheduledEnd:'2026-11-01T06:30:00.000Z'}]});app.openTask('a');const snap=app.timelineResizeSnapshot('a');app.commitHourlyResize(snap,snap.end+15*60000);app.saveTaskSchedule();console.log(JSON.stringify({start:app.state.tasks[0].scheduledStart,end:app.state.tasks[0].scheduledEnd,error:app.state.scheduleError}));`);
 assert.deepEqual(result,{start:'2026-11-01T05:30:00.000Z',end:'2026-11-01T06:45:00.000Z',error:''});
});
