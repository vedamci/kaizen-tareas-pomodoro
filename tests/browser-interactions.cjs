// Browser QA uses only the local preview and disposable demo data.
const assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const fs=require('node:fs/promises');
const path=require('node:path');
const {chromium}=require('playwright');
const port=4189,url='http://127.0.0.1:'+port;
const server=spawn(process.execPath,['tools/preview-server.mjs'],{env:{...process.env,KAIZEN_PREVIEW_PORT:String(port)},stdio:'inherit'});
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function ready(){for(let i=0;i<40;i++){try{if((await fetch(url)).ok)return;}catch{}await pause(100);}throw Error('Preview did not start');}
async function drag(page,locator,dx,dy,touch){
 await locator.scrollIntoViewIfNeeded();
 // Keep the task clear of the timeline's sticky name column on narrow screens.
 await locator.evaluate(node=>{
  const scroller=node.closest('.timeline-scroll');
  if(scroller){const sheet=node.closest('.timeline-sheet'),labelWidth=parseFloat(getComputedStyle(sheet).getPropertyValue('--task-width'));scroller.scrollLeft+=node.getBoundingClientRect().left-(scroller.getBoundingClientRect().left+labelWidth+12);}
 });
 const box=await locator.boundingBox();assert.ok(box);
 const x=box.x+Math.min(box.width/2,12),y=box.y+Math.min(box.height/2,24);
 assert.equal(await locator.evaluate((node,{x,y})=>node.contains(document.elementFromPoint(x,y)),{x,y}),true,'The drag must start on the visible task, clear of sticky labels');
 if(touch){
  const client=await page.context().newCDPSession(page);
  await client.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});
  for(let n=1;n<=6;n++)await client.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x:x+dx*n/6,y:y+dy*n/6}]});
  await client.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await client.detach();
 }else{
  await page.mouse.move(x,y);await page.mouse.down();await page.mouse.move(x+dx,y+dy,{steps:6});await page.mouse.up();
 }
}
(async()=>{
 let browser;
 try{
  await ready();await fs.mkdir('test-results',{recursive:true});browser=await chromium.launch();
  for(const width of [1280,375,320]){
   const touch=width<600,context=await browser.newContext({viewport:{width,height:touch?740:900},isMobile:touch,hasTouch:touch,timezoneId:'UTC'});
   // Expose the component only in this intercepted, local test response.
   await context.route(url+'/',async route=>{
    const response=await route.fetch(),body=(await response.text()).replace('class Component extends DCLogic {','class Component extends DCLogic { constructor(...args){super(...args);window.__kaizenQA=this;}');
    await route.fulfill({response,body});
   });
   const page=await context.newPage();page.setDefaultTimeout(15000);
   page.on('pageerror',error=>console.log('Browser error: '+error.message));
   await page.goto(url);await page.waitForFunction(()=>window.__kaizenQA?.state.auth&&window.__kaizenQA.state.tasks.length>0);
   const menu=page.locator('.header-menus > details').first();await menu.locator(':scope > summary').click();assert.equal(await menu.evaluate(node=>node.open),true);
   if(touch)await page.locator('.app-brand').tap();else await page.locator('.app-brand').click();
   assert.equal(await menu.evaluate(node=>node.open),false);
   const key=await page.evaluate(()=>window.__kaizenQA.dkey(Date.now()));
   await page.evaluate(key=>{
    const task={id:'browser-qa',title:'Prueba local de horario',list:'inbox',due:'',project:'',quadrant:'q2',tags:[],subtasks:[],done:false,seconds:123,pomos:0,estPomos:1,_owner_id:1,_shared_user_ids:[],scheduledStart:key+'T09:00:00.000Z',scheduledEnd:key+'T10:00:00.000Z',scheduleTimeZone:'UTC'};
    window.__kaizenQA.setState({view:'inbox',tasks:[task],selId:null,mobileNavOpen:false});
   },key);
   const today=page.getByRole('button',{name:'Mover a Hoy',exact:true});await today.waitFor();assert.equal(await today.count(),1);
   if(touch)await today.tap();else await today.click();
   await page.waitForFunction(()=>window.__kaizenQA.state.tasks[0].list==='today');
   assert.equal(await page.evaluate(()=>window.__kaizenQA.state.tasks[0].seconds),123);
   await page.evaluate(key=>window.__kaizenQA.setState({view:'calendar',dailyDate:key,selId:null}),key);
   await page.getByRole('button',{name:'Crear tarea de 11:00 a 12:00',exact:true}).click();
   const start=await page.locator('#slot-task-start').inputValue(),end=await page.locator('#slot-task-end').inputValue();assert.equal(Date.parse(end)-Date.parse(start),3600000);
   await page.getByRole('button',{name:'Cancelar',exact:true}).click();
   await page.evaluate(key=>window.__kaizenQA.setState({view:'timeline',timelineMode:'day',timelineAnchor:Date.parse(key+'T12:00:00Z'),selId:null}),key);
   const bar=page.locator('.timeline-bar');await bar.waitFor();
   const laneWidth=await page.locator('.timeline-lane').evaluate(node=>node.getBoundingClientRect().width);
   await drag(page,bar,laneWidth/24,0,touch);
   console.log(JSON.stringify({viewport:width,afterTimeline:await page.evaluate(()=>({start:window.__kaizenQA.state.tasks[0].scheduledStart,end:window.__kaizenQA.state.tasks[0].scheduledEnd}))}));
   await page.waitForFunction(key=>window.__kaizenQA.state.tasks[0].scheduledStart===key+'T10:00:00.000Z',key);
   assert.equal(await page.evaluate(()=>window.__kaizenQA.state.tasks[0].scheduledEnd),key+'T11:00:00.000Z');
   await page.evaluate(key=>window.__kaizenQA.setState({view:'calendar',dailyDate:key,selId:null}),key);
   await drag(page,page.locator('.hourly-block-open'),0,60,touch);
   await page.waitForFunction(key=>window.__kaizenQA.state.tasks[0].scheduledStart===key+'T11:00:00.000Z',key);
   assert.equal(await page.evaluate(()=>window.__kaizenQA.state.tasks[0].scheduledEnd),key+'T12:00:00.000Z');
   assert.equal(await page.evaluate(()=>window.__kaizenQA.state.tasks[0].seconds),123);
   await page.waitForFunction(()=>!window.__kaizenQA.state.syncError);
   await page.screenshot({path:path.join('test-results','kaizen-'+width+'.png'),fullPage:true});
   console.log(JSON.stringify({viewport:width,input:touch?'touch':'mouse',menus:'pass',moveToday:'pass',oneHour:'pass',timelineMove:'09:00 -> 10:00',calendarMove:'10:00 -> 11:00',durationMinutes:60,recordedSeconds:123}));
   await context.close();
  }
 }finally{await browser?.close();server.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
