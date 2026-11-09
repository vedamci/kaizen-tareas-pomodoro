// Headful Chromium, normal background policies, real elapsed time and PulseAudio output.
// Data and API are disposable local fixtures; no Mac or production setting is changed.
const assert=require('node:assert/strict'),fs=require('node:fs/promises'),http=require('node:http'),path=require('node:path'),os=require('node:os');
const {spawn,execFileSync}=require('node:child_process'),{chromium}=require('playwright');
const BASE='ca331b8d0c3ce900201367fa35b8b50b6fc60312',RATE=48000,WAIT_SECONDS=377;
const sleep=ms=>new Promise(r=>setTimeout(r,ms)),children=[],servers=[],browsers=[],recorders=[];
const run=(cmd,args)=>execFileSync(cmd,args,{encoding:'utf8',env:process.env,stdio:['ignore','pipe','pipe']}).trim();
async function startServer(port,baseline){
 const source=baseline?execFileSync('git',['show',BASE+':Gestor de Tareas.dc.html'],{encoding:'utf8'}):await fs.readFile('Gestor de Tareas.dc.html','utf8');
 const allowed=new Set(['support.js','planning.js','assistant-runs.js','assistant-runs-demo.js','sw.js','preview/mock-api.js','kaizen-icon.svg','manifest.webmanifest','_ds/modernist-454c18dc-45af-4299-9be2-71360d2b9a90/styles.css','_ds/modernist-454c18dc-45af-4299-9be2-71360d2b9a90/_ds_bundle.js']);
 const server=http.createServer(async(req,res)=>{
  const file=new URL(req.url,'http://localhost').pathname.slice(1);
  try{
   let content,type='text/html';
   if(!file){content=source.replace(/(<script src="\.\/support\.js\?[^"]+"><\/script>)/,'<script src="./preview/mock-api.js"></script>$1').replace("KEY = 'tareas-modernist-v1'","KEY = 'kaizen-local-preview-ui-v1'").replace('class Component extends DCLogic {','class Component extends DCLogic { constructor(...args){super(...args);window.__kaizenQA=this;}');}
   else{if(!allowed.has(file)){res.writeHead(404);res.end();return;}content=await fs.readFile(file);type=file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':'application/json';}
   res.writeHead(200,{'Content-Type':type+';charset=utf-8','Cache-Control':'no-store'});res.end(content);
  }catch(error){res.writeHead(500);res.end(error.message);}
 });
 await new Promise(r=>server.listen(port,'127.0.0.1',r));servers.push(server);return 'http://127.0.0.1:'+port;
}
function capture(sink){
 const capture={sink,fireAt:0,first:null,last:null,maxRms:0,blocks:[],audio:[],bytes:0,firstSampleAt:null,left:Buffer.alloc(0)};
 const recorder=spawn('parec',['--raw','--device='+sink+'.monitor','--format=s16le','--rate='+RATE,'--channels=1','--latency-msec=20'],{stdio:['ignore','pipe','inherit']});children.push(recorder);recorders.push(recorder);
 recorder.stdout.on('data',chunk=>{
  if(capture.firstSampleAt===null)capture.firstSampleAt=Date.now()-chunk.length/(RATE*2)*1000;
  let buffer=Buffer.concat([capture.left,chunk]);
  while(buffer.length>=1920){
   const block=buffer.subarray(0,1920);buffer=buffer.subarray(1920);let sum=0;for(let i=0;i<block.length;i+=2){const value=block.readInt16LE(i)/32768;sum+=value*value;}
   const rms=Math.sqrt(sum/960),at=capture.firstSampleAt+capture.bytes/(RATE*2)*1000;capture.bytes+=1920;capture.maxRms=Math.max(capture.maxRms,rms);
   if(rms>0.0015){capture.first??=at;capture.last=at+20;}
   if(capture.fireAt&&at>=capture.fireAt-1000&&at<=capture.fireAt+65000)capture.audio.push(Buffer.from(block));
   capture.blocks.push({at,rms});
  }
  capture.left=Buffer.from(buffer);
 });
 return capture;
}
async function saveWav(name,chunks){
 const pcm=Buffer.concat(chunks),header=Buffer.alloc(44);header.write('RIFF',0);header.writeUInt32LE(36+pcm.length,4);header.write('WAVEfmt ',8);header.writeUInt32LE(16,16);header.writeUInt16LE(1,20);header.writeUInt16LE(1,22);header.writeUInt32LE(RATE,24);header.writeUInt32LE(RATE*2,28);header.writeUInt16LE(2,32);header.writeUInt16LE(16,34);header.write('data',36);header.writeUInt32LE(pcm.length,40);await fs.writeFile(name,Buffer.concat([header,pcm]));
}
class CDP{
 constructor(ws){this.ws=ws;this.id=0;this.pending=new Map();ws.addEventListener('message',event=>{const data=JSON.parse(String(event.data));if(data.id){const task=this.pending.get(data.id);if(task){this.pending.delete(data.id);data.error?task.reject(Error(JSON.stringify(data.error))):task.resolve(data.result);}}});}
 send(method,params={},sessionId){const id=++this.id;return new Promise((resolve,reject)=>{this.pending.set(id,{resolve,reject});this.ws.send(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})}));});}
 async evaluate(sessionId,fn,arg){const expression='('+fn.toString()+')('+JSON.stringify(arg)+')',r=await this.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true},sessionId);if(r.exceptionDetails)throw Error(JSON.stringify(r.exceptionDetails));return r.result.value;}
 async attach(targetId){const {sessionId}=await this.send('Target.attachToTarget',{targetId,flatten:true});await this.send('Page.enable',{},sessionId);await this.send('Runtime.enable',{},sessionId);return sessionId;}
}
async function launchNative(sink){
 const profile=await fs.mkdtemp(path.join(os.tmpdir(),'kaizen-background-'));
 const proc=spawn(chromium.executablePath(),['--remote-debugging-port=0','--user-data-dir='+profile,'--no-sandbox','--disable-dev-shm-usage','--enable-automation','--no-first-run','--no-default-browser-check','--window-size=1280,900','about:blank'],{env:{...process.env,PULSE_SINK:sink},stdio:'ignore'});children.push(proc);
 let portFile;
 for(let n=0;n<100;n++){try{portFile=(await fs.readFile(path.join(profile,'DevToolsActivePort'),'utf8')).trim().split('\n');break;}catch{}await sleep(100);}
 assert.ok(portFile,'Native Chrome did not start');
 const ws=new WebSocket('ws://127.0.0.1:'+portFile[0]+portFile[1]);await new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});});
 const client=new CDP(ws),browser={client,async close(){await Promise.race([client.send('Browser.close').catch(()=>{}),sleep(1500)]);ws.close();proc.kill();}};
 browsers.push(browser);return browser;
}
async function waitNative(client,session,fn){
 for(let n=0;n<300;n++){if(await client.evaluate(session,fn))return;await sleep(100);}throw Error('Native Chrome readiness timed out');
}
async function clickNative(client,session,selector){
 const point=await client.evaluate(session,selector=>{const r=document.querySelector(selector).getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};},selector);
 await client.send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point},session);await client.send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point},session);
}

(async()=>{
 try{
  await fs.mkdir('test-results',{recursive:true});
  const xvfb=spawn('Xvfb',['-displayfd','3','-screen','0','1280x900x24','-nolisten','tcp'],{stdio:['ignore','ignore','inherit','pipe']});children.push(xvfb);
  const display=await new Promise((resolve,reject)=>{xvfb.stdio[3].once('data',d=>resolve(':'+String(d).trim()));xvfb.once('error',reject);});process.env.DISPLAY=display;
  const wm=spawn('openbox',['--sm-disable'],{stdio:'ignore',env:process.env});children.push(wm);await sleep(800);
  try{run('pactl',['info']);}catch{run('pulseaudio',['--start','--exit-idle-time=-1']);}
  const cases=[];
  for(const [kind,port,scenario] of [['baseline',4194,'inactive_tab'],['fixed',4195,'minimized_window']]){
   const sink='kaizen_'+kind;run('pactl',['load-module','module-null-sink','sink_name='+sink,'sink_properties=device.description='+sink]);run('pactl',['set-sink-volume',sink,'100%']);
   const cap=capture(sink),url=await startServer(port,kind==='baseline');
   const browser=await launchNative(sink),client=browser.client;
   const command=await client.send('Browser.getBrowserCommandLine'),relevant=command.arguments.filter(arg=>/background|headless|mute|autoplay|intensive/i.test(arg));
   assert.ok(!command.arguments.some(arg=>/^--(headless|mute-audio|disable-background-timer-throttling|disable-backgrounding-occluded-windows|disable-renderer-backgrounding)(=|$)/.test(arg)),JSON.stringify(relevant));
   await client.send('Browser.grantPermissions',{origin:url,permissions:['notifications']});
   const target=await client.send('Target.createTarget',{url,newWindow:true,background:false}),session=await client.attach(target.targetId);
   await waitNative(client,session,()=>window.__kaizenQA?.state.auth&&!window.__kaizenQA.timerRestoring&&window.__kaizenQA.state.tasks.length);
   await client.evaluate(session,()=>{
    document.querySelector('[aria-label="Usuario de demostración"]').parentElement.style.display='none';
   });
   await client.evaluate(session,seconds=>{
    const app=window.__kaizenQA;window.__ticks=[];window.__notices=[];window.__visibility=[];
    const tick=app.tick.bind(app);app.tick=()=>{window.__ticks.push(Date.now());return tick();};
    const notify=app.notify.bind(app);app.notify=(...args)=>{window.__notices.push({at:Date.now(),visibility:document.visibilityState,focus:document.hasFocus(),audio:app.alertAudio?.state,title:args[0]});return notify(...args);};
    document.addEventListener('visibilitychange',()=>window.__visibility.push({at:Date.now(),state:document.visibilityState}));
    app.setState({activeId:'bg-qa',tasks:[{id:'bg-qa',title:'Prueba local de segundo plano',list:'today',due:app.dkey(Date.now()),project:'',quadrant:'q2',tags:[],subtasks:[],done:false,seconds:0,pomos:0,estPomos:1,_owner_id:1,_shared_user_ids:[]}],log:{},pomo:{mode:'focus',remaining:seconds,running:false,accountedAt:null,endsAt:null,breakEndsAt:null,pushJobId:null,breakPushJobId:null}});
   },WAIT_SECONDS);
   await client.send('Target.activateTarget',{targetId:target.targetId});await clickNative(client,session,'.app-header > .btn-primary');
   await waitNative(client,session,()=>window.__kaizenQA.state.alertSoundReady);
   const state=await client.evaluate(session,()=>({fireAt:window.__kaizenQA.state.pomo.endsAt,audio:window.__kaizenQA.alertAudio.state,alarms:window.__kaizenQA.bellAlarms?.size||0}));cap.fireAt=state.fireAt;
   const appWindow=(await client.send('Browser.getWindowForTarget',{targetId:target.targetId})).windowId;
   if(scenario==='inactive_tab'){const tab=await client.send('Target.createTarget',{url:'about:blank',newWindow:false,background:false});await client.send('Target.activateTarget',{targetId:tab.targetId});}
   await client.send('Browser.setWindowBounds',{windowId:appWindow,bounds:{windowState:'minimized'}});
   cases.push({kind,scenario,browser,client,session,cap,appWindow,relevant,initial:state});
  }
  const client=cases[1].client,coverTarget=await client.send('Target.createTarget',{url:'about:blank',newWindow:true,background:false}),coverSession=await client.attach(coverTarget.targetId);
  await client.evaluate(coverSession,()=>{document.title='Otra ventana de Chrome';document.body.innerHTML='<h1>Otra ventana de Chrome activa durante toda la prueba</h1>';});
  await client.send('Target.activateTarget',{targetId:coverTarget.targetId});const coverWindow=(await client.send('Browser.getWindowForTarget',{targetId:coverTarget.targetId})).windowId;assert.notEqual(coverWindow,cases[1].appWindow);await sleep(300);
  for(const item of cases){item.hidden=await item.client.evaluate(item.session,()=>({at:Date.now(),visibility:document.visibilityState,focus:document.hasFocus()}));console.log(JSON.stringify({stage:'visibility-check',kind:item.kind,...item.hidden}));assert.equal(item.hidden.visibility,'hidden');assert.equal(item.hidden.focus,false);console.log(JSON.stringify({stage:'background-start',kind:item.kind,scenario:item.scenario,hidden:item.hidden,initial:item.initial,normalBackgroundFlags:item.relevant,focusEmulation:'not enabled; native Chrome controlled directly',anotherChromeWindow:coverWindow!==item.appWindow}));}
  const deadline=Math.max(...cases.map(item=>item.cap.fireAt))+65000;
  while(Date.now()<deadline){console.log(JSON.stringify({stage:'waiting-with-other-chrome-window-active',remainingSeconds:Math.ceil((deadline-Date.now())/1000)}));await sleep(Math.min(30000,deadline-Date.now()));}
  const results=[];
  for(const item of cases){
   const beforeFront=await item.client.evaluate(item.session,()=>({visibility:document.visibilityState,focus:document.hasFocus(),audio:window.__kaizenQA.alertAudio.state,ticks:window.__ticks,notices:window.__notices,changes:window.__visibility,pomo:window.__kaizenQA.state.pomo}));
   const loud=item.cap.blocks.filter(b=>b.at>=item.cap.fireAt-1000&&b.at<=item.cap.fireAt+65000&&b.rms>0.0015);
   const first=loud[0]?.at??null,last=loud.at(-1)?loud.at(-1).at+20:null;
   const gaps=beforeFront.ticks.slice(1).map((at,i)=>at-beforeFront.ticks[i]),result={kind:item.kind,scenario:item.scenario,hiddenForSeconds:(Date.now()-item.hidden.at)/1000,visibility:beforeFront.visibility,focus:beforeFront.focus,audioState:beforeFront.audio,scheduledAlarms:item.initial.alarms,firstOutputDelayMs:first===null?null:first-item.cap.fireAt,outputSpanMs:first===null?0:last-first,maxOutputRms:Math.max(0,...loud.map(b=>b.rms)),maxJavaScriptTickGapMs:Math.max(0,...gaps),notices:beforeFront.notices,baselineSource:item.kind==='baseline'?BASE:null};
   results.push(result);console.log(JSON.stringify({stage:'background-result',...result}));
   await saveWav('test-results/background-'+item.kind+'.wav',item.cap.audio);
   if(item.kind==='fixed'){assert.equal(beforeFront.visibility,'hidden');assert.equal(beforeFront.focus,false);assert.ok(result.hiddenForSeconds>300);assert.ok(item.initial.alarms>=2);assert.ok(result.firstOutputDelayMs!==null&&Math.abs(result.firstOutputDelayMs)<2500,'Output must reach the virtual speaker on time: '+JSON.stringify(result));assert.ok(result.outputSpanMs>=5000,'Output must last at least five seconds');assert.ok(result.maxOutputRms>0.01);assert.equal(beforeFront.pomo.mode,'break');}
  }
  await fs.writeFile('test-results/background-audio.json',JSON.stringify(results,null,2));
 }finally{
  for(const browser of browsers)await browser.close().catch(()=>{});for(const server of servers)await new Promise(r=>server.close(r));for(const child of children.reverse())child.kill();
 }
})().catch(error=>{console.error(error);process.exitCode=1;});
