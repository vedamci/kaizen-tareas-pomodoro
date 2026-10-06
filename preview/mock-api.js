// Disposable demonstration data. This is loaded only by the preview server.
(function () {
  const KEY = 'kaizen-local-preview-api-v1';
  const members = [{ id:1,name:'Ana (demo)',email:'ana@example.test',workspace_role:'admin' },{id:2,name:'Luis (demo)',email:'luis@example.test',workspace_role:'member'},{id:3,name:'Mar (demo)',email:'mar@example.test',workspace_role:'member'}];
  let data;
  try { data = JSON.parse(localStorage.getItem(KEY)); } catch (_) {}
  const task = (id,title,project,owner,start='',end='') => ({id,title,project,_owner_id:owner,scheduledStart:start,scheduledEnd:end,scheduleTimeZone:'UTC',list:'inbox',due:'',quadrant:'q2',tags:[],subtasks:[],sharedWith:[],estPomos:1,pomos:0,seconds:900,done:false,createdAt:Date.now()});
  data ||= { projects:[{id:'demo-a',name:'Lanzamiento (demo)',owner_id:1,member_ids:[1,2]},{id:'demo-b',name:'Contenido (demo)',owner_id:1,member_ids:[1,3]}],tasks:[task('demo-t1','Preparar propuesta (demo)','demo-a',1,'2026-10-05T15:00:00.000Z','2026-10-05T17:00:00.000Z'),task('demo-t2','Revisar materiales (demo)','demo-a',2,'2026-10-05T16:00:00.000Z','2026-10-05T18:30:00.000Z'),task('demo-t3','Ideas por clasificar (demo)','',1),task('demo-t4','Definir calendario (demo)','demo-b',1)] };
  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, options = {}) => {
    const url = new URL(String(input), location.href);
    if (!url.pathname.endsWith('/api/index.php')) return nativeFetch(input, options);
    // No request to any real API is made by this mock.
    const payload = JSON.parse(options.body || '{}'); const action = url.searchParams.get('action');
    const id = Number(localStorage.getItem('kaizen-preview-user') || 1); const user = { ...members.find(m=>m.id===id),role:id===1?'admin':'user' };
    const projectMember = p => !p?.member_ids || p.member_ids.includes(id);
    const access = t => t.project && data.projects.find(p=>p.id===t.project)?.member_ids ? projectMember(data.projects.find(p=>p.id===t.project)) : t._owner_id===id || (t.sharedWith||[]).includes(id);
    let result = {ok:true};
    if(action==='me') result={ok:true,user,workspaces:[{id:1,name:'Equipo de demostración',member_role:id===1?'admin':'member'}]};
    else if(action==='tasks'||action==='team_tasks'||action==='task_get') {
      const scope=action==='team_tasks'?'team':(payload.scope||'personal');
      const personal=t=>t._owner_id===id||(t.sharedWith||[]).includes(id);
      const decorate=t=>({...t,_project_member:!!t.project&&projectMember(data.projects.find(p=>p.id===t.project)),_can_move:scope!=='team'&&(t._owner_id===id||id===1),_shared_user_ids:t.sharedWith||[],_dashboard_visible:access(t)&&personal(t),_team_read_only:scope==='team'});
      const readable=t=>scope==='team'||(access(t)&&(scope==='projects'||personal(t)));
      if(!['personal','projects','team'].includes(scope)||(action==='tasks'&&scope==='team'))result={ok:false,error:'Vista no válida; usa la consulta de equipo.'};
      else if(scope==='team'&&id!==1)result={ok:false,error:'Solo un administrador del espacio puede consultar el equipo.'};
      else if(action==='task_get'){const t=data.tasks.find(t=>t.id===payload.task_id);result=t&&readable(t)?{ok:true,task:decorate(t)}:{ok:false,error:'Sin acceso a esta tarea.'};}
      else result={ok:true,tasks:data.tasks.filter(readable).map(decorate),projects:data.projects.filter(p=>scope==='team'||projectMember(p)||id===1).map(p=>({...p,_legacy:!p.member_ids,_can_manage:p.owner_id===id||id===1,_can_assign:projectMember(p)}))};
    }
    else if(action==='workspace_members')result={ok:true,members};
    else if(action==='suggestions')result={ok:true,suggestions:[]};
    else if(action==='checklist')result={ok:true,items:[]};
    else if(action==='tasks_sync'){
      const next=[];
      for(const t of payload.tasks||[]){const old=data.tasks.find(x=>x.id===t.id),p=data.projects.find(x=>x.id===t.project);
        if((old&&!access(old)) || (p&&!projectMember(p)) || (old&&old.project!==t.project&&old._owner_id!==id&&id!==1)){result={ok:false,error:'No tienes acceso a la tarea o proyecto.'};break;}
        if((t.scheduledStart||t.scheduledEnd)&&!(Date.parse(t.scheduledEnd)>Date.parse(t.scheduledStart))){result={ok:false,error:'El fin debe ser posterior al inicio.'};break;}
        next.push({...t,_owner_id:old?old._owner_id:id});
      }
      if(result.ok)for(const t of next){const i=data.tasks.findIndex(x=>x.id===t.id);if(i<0)data.tasks.push(t);else data.tasks[i]=t;}
    }else if(action==='project_save'){
      const p=data.projects.find(p=>p.id===payload.project_id);
      if(p&&p.owner_id!==id&&id!==1)result={ok:false,error:'Solo el creador puede cambiar los miembros.'};
      else{const project={id:p?.id||'demo-'+Date.now(),name:payload.name,owner_id:p?.owner_id||id,member_ids:[...new Set([p?.owner_id||id,...payload.member_ids])]};if(p)Object.assign(p,project);else data.projects.push(project);result={ok:true,project:{...project,_can_manage:true,_can_assign:projectMember(project)}};}
    }else if(action==='task_share'){
      await new Promise(resolve=>setTimeout(resolve,1500));
      const scenario=document.querySelector('[aria-label="Respuesta de delegación"]')?.value;
      if(scenario==='network')throw new Error('Conexión interrumpida (prueba local).');
      if(scenario==='reject')return new Response(JSON.stringify({ok:false,error:'Los miembros del proyecto cambiaron (prueba local).'}),{status:422,headers:{'Content-Type':'application/json'}});
const t=data.tasks.find(t=>t.id===payload.task_id);if(t&&access(t)){t.sharedWith=payload.user_ids;t._delegated_at=t.sharedWith.length?new Date().toISOString():null;result={ok:true,shared_user_ids:t.sharedWith,delegated_at:t._delegated_at};}else result={ok:false,error:'Sin acceso.'};}
    localStorage.setItem(KEY,JSON.stringify(data));
    return new Response(JSON.stringify(result),{status:result.ok?200:403,headers:{'Content-Type':'application/json'}});
  };
  document.addEventListener('DOMContentLoaded',()=>{
    const banner=document.createElement('div');banner.style='position:fixed;left:0;bottom:0;right:0;z-index:200;padding:10px 20px;background:#ffe8b2;color:#201e1d;font:13px sans-serif;';banner.textContent='Vista previa local · datos ficticios · no modifica la app publicada. Ver como: ';
    const select=document.createElement('select'); select.setAttribute('aria-label','Usuario de demostración');
    for(const m of members){const opt=document.createElement('option');opt.value=m.id;opt.textContent=m.name;select.append(opt);}select.value=localStorage.getItem('kaizen-preview-user')||'1';
    select.onchange=()=>{localStorage.setItem('kaizen-preview-user',select.value);localStorage.removeItem('kaizen-local-preview-ui-v1');location.reload();};banner.append(select);
    const scenario=document.createElement('select');scenario.setAttribute('aria-label','Respuesta de delegación');scenario.style.marginLeft='12px';
    for(const [value,label] of [['normal','Delegación: normal'],['reject','Delegación: rechazo'],['network','Delegación: sin conexión']]){const opt=document.createElement('option');opt.value=value;opt.textContent=label;scenario.append(opt);}banner.append(scenario);document.body.prepend(banner);
  });
})();
