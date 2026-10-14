import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,mkdir,copyFile,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync,spawn} from 'node:child_process';

const repo=fileURLToPath(new URL('../',import.meta.url));
const endpoint='http://127.0.0.1:4181/api/mcp.php';
const tokens={read:'fixture-owner-read-00000001',write:'fixture-owner-write-0000001',member:'fixture-member-read-0000001',memberWrite:'fixture-member-write-000001',admin:'fixture-admin-read-00000001',outsider:'fixture-outsider-read-00001'};

test('MCP transport, scopes and existing Kaizen authorization', {timeout:90000},async()=>{
  const scratch=await mkdtemp(path.join(tmpdir(),'kaizen-mcp-'));
  let server,logs='';let id=0;
  async function rpc(token,method,params={},status=200,headers={}){
    const request={jsonrpc:'2.0',id:++id,method,params};
    const response=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json, text/event-stream','MCP-Protocol-Version':'2025-11-25',...(token?{Authorization:'Bearer '+token}:{}),...headers},body:JSON.stringify(request)});
    assert.equal(response.status,status,`${method}: ${await response.clone().text()}`);
    return status===401||status===403?null:response.json();
  }
  const call=(token,name,args,status=200)=>rpc(token,'tools/call',{name,arguments:args},status);
  const data=r=>r.result.structuredContent;
  try{
    await mkdir(path.join(scratch,'api'));
    for(const name of ['mcp.php','mcp-core.php','projects.php','project-policy.php']) await copyFile(path.join(repo,'api',name),path.join(scratch,'api',name));
    execFileSync('php',[path.join(repo,'tests/mcp-fixture.php'),scratch],{stdio:'pipe'});
    server=spawn('php',['-S','127.0.0.1:4181','-t',scratch],{stdio:['ignore','pipe','pipe']});
    server.stdout.on('data',d=>logs+=d);server.stderr.on('data',d=>logs+=d);
    let ready=false;for(let i=0;i<60;i++){try{await fetch(endpoint);ready=true;break;}catch{await new Promise(r=>setTimeout(r,100));}}
    assert.ok(ready,'fixture HTTP server starts');
    await rpc(null,'initialize',{},401);
    await rpc('invalid-token-000000000001','initialize',{},401);
    await rpc(tokens.read,'initialize',{},403,{Origin:'https://evil.example'});
    const init=await rpc(tokens.read,'initialize',{});
    assert.equal(init.result.protocolVersion,'2025-11-25');
    const readTools=(await rpc(tokens.read,'tools/list')).result.tools.map(t=>t.name);
    assert.ok(readTools.includes('day_agenda')&&!readTools.includes('create_task'));
    assert.ok((await rpc(tokens.write,'tools/list')).result.tools.some(t=>t.name==='create_task'));
    assert.equal(data(await call(tokens.read,'list_workspaces',{})).workspaces.length,1);
    await call(tokens.outsider,'list_tasks',{workspace_id:1},403);
    await call(tokens.member,'list_tasks',{workspace_id:1,scope:'team'},403);
    await call(tokens.read,'create_task',{workspace_id:1,client_id:'c1',title:'No'},403);
    const created=data(await call(tokens.write,'create_task',{workspace_id:1,client_id:'c1',title:'A'})).task;
    assert.equal(created.title,'A');
    assert.equal(data(await call(tokens.write,'create_task',{workspace_id:1,client_id:'c1',title:'A'})).task.id,created.id);
    const denied=await call(tokens.write,'create_task',{workspace_id:1,client_id:'c1',title:'Different'},409);
    assert.equal(denied.result.isError,true);
    await call(tokens.member,'get_task',{workspace_id:1,task_id:created.id},403);
    const updated=data(await call(tokens.write,'set_task_done',{workspace_id:1,task_id:created.id,revision:created._revision,done:true})).task;
    assert.equal(updated.done,true);
    assert.equal(data(await call(tokens.write,'set_task_done',{workspace_id:1,task_id:created.id,revision:created._revision,done:true})).task.done,true);
    const block=data(await call(tokens.write,'set_work_block',{workspace_id:1,task_id:created.id,revision:updated._revision,block_id:'b1',start:'2026-10-14T15:00:00.000Z',end:'2026-10-14T16:00:00.000Z',time_zone:'America/Mexico_City'})).task;
    assert.equal(block.workBlocks.length,1);
    assert.equal(data(await call(tokens.read,'day_agenda',{workspace_id:1,date:'2026-10-14'})).blocks[0].task_id,created.id);
    assert.equal(data(await call(tokens.admin,'list_tasks',{workspace_id:1,scope:'team'})).tasks.length,1);
    const project=data(await call(tokens.write,'save_project',{workspace_id:1,client_id:'p1',name:'Proyecto',member_ids:[2]})).project;
    assert.deepEqual(project.member_ids,[1,2]);
    assert.equal(data(await call(tokens.member,'list_projects',{workspace_id:1})).projects[0].id,project.id);
    await call(tokens.memberWrite,'save_project',{workspace_id:1,project_id:project.id,name:'Hijack',member_ids:[2]},403);
  }finally{
    if(server){server.kill();await new Promise(resolve=>server.once('exit',resolve));}
    await rm(scratch,{recursive:true,force:true});
    if(logs.includes('Fatal error')) console.error(logs);
  }
});
