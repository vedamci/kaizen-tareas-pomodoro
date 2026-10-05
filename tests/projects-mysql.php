<?php
declare(strict_types=1);
require __DIR__ . '/../api/projects.php';
// This suite accepts only a disposable database on loopback. Never load the
// application config.php or use real users, sessions, credentials or grants.
$dsn=getenv('KAIZEN_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127\.0\.0\.1;(?:port=\d+;)?dbname=kaizen_test_[a-z0-9_]+$/D',$dsn))throw new RuntimeException('Set a loopback kaizen_test_ database DSN.');
$pdo=new PDO($dsn,getenv('KAIZEN_TEST_DB_USER')?:'root',getenv('KAIZEN_TEST_DB_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($pdo->query('SHOW TABLES')->fetchColumn()!==false)throw new RuntimeException('The disposable test database must be empty.');
$count=0;
function verify(bool $ok,string $name):void {global $count;if(!$ok)throw new RuntimeException($name);$count++;echo "PASS $name\n";}
function fails(callable $f,int $code):bool {try{$f();return false;}catch(DomainException $e){return $e->getCode()===$code;}}
function readTask(PDO $pdo,array $u,string $id):array {foreach(loadWorkspaceTasks($pdo,1,$u,(int)$u['id']===1)['tasks'] as $task)if($task['id']===$id)return $task;throw new RuntimeException('Fixture task not visible');}
$u1=['id'=>1,'role'=>'user'];$u2=['id'=>2,'role'=>'user'];$u3=['id'=>3,'role'=>'user'];
try {
    $pdo->exec(file_get_contents(__DIR__.'/../api/schema.sql'));
    $pdo->exec("INSERT INTO users(id,email,name,password_hash) VALUES(1,'one@example.test','One','not-a-login'),(2,'two@example.test','Two','not-a-login'),(3,'three@example.test','Three','not-a-login'),(4,'outside@example.test','Outside','not-a-login')");
    $pdo->exec("INSERT INTO workspaces(id,name,created_by) VALUES(1,'Disposable fixture',1)");
    $pdo->exec("INSERT INTO workspace_members(workspace_id,user_id,role) VALUES(1,1,'admin'),(1,2,'member'),(1,3,'member')");
    $pdo->prepare('INSERT INTO workspace_data(workspace_id,projects_json,improvement_checklist_json) VALUES(?,?,?)')->execute([1,'[{"id":"legacy","name":"Old grouping"}]','[{"text":"keep"}]']);
    $a=saveWorkspaceProject($pdo,1,$u1,true,['name'=>'A','member_ids'=>[2]]);
    $b=saveWorkspaceProject($pdo,1,$u1,true,['name'=>'B','member_ids'=>[3]]);
    verify(count(workspaceProjects($pdo,1))===3,'creating projects preserves legacy definitions');
    verify($pdo->query('SELECT improvement_checklist_json FROM workspace_data WHERE workspace_id=1')->fetchColumn()==='[{"text":"keep"}]','project writes preserve checklist');
    $before=workspaceProjects($pdo,1);
    verify(fails(fn()=>saveWorkspaceProject($pdo,1,$u1,true,['name'=>'Bad','member_ids'=>[4]]),422),'non-team member rejected');
    verify(workspaceProjects($pdo,1)===$before,'invalid membership transaction rolled back');
    verify(fails(fn()=>saveWorkspaceProject($pdo,1,$u2,false,['project_id'=>$a['id'],'name'=>'Forged','member_ids'=>[2]]),403),'project member cannot replace membership');
    $private=['id'=>'private','title'=>'Personal','project'=>'','seconds'=>33,'pomos'=>1];
    $task=['id'=>'shared','title'=>'Shared','project'=>$a['id'],'seconds'=>60,'pomos'=>2,'scheduledStart'=>'2026-10-05T15:00:00.000Z','scheduledEnd'=>'2026-10-05T16:00:00.000Z','scheduleTimeZone'=>'America/Mexico_City'];
    syncWorkspaceTasks($pdo,1,$u1,true,[$private,$task]);
    $visible=loadWorkspaceTasks($pdo,1,$u2,false)['tasks'];verify(count($visible)===1&&$visible[0]['id']==='shared','member reads project task but not unassigned personal task');
    verify(loadWorkspaceTasks($pdo,1,$u3,false)['tasks']===[],'nonmember cannot read project task');
    verify(readTask($pdo,$u1,'shared')['scheduledStart']===$task['scheduledStart'],'UTC schedule persists in MySQL');
    $member=readTask($pdo,$u2,'shared');$member['notes']='Member edit';syncWorkspaceTasks($pdo,1,$u2,false,[$member]);
    verify(readTask($pdo,$u1,'shared')['notes']==='Member edit','project member edits task');
    $member=readTask($pdo,$u2,'shared');$member['project']=$b['id'];verify(fails(fn()=>syncWorkspaceTasks($pdo,1,$u2,false,[$member]),403),'collaborator move rejected');
    $first=readTask($pdo,$u1,'shared');$second=$first;$first['title']='First editor';syncWorkspaceTasks($pdo,1,$u1,true,[$first]);$second['title']='Stale editor';verify(fails(fn()=>syncWorkspaceTasks($pdo,1,$u1,true,[$second]),409),'stale revision rejected');
    verify(readTask($pdo,$u1,'shared')['title']==='First editor','stale edit did not overwrite data');
    $move=readTask($pdo,$u1,'shared');$move['project']=$b['id'];syncWorkspaceTasks($pdo,1,$u1,true,[$move]);
    verify(loadWorkspaceTasks($pdo,1,$u2,false)['tasks']===[],'old project member loses access after move');
    verify(readTask($pdo,$u3,'shared')['seconds']===60,'destination member receives task with real time preserved');
    $move=readTask($pdo,$u1,'shared');$move['project']='';syncWorkspaceTasks($pdo,1,$u1,true,[$move]);
    verify(loadWorkspaceTasks($pdo,1,$u3,false)['tasks']===[],'removing project restores personal visibility');
    $move=readTask($pdo,$u1,'shared');$move['project']=$a['id'];syncWorkspaceTasks($pdo,1,$u1,true,[$move]);
    saveWorkspaceProject($pdo,1,$u1,true,['project_id'=>$a['id'],'name'=>'A','member_ids'=>[]]);
    verify(loadWorkspaceTasks($pdo,1,$u2,false)['tasks']===[],'member revocation applies on next read');
    verify(fails(fn()=>syncWorkspaceTasks($pdo,1,$u2,false,[$member]),403),'revoked member cannot write with old browser data');
    $one=readTask($pdo,$u1,'private');$one['title']='Should roll back';$bad=readTask($pdo,$u1,'shared');$bad['project']='unknown';
    verify(fails(fn()=>syncWorkspaceTasks($pdo,1,$u1,true,[$one,$bad]),422),'invalid destination rejects entire batch');
    verify(readTask($pdo,$u1,'private')['title']==='Personal','earlier writes in failed batch roll back');
    $bad=readTask($pdo,$u1,'shared');$bad['scheduledEnd']=$bad['scheduledStart'];verify(fails(fn()=>syncWorkspaceTasks($pdo,1,$u1,true,[$bad]),422),'invalid schedule rejected by backend');
    $forged=readTask($pdo,$u1,'shared');$forged['_owner_id']=3;syncWorkspaceTasks($pdo,1,$u1,true,[$forged]);verify(readTask($pdo,$u1,'shared')['_owner_id']===1,'client cannot replace owner');
    echo "$count MySQL checks passed\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    // Only the empty, explicitly named test DB above is ever touched.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
