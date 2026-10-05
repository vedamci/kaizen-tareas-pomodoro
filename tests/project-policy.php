<?php
declare(strict_types=1);
require __DIR__ . '/../api/project-policy.php';
$count=0;
function check(bool $condition, string $label): void { global $count; if(!$condition)throw new RuntimeException($label);$count++;echo "PASS $label\n"; }
function rejected(callable $f, int $code): bool { try{$f();return false;}catch(DomainException $e){return $e->getCode()===$code;} }
$projects=[['id'=>'a','owner_id'=>1,'member_ids'=>[1,2]],['id'=>'b','owner_id'=>1,'member_ids'=>[1,3]],['id'=>'legacy','name'=>'Old']];
$task=['id'=>'t','project'=>'a','_owner_id'=>1];
check(projectTaskAccess($task,$projects,2,[]),'member sees project task');
check(!projectTaskAccess($task,$projects,3,[3]),'nonmember direct share does not bypass project');
check(!projectTaskAccess($task,$projects,4,[]),'outsider cannot read');
check(projectTaskAccess(['project'=>'','_owner_id'=>1],$projects,1,[]),'unassigned task stays personal');
check(!projectTaskAccess(['project'=>'','_owner_id'=>1],$projects,2,[]),'unassigned task hidden from teammate');
check(!projectTaskAccess(['project'=>'legacy','_owner_id'=>1],$projects,2,[]),'legacy project does not widen task visibility');
check(projectTaskAccess(['project'=>'legacy','_owner_id'=>1],$projects,2,[2]),'legacy delegation preserved');
projectTaskWrite(['project'=>'b'],$task,$projects,['id'=>1],false,[]);check(true,'owner moves between projects');
projectTaskWrite(['project'=>''],$task,$projects,['id'=>1],false,[]);check(true,'owner removes task from project');
projectTaskWrite(['project'=>'a'],$task,$projects,['id'=>2],false,[]);check(true,'member edits within project');
check(rejected(fn()=>projectTaskWrite(['project'=>'b'],$task,$projects,['id'=>2],false,[]),403),'collaborator cannot move task');
check(rejected(fn()=>projectTaskWrite(['project'=>'b'],$task,$projects,['id'=>2],true,[]),403),'admin still needs target membership');
check(rejected(fn()=>projectTaskWrite(['project'=>'a'],$task,$projects,['id'=>3],true,[3]),403),'admin cannot edit without project membership');
check(rejected(fn()=>projectTaskWrite(['project'=>'missing'],null,$projects,['id'=>1],false,[]),422),'new unknown project rejected');
projectTaskWrite(['project'=>'old-orphan'],['project'=>'old-orphan','_owner_id'=>1],$projects,['id'=>1],false,[]);check(true,'existing orphan association retained');
check(!projectManage($projects[0],['id'=>2],false),'member cannot change membership');
check(projectManage($projects[0],['id'=>1],false),'creator manages membership');
$schedule=['scheduledStart'=>'2026-10-05T15:00:00.000Z','scheduledEnd'=>'2026-10-05T17:00:00.000Z','scheduleTimeZone'=>'America/Mexico_City'];
validateTaskSchedule($schedule);check(true,'valid UTC schedule');
validateTaskSchedule([]);check(true,'undated tasks preserved');
check(rejected(fn()=>validateTaskSchedule(array_merge($schedule,['scheduledEnd'=>$schedule['scheduledStart']])),422),'equal times rejected');
check(rejected(fn()=>validateTaskSchedule(array_merge($schedule,['scheduledEnd'=>''])),422),'partial times rejected');
check(rejected(fn()=>validateTaskSchedule(array_merge($schedule,['scheduledStart'=>'2026-02-30T15:00:00.000Z'])),422),'invalid calendar rejected');
check(rejected(fn()=>validateTaskSchedule(array_merge($schedule,['scheduleTimeZone'=>'fake-zone'])),422),'invalid timezone rejected');
$persisted=json_decode(json_encode(['projects'=>$projects,'task'=>array_merge($task,$schedule)]),true);
check(projectTaskAccess($persisted['task'],$persisted['projects'],2,[]),'serialized membership access preserved');
validateTaskSchedule($persisted['task']);check(true,'serialized UTC schedule preserved');
$json=json_encode($task);validateTaskRevision($json,['_revision'=>hash('sha256',$json)]);check(true,'matching revision accepted');
check(rejected(fn()=>validateTaskRevision($json,['_revision'=>'old']),409),'concurrent edit rejected');
check(rejected(fn()=>validateTaskRevision($json,[]),409),'missing revision cannot overwrite an existing task');
echo "$count checks passed\n";
