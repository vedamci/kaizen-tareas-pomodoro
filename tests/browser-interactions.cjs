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
 await locator.evaluate(node=>node.scrollIntoView({block:'center',inline:'nearest'}));
 // Keep the task clear of the timeline's sticky name column on narrow screens.
 await locator.evaluate(node=>{
  const scroller=node.closest('.timeline-scroll');
  if(scroller){const sheet=node.closest('.timeline-sheet'),labelWidth=parseFloat(getComputedStyle(sheet).getPropertyValue('--task-width'));scroller.scrollLeft+=node.getBoundingClientRect().left-(scroller.getBoundingClientRect().left+labelWidth+12);}
 });
 const point=await locator.evaluate((node,{dx,dy})=>{
  const box=node.getBoundingClientRect(),scroller=node.closest('.timeline-scroll,.hourly-scroll')?.getBoundingClientRect();
  const left=Math.max(box.left,scroller?.left||0,0)+8,right=Math.min(box.right,scroller?.right||innerWidth,innerWidth)-4;
  const top=Math.max(box.top,scroller?.top||0,0)+8,bottom=Math.min(box.bottom,scroller?.bottom||innerHeight,innerHeight)-4;
  for(let y=top;y<bottom;y+=8)for(let x=left;x<right;x+=8)if(x+dx>4&&x+dx<innerWidth-4&&y+dy>4&&y+dy<innerHeight-4&&node.contains(document.elementFromPoint(x,y)))return {x,y};
  return null;
 },{dx,dy});
 if(!point){
  console.log(JSON.stringify(await locator.evaluate(node=>{const rect=node.getBoundingClientRect(),s=node.closest('.timeline-scroll,.hourly-scroll');return {missingTarget:true,rect:rect.toJSON(),scroll:s?.getBoundingClientRect().toJSON(),scrollTop:s?.scrollTop,scrollLeft:s?.scrollLeft,viewport:{width:innerWidth,height:innerHeight},samples:Array.from({length:5},(_,i)=>{const x=Math.max(1,Math.min(innerWidth-1,rect.left+rect.width*(i+1)/6)),y=Math.max(1,Math.min(innerHeight-1,rect.top+20));return{x,y,hit:document.elementFromPoint(x,y)?.outerHTML.slice(0,300)}})}})));
  await page.screenshot({path:path.join('test-results','drag-failure-'+page.viewportSize().width+'.png'),fullPage:true});
 }
 assert.ok(point,'The drag must start on a visible task surface and end inside the viewport');
 const {x,y}=point;
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
   // The preview-only account toolbar is absent in production and otherwise covers tasks on small screens.
   await page.locator('[aria-label="Usuario de demostración"]').evaluate(node=>node.parentElement.style.display='none');
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

   await page.evaluate(()=>window.__kaizenQA.renderVals().navItems.find(n=>n.id==='meetings').onClick());
   await page.getByRole('heading',{name:'Tareas creadas en junta',exact:true}).waitFor();
   assert.equal(await page.getByText('No se importan automáticamente de juntas, chats ni Slack.',{exact:false}).count(),1);
   await page.locator('.quick-add input').fill('Acuerdo de junta de prueba');
   await page.getByRole('button',{name:'Agregar',exact:true}).click();
   await page.locator('#task-origin').waitFor();
   assert.equal(await page.locator('#task-origin').inputValue(),'meeting');
   await page.locator('#task-meeting-title').fill('Coordinación demo');
   await page.locator('#task-meeting-date').fill('2026-11-10');
   const delegationDetail=page.getByRole('region',{name:'Delegada a',exact:true});
   assert.match(await delegationDetail.innerText(),/Sin delegar/);
   await delegationDetail.getByRole('button',{name:'Delegar tarea',exact:true}).click();
   const chooser=page.getByRole('dialog',{name:'Delegar tarea',exact:true});
   await chooser.getByRole('checkbox').first().check();
   await chooser.getByRole('button',{name:'Cancelar',exact:true}).click();
   assert.match(await delegationDetail.innerText(),/Sin delegar/);
   await delegationDetail.getByRole('button',{name:'Delegar tarea',exact:true}).click();
   assert.equal(await chooser.getByRole('checkbox').first().isChecked(),false);
   await chooser.getByRole('checkbox').first().check();
   await page.locator('[aria-label="Respuesta de delegación"]').selectOption('reject');
   await chooser.getByRole('button',{name:'Guardar delegación',exact:true}).click();
   await chooser.getByRole('alert').waitFor();
   assert.match(await delegationDetail.innerText(),/Sin delegar/);
   assert.equal(await chooser.getByRole('checkbox').first().isChecked(),true);
   await page.locator('[aria-label="Respuesta de delegación"]').selectOption('normal');
   await chooser.getByRole('button',{name:'Guardar delegación',exact:true}).click();
   await chooser.waitFor({state:'hidden'});
   assert.match(await delegationDetail.innerText(),/Delegada a Luis/);
   assert.equal(await delegationDetail.getByRole('button',{name:'Cambiar delegación',exact:true}).count(),1);
   await page.getByRole('button',{name:'Cerrar',exact:true}).click();
   const meetingRow=page.locator('.task-row').filter({hasText:'Acuerdo de junta de prueba'});
   await meetingRow.getByRole('button',{name:'Mover a Hoy',exact:true}).click();
   assert.equal(await meetingRow.count(),1,'moving to Today preserves the meeting view');
   await page.locator('#meeting-search').fill('Coordinación demo');
   assert.equal(await page.locator('.task-row').count(),1);
   assert.match(await meetingRow.innerText(),/Delegada a Luis/);
   await page.locator('#meeting-search').fill('Luis');
   assert.equal(await page.locator('.task-row').count(),1);
   await page.locator('#meeting-delegate').selectOption('__unassigned__');
   assert.equal(await page.locator('.task-row').count(),0);
   await page.locator('#meeting-delegate').selectOption('2');
   assert.equal(await page.locator('.task-row').count(),1);
   await page.evaluate(async()=>{await window.__kaizenQA.refreshTasks();});
   const saved=await page.evaluate(()=>window.__kaizenQA.state.tasks.find(t=>t.title==='Acuerdo de junta de prueba'));
   assert.deepEqual(saved._shared_user_ids,[2]);assert.equal(saved.taskOrigin,'meeting');assert.equal(saved.meetingTitle,'Coordinación demo');assert.equal(saved.meetingDate,'2026-11-10');assert.equal(saved.list,'today');
   await page.locator('#meeting-search').fill('');
   assert.equal(await page.locator('.meeting-help').evaluate(node=>node.getBoundingClientRect().right<=innerWidth),true);
   await page.screenshot({path:path.join('test-results','kaizen-meetings-'+width+'.png'),fullPage:true});
   console.log(JSON.stringify({viewport:width,meetingCreate:'pass',meetingDetails:'pass',meetingSearch:'pass',meetingMoveToday:'pass',meetingReload:'pass',delegationCancel:'pass',delegationRetry:'pass',delegationDetail:'pass',delegateFilter:'pass',delegateReload:'pass'}));
   await context.close();
  }
 }finally{await browser?.close();server.kill();}
})().catch(error=>{console.error(error);process.exitCode=1;});
