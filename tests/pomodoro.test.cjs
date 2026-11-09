process.env.TZ='UTC';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const P=require('../planning.js'),A=require('../assistant-runs.js');
const html=fs.readFileSync(require('node:path').join(__dirname,'../index.html'),'utf8'),source=html.match(/<script type="text\/x-dc"[^>]*>([\s\S]*?)<\/script>/)[1];
const baseTask=(id='a')=>({id,title:'Tarea '+id,list:'inbox',due:'',project:'',tags:[],subtasks:[],quadrant:'q2',seconds:0,pomos:0,estPomos:1,_owner_id:1,_shared_user_ids:[]});
function fixture(at='2026-11-09T09:00:00Z'){
 let now=Date.parse(at),requests=0,permission='default';const sounds=[],shown=[],cancelled=[],scheduled=[];
 class Clock extends Date{constructor(...args){super(...(args.length?args:[now]))}static now(){return now}}
 class Audio{state='suspended';currentTime=10;destination={};constructor(){sounds.push(this)}resume(){this.state='running';return Promise.resolve()}close(){this.state='closed';return Promise.resolve()}createOscillator(){const o={frequency:{setValueAtTime(value){o.frequencyValue=value}},connect(){},disconnect(){},start(at){o.startAt=at},stop(at){o.stopAt=at}};(this.oscillators||=[]).push(o);return o}createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){},disconnect(){}}}}
 const registration={showNotification:async(title,options)=>shown.push({title,options}),getNotifications:async()=>[],pushManager:{getSubscription:async()=>null}};
 const Notification={get permission(){return permission},async requestPermission(){requests++;permission='granted';return permission}};
 const context={DCLogic:class{props={};setState(u){this.state={...this.state,...(typeof u==='function'?u(this.state):u)}}},KaizenPlanning:P,KaizenAssistantRuns:A,Date:Clock,Intl,console,Notification,window:{AudioContext:Audio,Notification,addEventListener(){},removeEventListener(){}},navigator:{serviceWorker:{ready:Promise.resolve(registration)},userAgent:'Macintosh'},document:{removeEventListener(){}},setTimeout:()=>1,clearTimeout(){},clearInterval(){},localStorage:{getItem(){return null},setItem(){},removeItem(){}}};
 vm.createContext(context);vm.runInContext(source+';globalThis.App=Component;',context);
 const app=new context.App();app.setState({auth:{id:1,role:'member'},workspaceId:1,tasks:[baseTask()],log:{},activeId:'a'});
 app.api=async()=>({ok:false,error:'Fixture: no real push server'});app.cancelPush=id=>{cancelled.push(id)};
 app.schedulePush=(...args)=>{scheduled.push(args);return Promise.resolve(false)};app.scheduleFocusCycle=(...args)=>{scheduled.push(args);return Promise.resolve(false)};
 return {app,context,sounds,shown,registration,cancelled,scheduled,now:()=>now,setNow:value=>now=typeof value==='number'?value:Date.parse(value),advance:ms=>now+=ms,requests:()=>requests,permission:value=>permission=value};
}
const flush=async()=>{for(let i=0;i<10;i++)await Promise.resolve()};
test('starting and completing never asks for notification permission',async()=>{
 const f=fixture();f.app.togglePomo();await flush();assert.equal(f.requests(),0);f.advance(25*60000);f.app.tick();await flush();assert.equal(f.requests(),0);assert.equal(f.app.state.timerNotice.title,'Pomodoro terminado');assert.equal(f.shown.length,0);
});
test('only explicit activation asks for permission; denied stays denied',async()=>{
 const f=fixture();await f.app.enableNotifications();assert.equal(f.requests(),1);assert.equal(f.app.state.notificationState,'granted');
 f.permission('denied');await f.app.enableNotifications();assert.equal(f.requests(),1);assert.equal(f.app.state.notificationState,'denied');
});
test('test notification is local without prompting; sound spans at least five seconds',async()=>{
 const f=fixture();await f.app.testNotification();await flush();assert.equal(f.requests(),0);assert.equal(f.sounds.length,1);
 const oscillators=f.sounds[0].oscillators;assert.equal(oscillators.length,12);const duration=Math.max(...oscillators.map(o=>o.stopAt))-Math.min(...oscillators.map(o=>o.startAt));assert.ok(duration>=5&&duration<6);assert.ok(Math.abs(duration-5.4)<1e-9);
 assert.equal(f.app.state.alertSoundPlaying,true);f.app.stopAlertSound();assert.equal(f.sounds[0].state,'closed');
});
test('granted notifications use service worker and stay silent when the five-second sound plays',async()=>{
 const f=fixture();f.permission('granted');await f.app.prepareAlertSound();f.app.notify('Fin','Ciclo terminado','job-a');await flush();
 assert.equal(f.shown.length,1);assert.equal(f.shown[0].options.tag,'job-a');assert.equal(f.shown[0].options.silent,true);assert.equal(f.requests(),0);
 f.app.notify('Fin','Ciclo terminado','job-a');await flush();assert.equal(f.shown.length,1);
});
test('an already delivered push keeps the required app sound without a second system notification',async()=>{
 const f=fixture();f.permission('granted');f.registration.getNotifications=async()=>[{}];await f.app.prepareAlertSound();f.app.notify('Fin','Ciclo terminado','job-a');await flush();assert.equal(f.shown.length,0);assert.equal(f.sounds[0].oscillators.length,12);assert.ok(f.app.state.timerNotice);
});
test('25/5 cycles repeat until stopped and duplicate ticks do not credit twice',()=>{
 const f=fixture();f.app.notify=()=>{};f.app.startTaskTimer('a');
 f.advance(25*60000);f.app.tick();f.app.tick();assert.equal(f.app.state.pomo.mode,'break');assert.equal(f.app.state.tasks[0].seconds,1500);assert.equal(f.app.state.tasks[0].pomos,1);
 f.advance(5*60000);f.app.tick();assert.equal(f.app.state.pomo.mode,'focus');assert.equal(f.app.state.pomo.running,true);assert.equal(f.app.state.pomo.remaining,1500);assert.equal(f.app.state.tasks[0].seconds,1500);
 f.advance(25*60000);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,3000);assert.equal(f.app.state.tasks[0].pomos,2);assert.equal(f.app.state.pomo.mode,'break');
});
test('late wake-up advances through missed phases once and emits only the latest notice',()=>{
 const f=fixture(),notices=[];f.app.notify=(...args)=>notices.push(args);f.app.startTaskTimer('a');f.advance(120*60000);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,6000);assert.equal(f.app.state.tasks[0].pomos,4);assert.equal(f.app.state.pomo.mode,'focus');assert.equal(notices.length,1);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,6000);assert.equal(notices.length,1);
});
test('restoring the persisted checkpoint never duplicates recorded work',()=>{
 const f=fixture();f.app.notify=()=>{};f.app.startTaskTimer('a');f.advance(60000);f.app.tick();
 const g=fixture();g.setNow(f.now());g.app.setState(JSON.parse(JSON.stringify(f.app.state)));g.app.tick();assert.equal(g.app.state.tasks[0].seconds,60);g.advance(10000);g.app.tick();assert.equal(g.app.state.tasks[0].seconds,70);
});
test('pause excludes paused time, resume keeps phase, and stop resets focus while preserving work',()=>{
 const f=fixture();f.app.startTaskTimer('a');f.advance(60000);f.app.togglePomo();assert.equal(f.app.state.tasks[0].seconds,60);assert.equal(f.app.state.pomo.running,false);assert.equal(f.app.state.pomo.remaining,1440);
 f.advance(10*60000);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,60);f.app.togglePomo();f.advance(30000);f.app.stopPomo();assert.equal(f.app.state.tasks[0].seconds,90);assert.equal(f.app.state.pomo.mode,'focus');assert.equal(f.app.state.pomo.running,false);assert.equal(f.app.state.pomo.remaining,1500);
 f.advance(3600000);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,90);
});
test('crossing midnight splits the focus between the correct local days',()=>{
 const f=fixture('2026-11-09T23:50:00Z');f.app.notify=()=>{};f.app.startTaskTimer('a');f.advance(20*60000);f.app.tick();
 const days=f.app.state.tasks[0].focusByDay;assert.equal(days['2026-11-09']['1:1'],600);assert.equal(days['2026-11-10']['1:1'],600);assert.equal(f.app.state.tasks[0].seconds,1200);assert.match(f.app.renderVals().todayFocusTotal,/10 min/);
 f.advance(5*60000);f.app.tick();assert.equal(f.app.state.tasks[0].focusByDay['2026-11-10']['1:1'],900);assert.equal(f.app.state.log['2026-11-10'].pomos,1);
});
test('fractional tick remainders survive reload without rounding duplication',()=>{
 const f=fixture();f.app.startTaskTimer('a');for(const ms of [100,400,600]){f.advance(ms);f.app.tick();}f.app.tick();assert.equal(f.app.state.tasks[0].seconds,1);
 const g=fixture();g.setNow(f.now());g.app.setState(JSON.parse(JSON.stringify(f.app.state)));g.advance(900);g.app.tick();assert.equal(g.app.state.tasks[0].seconds,2);
});
test('changing tasks credits the previous task and starts a fresh focus cycle',()=>{
 const f=fixture();f.app.setState({tasks:[baseTask('a'),baseTask('b')]});f.app.startTaskTimer('a');f.advance(120000);f.app.startTaskTimer('b');assert.equal(f.app.state.tasks[0].seconds,120);assert.equal(f.app.state.tasks[1].seconds,0);assert.equal(f.app.state.pomo.remaining,1500);f.advance(60000);f.app.tick();assert.equal(f.app.state.tasks[1].seconds,60);
});
test('today summary uses recorded personal day values, never historical totals or another user',()=>{
 const f=fixture();f.app.state.tasks[0].seconds=99999;assert.equal(f.app.renderVals().todayFocusRows.length,0);
 f.app.state.tasks[0].focusByDay={'2026-11-09':{'1:1':90,'2:1':999}};const view=f.app.renderVals();assert.equal(view.todayFocusRows.length,1);assert.match(view.todayFocusRows[0].label,/1 min/);f.app.setState({auth:{id:3}});assert.equal(f.app.renderVals().todayFocusRows.length,0);
});
test('unassigned focus is recorded separately and displayed without inventing a task',()=>{
 const f=fixture();f.app.setState({activeId:null});f.app.togglePomo();f.advance(60000);f.app.tick();const rows=f.app.renderVals().todayFocusRows;assert.equal(rows.length,1);assert.match(rows[0].label,/Sin tarea/);assert.equal(f.app.state.tasks[0].seconds,0);
});
test('stale scheduled pushes are not recreated after the timer is paused',async()=>{
 const f=fixture();f.app.startTaskTimer('a');f.app.togglePomo();delete f.app.schedulePush;let calls=0;f.app.pushSubscriptionHash='x';f.app.api=async()=>{calls++;return {ok:true}};const result=await f.app.schedulePush(f.now()+60000,'old','a','focus',false);assert.equal(result,false);assert.equal(calls,0);
});
test('push worker deduplicates tags and notification clicks focus Kaizen rather than another site page',async()=>{
 const handlers={},shown=[],focused=[],scope='https://example.test/tareas-pomodoro/';
 const context={URL,Date,self:{registration:{scope,getNotifications:async()=>[{}],showNotification:async(...args)=>shown.push(args)},clients:{claim:async()=>{},matchAll:async()=>[{url:'https://example.test/other/',focus:()=>focused.push('wrong')},{url:scope,focus:()=>focused.push('kaizen')}],openWindow:async url=>focused.push(url)},skipWaiting(){},addEventListener:(type,handler)=>handlers[type]=handler}};
 vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname,'../sw.js'),'utf8'),context);
 let pending;handlers.push({data:{json:()=>({title:'Fin',tag:'same'})},waitUntil:p=>pending=p});await pending;assert.equal(shown.length,0);
 handlers.notificationclick({notification:{close(){},data:{url:'./'}},waitUntil:p=>pending=p});await pending;assert.deepEqual(focused,['kaizen']);
});

test('DST repeat and skipped hours credit only elapsed focus seconds',()=>{
 const previous=process.env.TZ;
 try{
  process.env.TZ='America/New_York';
  for(const at of ['2026-11-01T05:50:00Z','2026-03-08T06:50:00Z']){
   const f=fixture(at);f.app.notify=()=>{};f.app.startTaskTimer('a');f.advance(60*60000);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,3000);assert.equal(f.app.state.tasks[0].pomos,2);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,3000);
  }
 }finally{process.env.TZ=previous;}
});

test('a running cycle cannot credit a different user or workspace',()=>{
 for(const change of [{auth:{id:2}},{workspaceId:2}]){
  const f=fixture();f.app.startTaskTimer('a');f.advance(60000);f.app.setState(change);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,0);assert.equal(f.app.state.pomo.running,false);
 }
});
test('a restored timer waits for identity and tasks to finish loading before reconciling',()=>{
 const f=fixture();f.app.startTaskTimer('a');f.app.timerRestoring=true;f.advance(60000);f.app.tick();assert.equal(f.app.state.tasks[0].seconds,0);f.app.timerRestoring=false;f.app.tick();assert.equal(f.app.state.tasks[0].seconds,60);
});
