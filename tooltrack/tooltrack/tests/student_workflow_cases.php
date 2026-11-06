<?php
if(PHP_SAPI!=='cli'||!isset($db,$work))exit;
if(!preg_match('/^tooltrack_test_[a-f0-9]{12}$/',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('Student tests require an isolated database.');
$_SERVER['REQUEST_METHOD']='GET';
require_once dirname(__DIR__).'/api/student_import.php';
function studentFields(string $id,string $course,string $year,string $section):array{
    return ['full_name'=>'Test Student '.$section,'id_number'=>$id,'type'=>'Student','course'=>$course,'year_level'=>$year,'section_name'=>$section,'phone'=>'+639171234567'];
}
function studentCsv(array $rows,array $headers=['Student ID','Full Name','Year Level','Section','Course/Program','Phone Number']):array{
    global $work;
    $path=$work.'/students.csv';$f=fopen($path,'wb');fwrite($f,"\xEF\xBB\xBF");fputcsv($f,$headers);
    foreach($rows as $r)fputcsv($f,$r);fclose($f);return smi_read_csv($path);
}
$manual=[];
foreach([['20261001','FPST','Year 1','Rizal','1st Year'],['20261002','FPST','Second Year','Bonifacio','2nd Year'],['20261003','TVL','11th Grade','A','Grade 11'],['20261004','TVL','G12','B','Grade 12']] as [$sid,$course,$year,$section,$label]){
    $new=expectApi('Manual '.$course.' '.$year.' / '.$section,'borrowers.php','POST',studentFields($sid,$course,$year,$section));$manual[$sid]=(int)$new['id'];
    $saved=expectApi('Student details read from Directory','borrowers.php','GET',[],true,'Admin',['id'=>$new['id']]);
    check($saved['section_name']===$section&&$saved['year_level']===$label&&$saved['phone']==='09171234567','Manual student stores canonical year and unrestricted section');
}
$edit=studentFields('20261003','TVL','12','Rizal - Senior High South Campus');$edit['id']=$manual['20261003'];
expectApi('Edit accepts Grade 12 and long section','borrowers.php','PUT',$edit);
$edited=expectApi('Read edited student','borrowers.php','GET',[],true,'Admin',['id'=>$edit['id']]);
check($edited['year_level']==='Grade 12'&&$edited['section_name']===$edit['section_name'],'Edited section is not truncated to 20 characters');
$invalid=studentFields('20261111','FPST','3','Rizal');
$bad=api('borrowers.php','POST',$invalid);check(!$bad['success']&&str_contains($bad['message'],'Year 1 or Year 2'),'Manual invalid FPST year has clear error');
$opt=smi_options(json_encode(['course'=>'FPST','year_level'=>'1','section'=>'Rizal','name_format'=>'as_is']));
$parsed=smi_analyze($db,studentCsv([['20261111','Test Student Rizal','3','Rizal','FPST','+639171234567']]),$opt);
check(implode(' ',$parsed['rows'][0]['errors'])===$bad['message'],'Manual registration and CSV preview share identical academic validation');
$invalid=studentFields('20261003','TVL','1','A');$invalid['id']=$manual['20261003'];
expectApi('Edit rejects unsupported TVL grade','borrowers.php','PUT',$invalid,false);
check(row('borrowers',$manual['20261003'])['id_number']==='20261003','Rejected edit preserves original student');
expectApi('Manual duplicate Student ID blocked','borrowers.php','POST',studentFields('20261001','FPST','1','A'),false);
$race=concurrentApi('borrowers.php','POST',studentFields('20261009','FPST','2','B'));
check(count(array_filter($race,fn($r)=>$r['success']))===1,'Concurrent duplicate Student IDs create one student');
$valid=[['20262001','CSV Student One','1','Rizal','FPST','09171234567'],['20262002','CSV Student Two','2','Bonifacio','FPST',''],['20262003','CSV Student Three','Grade 11','A','TVL','+639171234567'],['20262004','CSV Student Four','12','B','TVL','9171234567']];
$table=studentCsv($valid);$before=(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();
$analysis=smi_analyze($db,$table,$opt);$preview=smi_preview_payload($analysis);
check(!$analysis['missing']&&$preview['counts']['new']===4&&$preview['counts']['invalid']===0,'Exact six-column CSV previews all FPST and TVL students as valid');
check((int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn()===$before,'CSV preview writes nothing');
$db->beginTransaction();try{$fresh=smi_analyze($db,smi_read_csv($work.'/students.csv'),$opt);$stats=smi_apply($db,$fresh['rows'],$opt);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}
check($stats['created']===4,'CSV commit creates every valid student');
foreach($valid as $row){
    $list=expectApi('Imported student appears in Borrowers Directory','borrowers.php','GET',[],true,'Admin',['type'=>'Student','search'=>$row[0]]);
    check(count($list)===1&&$list[0]['section_name']===$row[3]&&$list[0]['course']===$row[4],'Imported student has correct course and free-form section');
    check($list[0]['year_level']===studentYearLabel(studentYearNumber($row[2])),'CSV year/grade stored with the correct label');
}
$before=(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();$again=smi_analyze($db,studentCsv($valid),$opt);
$db->beginTransaction();$stats=smi_apply($db,$again['rows'],$opt);$db->commit();
check($stats['created']===0&&(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn()===$before,'Importing the same IDs twice creates no duplicates');
$conflict=smi_analyze($db,studentCsv([['20263001','Conflict One','1','Rizal','FPST',''],['20263001','Conflict Two','2','A','FPST','']]),$opt);
check($conflict['rows'][0]['state']==='conflict'&&$conflict['rows'][1]['state']==='conflict','Conflicting duplicate IDs within CSV are rejected clearly');
$identical=smi_analyze($db,studentCsv([['20263002','Identical Student','1','A','FPST',''],['20263002','Identical Student','1','A','FPST','']]),$opt);
$db->beginTransaction();$stats=smi_apply($db,$identical['rows'],$opt);$db->commit();check($stats['created']===1&&$stats['skipped_problem']===1,'Identical duplicate CSV rows import once');
$badRows=[['20264001','Invalid Year','3','Rizal','FPST',''],['20264002','Invalid Grade','2','A','TVL',''],['20264003','No Section','1','','FPST',''],['bad-id','Invalid ID','1','A','FPST',''],['20264004','Invalid Phone','11','B','TVL','hello']];
$invalidAnalysis=smi_analyze($db,studentCsv($badRows),$opt);$payload=smi_preview_payload($invalidAnalysis);
check($payload['counts']['invalid']===5,'CSV preview flags invalid years, grades, missing sections, IDs and phones');
foreach($invalidAnalysis['rows'] as $r)check(!empty($r['errors']),'Invalid CSV row exposes actionable error');
$before=(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();$db->beginTransaction();$stats=smi_apply($db,$invalidAnalysis['rows'],$opt);$db->commit();
check($stats['created']===0&&(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn()===$before,'CSV import does not insert invalid preview rows');
$missing=smi_analyze($db,studentCsv([['20264010','Missing columns']],['Student ID','Full Name']),$opt);
check(!$missing['missing']&&$missing['rows'][0]['state']==='new'&&$missing['rows'][0]['course']==='FPST'&&$missing['rows'][0]['section']==='Rizal','Legacy ID/name-only lists use selected class defaults');
$overwrite=smi_options(json_encode(['course'=>'FPST','year_level'=>'1','section'=>'Rizal','policy'=>'overwrite','overwrite_fields'=>['section','course'],'name_format'=>'as_is']));
$yearOnly=smi_analyze($db,studentCsv([['20262003','CSV Student Three','12','A','TVL','']]),$overwrite);
check($yearOnly['rows'][0]['fields']['section']==='change','Year-only change detected with unchanged section name');
$db->beginTransaction();$stats=smi_apply($db,$yearOnly['rows'],$overwrite);$db->commit();
$updated=expectApi('Read imported year-only update','borrowers.php','GET',[],true,'Admin',['type'=>'Student','search'=>'20262003']);
check($updated[0]['year_level']==='Grade 12'&&$updated[0]['section_name']==='A','CSV updates Grade 11 to Grade 12 without changing section');
$partialPolicy=smi_options(json_encode(['course'=>'FPST','year_level'=>'1','section'=>'Rizal','policy'=>'overwrite','overwrite_fields'=>['course'],'name_format'=>'as_is']));
$badMix=smi_analyze($db,studentCsv([['20262001','CSV Student One','11','Rizal','TVL','']]),$partialPolicy);
check($badMix['rows'][0]['state']==='invalid','Partial import policy cannot combine TVL with existing FPST year');
$legacy=row('borrowers',1);check($legacy['full_name']==='Test Borrower'&&$legacy['type']==='Student','Existing unrelated student is preserved');
$db->exec("CREATE TRIGGER student_enrollment_test_fail BEFORE INSERT ON borrower_enrollments FOR EACH ROW BEGIN IF NEW.section_name='Force enrollment failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test enrollment failure'; END IF; END");
try{expectApi('Enrollment failure safely rejects manual registration','borrowers.php','POST',studentFields('20265001','FPST','1','Force enrollment failure'),false);}
finally{$db->exec('DROP TRIGGER student_enrollment_test_fail');}
check((int)$db->query("SELECT COUNT(*) FROM borrowers WHERE id_number='20265001'")->fetchColumn()===0,'Manual registration rolls back borrower if enrollment cannot save');
invariant();check(true,'Student changes preserve inventory and transaction consistency');
// Exercise the browser's real multipart upload, including its default_course option.
copy(dirname(__DIR__).'/api/student_import.php',$work.'/student_import.php');
file_put_contents($work.'/import_router.php', <<<'ROUTER'
<?php
require __DIR__.'/bootstrap.php';
$_SESSION=['user_id'=>1,'user_role'=>'Admin','user_name'=>'Test Admin'];
require __DIR__.'/student_import.php';
smi_handle();
ROUTER
);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if(!$socket)throw new RuntimeException('Could not reserve a test HTTP port.');
$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-d','upload_tmp_dir='.$work,'-d','display_errors=0','-S',$address,'-t',$work,$work.'/import_router.php'],[0=>['pipe','r'],1=>['file',$work.'/http-out.log','a'],2=>['file',$work.'/http-error.log','a']],$serverPipes);
fclose($serverPipes[0]);
function uploadedCsvRequest(string $address,string $action,string $csv,array $options):array{
    $boundary='ToolTrackTest'.bin2hex(random_bytes(8));
    $body="--$boundary\r\nContent-Disposition: form-data; name=\"options\"\r\n\r\n".json_encode($options)."\r\n";
    $body.="--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"FPST_TVL_Student_Import_Test_30_Sections_Only.csv\"\r\nContent-Type: text/csv\r\n\r\n$csv\r\n--$boundary--\r\n";
    $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: multipart/form-data; boundary=$boundary\r\nX-Requested-With: FPSTToolTrack\r\n",'content'=>$body,'ignore_errors'=>true,'timeout'=>15]]);
    $raw=file_get_contents('http://'.$address.'/?action='.$action,false,$context);
    $response=json_decode($raw,true);
    if(!is_array($response))throw new RuntimeException('Upload endpoint returned non-JSON: '.$raw);
    return $response;
}
try{
    $ready=false;
    for($i=0;$i<100;$i++){$conn=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($conn){fclose($conn);$ready=true;break;}usleep(20000);}
    if(!$ready)throw new RuntimeException('Upload test server did not start.');
    $csv="Student ID,Full Name,Year Level,Section,Course/Program,Phone Number\r\n";
    for($i=1;$i<=30;$i++){$course=$i%2?'FPST':'TVL';$level=$course==='FPST'?($i%4===1?1:2):($i%4===2?11:12);$section=['Rizal','Bonifacio','A','B'][$i%4];$csv.=sprintf('9876%04d,Upload Student %d,%d,%s,%s,',$i,$i,$level,$section,$course)."\r\n";}
    $before=(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();
    foreach(['FPST','TVL'] as $default){
        $options=['policy'=>'overwrite','overwrite_fields'=>['section','course','phone'],'name_format'=>'as_is','reactivate'=>false,'mapping'=>[],'default_course'=>$default,'academic_year'=>'2026-2027'];
        $response=uploadedCsvRequest($address,'preview',$csv,$options);
        check($response['success']&&$response['data']['counts']['new']===30&&$response['data']['counts']['invalid']===0,'Real Check File upload previews 30 mixed students with '.$default.' default');
    }
    check((int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn()===$before,'Real HTTP Check File preview writes no records');
    $response=uploadedCsvRequest($address,'commit',$csv,$options);
    check($response['success']&&$response['data']['created']===30,'Real CSV upload imports all 30 FPST/TVL students');
    check((int)$db->query("SELECT COUNT(*) FROM borrowers WHERE id_number LIKE '9876%'")->fetchColumn()===30,'Uploaded students persist once in isolated database');
    $response=uploadedCsvRequest($address,'preview',$csv,array_merge($options,['default_course'=>'Invalid']));
    check(!$response['success']&&str_contains($response['message'],'FPST or TVL'),'Invalid upload options return clear JSON validation error instead of fatal 500');
}finally{proc_terminate($server);proc_close($server);}
