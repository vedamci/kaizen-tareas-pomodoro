import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const root = fileURLToPath(new URL('../', import.meta.url));
const port = Number(process.env.KAIZEN_PREVIEW_PORT || 4178);
const allowed = new Set(['support.js','planning.js','preview/mock-api.js','kaizen-icon.svg','manifest.webmanifest','_ds/modernist-454c18dc-45af-4299-9be2-71360d2b9a90/styles.css','_ds/modernist-454c18dc-45af-4299-9be2-71360d2b9a90/_ds_bundle.js']);
http.createServer(async(req,res)=>{
  try{
    const pathname=new URL(req.url,'http://127.0.0.1').pathname;
    let content,type;
    if(pathname==='/'){
      content=(await readFile(path.join(root,'Gestor de Tareas.dc.html'),'utf8')).replace('<script src="./support.js">','<script src="./preview/mock-api.js"></script><script src="./support.js">').replace("KEY = 'tareas-modernist-v1'","KEY = 'kaizen-local-preview-ui-v1'");
      type='text/html';
    }else{
      const file=pathname.slice(1);if(!allowed.has(file)){res.writeHead(404);res.end('Not available in the local preview');return;}
      content=await readFile(path.join(root,file));type=file.endsWith('.js')?'application/javascript':file.endsWith('.css')?'text/css':file.endsWith('.svg')?'image/svg+xml':'application/json';
    }
    res.writeHead(200,{'Content-Type':type+'; charset=utf-8','Cache-Control':'no-store'});res.end(content);
  }catch(e){res.writeHead(500);res.end('Local preview unavailable');}
}).listen(port,'127.0.0.1',()=>console.log(`Vista previa local: http://127.0.0.1:${port}/`));
