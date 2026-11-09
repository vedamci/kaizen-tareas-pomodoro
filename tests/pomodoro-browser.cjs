// Browser QA uses synthetic data; it never touches the real API.
const assert=require('node:assert/strict'),{spawn}=require('node:child_process'),fs=require('node:fs/promises');
const {chromium,webkit}=require('playwright');
const port=4191,url='http://127.0.0.1:'+port,server=spawn(process.execPath,['tools/preview-server.mjs'],{env:{...process.env,KAIZEN_PREVIEW_PORT:String(port)},stdio:'inherit'});
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
 let browser;
 try{
  for(let i=0;i<50;i++){try{if((await fetch(url)).ok)break;}catch{}await pause(100);}
  await fs.mkdir('test-results',{recursive:true});
  for(const [engine,type] of [['chromium',chromium],['webkit',webkit]]){
   browser=await type.launch();const context=await browser.newContext({viewport:{width:1280,height:900},timezoneId:'UTC'}),page=await context.newPage();page.setDefaultTimeout(20000);
   await context.route(url+'/',async route=>{const response=await route.fetch(),body=(await response.text()).replace('class Component extends DCLogic {','class Component extends DCLogic { constructor(...args){super(...args);window.__kaizenQA=this;}');await route.fulfill({response,body});});
   await page.addInitScript(()=>{window.__permissionRequests=0;if(window.Notification){const native=Notification.requestPermission.bind(Notification);Notification.requestPermission=(...args)=>{window.__permissionRequests++;return native(...args);};}});
   await page.goto(url);await page.waitForFunction(()=>window.__kaizenQA?.state.auth&&window.__kaizenQA.state.tasks.length>0);
   await page.locator('[aria-label="Usuario de demostración"]').evaluate(node=>node.parentElement.style.display='none');
   await page.evaluate(()=>{
    const app=window.__kaizenQA;
    app.setState({activeId:'notify-qa',tasks:[{id:'notify-qa',title:'Enfoque de prueba local',list:'today',due:app.dkey(Date.now()),project:'',quadrant:'q2',tags:[],subtasks:[],done:false,seconds:0,pomos:0,estPomos:1,_owner_id:1,_shared_user_ids:[]}],log:{},pomo:{mode:'focus',remaining:1,running:false,accountedAt:null,endsAt:null,breakEndsAt:null,pushJobId:null,breakPushJobId:null}});
   });
   await page.locator('.app-header > .btn-primary').click();await page.waitForFunction(()=>window.__kaizenQA.state.alertSoundReady);
   const stats=await page.evaluate(async()=>{
    const app=window.__kaizenQA,c=app.alertAudio,analyser=c.createAnalyser();analyser.fftSize=1024;
    const gain=c.createGain.bind(c);c.createGain=()=>{const g=gain();g.connect(analyser);return g;};
    const data=new Float32Array(analyser.fftSize),started=performance.now();let first=null,last=null,maxRms=0;
    while(performance.now()-started<7200){
     analyser.getFloatTimeDomainData(data);const rms=Math.sqrt(data.reduce((sum,value)=>sum+value*value,0)/data.length);maxRms=Math.max(maxRms,rms);
     if(rms>0.002){first??=performance.now();last=performance.now();}
     await new Promise(resolve=>setTimeout(resolve,20));
    }
    return {first,last,span:first!==null&&last!==null?last-first:0,maxRms,permissionRequests:window.__permissionRequests,notice:app.state.timerNotice?.title,phase:app.state.pomo.mode,running:app.state.pomo.running,seconds:app.state.tasks[0].seconds};
   });
   assert.equal(stats.permissionRequests,0);assert.ok(stats.maxRms>0.01,engine+' renders an audible signal');assert.ok(stats.span>=5000,engine+' signal span >=5s: '+stats.span);
   assert.equal(stats.notice,'Pomodoro terminado');assert.equal(stats.phase,'break');assert.equal(stats.running,true);assert.equal(stats.seconds,1);
   assert.equal(await page.locator('.pomodoro-notice').isVisible(),true);assert.match(await page.locator('.today-focus-strip').innerText(),/Enfoque de prueba local/);
   await page.screenshot({path:'test-results/pomodoro-'+engine+'.png',fullPage:true});await page.getByRole('button',{name:'Entendido',exact:true}).click();
   let systemNotification='not available in this test engine';
   if(engine==='chromium'){
    await context.grantPermissions(['notifications'],{origin:url});
    const more=page.locator('.header-menus > details').last();await more.locator(':scope > summary').click();await page.getByRole('button',{name:'Probar aviso y sonido de 5 segundos',exact:true}).click();
    await page.waitForFunction(async()=>{const reg=await navigator.serviceWorker.ready;return (await reg.getNotifications()).some(n=>n.title==='Aviso de prueba de Kaizen');});
    assert.equal(await page.evaluate(()=>window.__permissionRequests),0);systemNotification='service-worker notification recorded with pre-granted permission';
   }
   await page.evaluate(()=>window.__kaizenQA.stopPomo());assert.equal(await page.evaluate(()=>window.__kaizenQA.state.pomo.running),false);
   console.log(JSON.stringify({engine,...stats,systemNotification,stopped:true}));await context.close();await browser.close();browser=null;
  }
 }finally{await browser?.close();server.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
