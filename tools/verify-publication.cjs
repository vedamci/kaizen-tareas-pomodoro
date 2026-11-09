// Read-only publication check: no login and no production writes.
const assert=require('node:assert/strict'),fs=require('node:fs/promises');
const base='https://vedamci.com.mx/tareas-pomodoro/';
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
 const expectedHtml=await fs.readFile('Gestor de Tareas.dc.html','utf8'),expectedPlanning=await fs.readFile('planning.js','utf8'),expectedWorker=await fs.readFile('sw.js','utf8');
 let lastError;
 for(let attempt=1;attempt<=18;attempt++){
  try{
   const query='?verify='+encodeURIComponent(process.env.GITHUB_SHA||Date.now())+'-'+attempt;
   const [page,planning,worker]=await Promise.all([fetch(base+query,{signal:AbortSignal.timeout(10000),headers:{'Cache-Control':'no-cache'}}),fetch(base+'planning.js'+query,{signal:AbortSignal.timeout(10000),headers:{'Cache-Control':'no-cache'}}),fetch(base+'sw.js'+query,{signal:AbortSignal.timeout(10000),headers:{'Cache-Control':'no-cache'}})]);
   assert.equal(page.status,200);assert.equal(planning.status,200);assert.equal(worker.status,200);
   assert.equal((await page.text()).trim(),expectedHtml.trim(),'Published HTML differs from the merged source');
   assert.equal((await planning.text()).trim(),expectedPlanning.trim(),'Published planning.js differs from the merged source');
   assert.equal((await worker.text()).trim(),expectedWorker.trim(),'Published service worker differs from merged source');
   console.log(JSON.stringify({worker:'matches merged source',url:base,commit:process.env.GITHUB_SHA,html:'matches merged source',planning:'matches merged source',attempt}));return;
  }catch(error){lastError=error;console.log('Publication not verified yet, attempt '+attempt+': '+error.message.split('\n')[0]);}
  if(attempt<18)await wait(5000);
 }
 throw lastError;
})().catch(error=>{console.error(error);process.exitCode=1;});
