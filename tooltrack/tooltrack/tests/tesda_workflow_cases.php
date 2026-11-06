<?php
if(PHP_SAPI!=='cli'||!isset($db,$work))exit;
$_SERVER['REQUEST_METHOD']='GET';
require_once dirname(__DIR__).'/api/student_import.php';
$manual=expectApi('Staff registers TESDA without academic year or section','borrowers.php','POST',['full_name'=>'Manual TESDA Trainee','id_number'=>'000122','type'=>'Student','course'=>' tesda ','section_name'=>'','year_level'=>''],true,'Staff');
$manualId=(int)$manual['id'];
$read=expectApi('Reopen TESDA in Edit Borrower','borrowers.php','GET',[],true,'Admin',['id'=>$manualId]);
check($read['type']==='Student'&&$read['course']==='TESDA'&&$read['id_number']==='000122'&&empty($read['section_name'])&&empty($read['year_level']),'Manual TESDA program and text ID persist');
expectApi('Edit TESDA without FPST/TVL fields','borrowers.php','PUT',['id'=>$manualId,'full_name'=>'Manual TESDA Trainee','id_number'=>'000122','type'=>'Student','course'=>'TESDA','section_name'=>'']);
$batch=expectApi('Add optional TESDA batch','borrowers.php','PUT',['id'=>$manualId,'full_name'=>'Manual TESDA Trainee','id_number'=>'000122','type'=>'Student','course'=>'TESDA','section_name'=>'Batch 2026-B']);
$read=expectApi('Read optional batch','borrowers.php','GET',[],true,'Admin',['id'=>$manualId]);check($read['section_name']==='Batch 2026-B'&&empty($read['year_level']),'Batch displays without inventing a school year');
expectApi('Staff cannot bypass existing manual edit permission','borrowers.php','PUT',['id'=>$manualId],false,'Staff');

copy(dirname(__DIR__).'/api/student_import.php',$work.'/student_import.php');
file_put_contents($work.'/tesda-router.php', <<<'PHP'
<?php
ini_set('session.save_path',__DIR__);
require __DIR__.'/bootstrap.php';
$_SESSION=['user_id'=>2,'user_role'=>'Staff','user_name'=>'Test Staff'];
require __DIR__.'/student_import.php';smi_handle();
PHP);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-d','upload_tmp_dir='.$work,'-d','display_errors=0','-S',$address,$work.'/tesda-router.php'],[0=>['pipe','r'],1=>['file',$work.'/tesda-http-out.log','a'],2=>['file',$work.'/tesda-http-error.log','a']],$pipes);fclose($pipes[0]);
$upload=function(string $action,string $csv,array $options=[])use($address):array{
 $boundary='TESDATest'.bin2hex(random_bytes(8));
 $content="--$boundary\r\nContent-Disposition: form-data; name=\"options\"\r\n\r\n".json_encode($options)."\r\n--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"mixed.csv\"\r\nContent-Type: text/csv\r\n\r\n$csv\r\n--$boundary--\r\n";
 $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: multipart/form-data; boundary=$boundary\r\nX-Requested-With: FPSTToolTrack\r\n",'content'=>$content,'ignore_errors'=>true,'timeout'=>20]]);
 $raw=file_get_contents('http://'.$address.'/?action='.$action,false,$context);$result=json_decode($raw,true);if(!is_array($result))throw new RuntimeException('Non-JSON import response: '.$raw);return $result;
};
try{
 for($i=0;$i<50;$i++){$s=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($s){fclose($s);break;}usleep(20000);}
 $csv="Student ID,Full Name,Year Level,Section,Course / Strand,Phone Number\r\n000123,Imported TESDA Trainee,,, tesda ,\r\n20268001,Legacy FPST Student,1,Rizal,FPST,\r\n20268002,Legacy TVL Student,11,A,TVL,\r\n";
 $before=(int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn();
 $preview=$upload('preview',$csv);check($preview['success']&&$preview['data']['counts']['new']===3&&$preview['data']['counts']['invalid']===0,'Staff multipart upload previews mixed legacy header with TESDA');
 check((int)$db->query('SELECT COUNT(*) FROM borrowers')->fetchColumn()===$before,'Mixed preview is read-only');
 check($preview['data']['rows'][0]['id']==='000123'&&$preview['data']['rows'][0]['course']==='TESDA','Preview retains six-digit leading-zero ID and normalizes TESDA');
 $commit=$upload('commit',$csv);check($commit['success']&&$commit['data']['created']===3,'Staff multipart commit saves all three programs');
 $listed=expectApi('Filter directory Student to TESDA','borrowers.php','GET',[],true,'Staff',['type'=>'Student','course'=>'TESDA']);
 check(count($listed)===2&&in_array('000122',array_column($listed,'id_number'),true)&&in_array('000123',array_column($listed,'id_number'),true),'Manual and imported TESDA appear together with no section filter');
 $imported=null;
 foreach(['Imported TESDA Trainee','000123'] as $search){$found=expectApi('Borrow page lookup '.$search,'borrowers.php','GET',[],true,'Staff',['search'=>$search,'status'=>'active','lite'=>1]);check(count($found)===1&&$found[0]['id_number']==='000123'&&$found[0]['course']==='TESDA','Borrow lookup preserves the same Student identity');$imported=$found[0];}
 $importedId=(int)$imported['id'];
 $db->exec("INSERT INTO tools(name,code,category,quantity,available) VALUES('TESDA Loan Tool','TESDA-LOAN','Cookware',5,5)");
 $loan=expectApi('TESDA uses existing Student checkout','transactions.php','POST',['type'=>'borrow_batch','borrower_id'=>$importedId,'due_date'=>date('Y-m-d',strtotime('+1 day')),'due_time'=>'17:30','items'=>[['tool_code'=>'TESDA-LOAN','qty'=>1]],'request_token'=>'tesda_checkout_identity_001'],true,'Staff');
 $again=$upload('commit',$csv);check($again['success']&&$again['data']['created']===0&&(int)$db->query("SELECT COUNT(*) FROM borrowers WHERE id_number='000123'")->fetchColumn()===1,'Reimport creates no duplicate TESDA borrower');
 check((int)$db->query("SELECT borrower_id FROM transactions WHERE tool_id=(SELECT id FROM tools WHERE code='TESDA-LOAN') AND type='borrow'")->fetchColumn()===$importedId,'Reimport preserves the borrower linked to the existing loan');
 $alias=str_replace('Course / Strand','Program',$csv);check($upload('preview',$alias)['data']['counts']['existing']===3,'Program header alias accepts mixed list');
 $invalid=$upload('preview',"Student ID,Full Name,Year Level,Section,Program,Phone Number\r\n20268999,Invalid Program Student,1,A,MYSTERY,\r\n");$row=$invalid['data']['rows'][0];check($row['row']===2&&$row['state']==='invalid'&&str_contains(implode(' ',$row['errors']),'MYSTERY'),'Invalid program identifies its file row and value');
 $legacy=$upload('preview',"Student ID,Full Name\r\n20268003,Two Column Legacy\r\n",['course'=>'FPST','year_level'=>1,'section'=>'Rizal']);check($legacy['success']&&$legacy['data']['counts']['new']===1,'Legacy two-column list still accepts class defaults');
 $transition=$upload('commit',"Student ID,Full Name,Year Level,Section,Program,Phone Number\r\n20268001,Legacy FPST Student,,,TESDA,\r\n",['policy'=>'overwrite','overwrite_fields'=>['section','course']]);
 $transitioned=expectApi('Read FPST to TESDA import update','borrowers.php','GET',[],true,'Staff',['search'=>'20268001']);
 check($transition['success']&&$transitioned[0]['course']==='TESDA'&&empty($transitioned[0]['section_name'])&&empty($transitioned[0]['year_level']),'Authorized academic overwrite clears former FPST section and year');
 $template=file_get_contents('http://'.$address.'/?action=template');check(str_contains($template,'Student ID,Full Name,Year Level,Section,Course / Strand,Phone Number'),'Download template matches documented column order');
 $export=api('reports.php','GET',[],'Staff',['type'=>'borrowers']);check($export['success']&&str_contains(json_encode($export['data']),'000123'),'Existing borrower report includes TESDA student');
 if(class_exists('ZipArchive')){
  $xlsx=$work.'/tesda.xlsx';$zip=new ZipArchive();$zip->open($xlsx,ZipArchive::CREATE|ZipArchive::OVERWRITE);
  $zip->addFromString('xl/styles.xml','<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="000000"/></numFmts><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
  $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Student ID</t></is></c><c r="B1" t="inlineStr"><is><t>Full Name</t></is></c><c r="E1" t="inlineStr"><is><t>Program</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>000123</t></is></c><c r="B2" t="inlineStr"><is><t>Text XLSX Trainee</t></is></c><c r="E2" t="inlineStr"><is><t>tesda</t></is></c></row><row r="3"><c r="A3" s="1"><v>127</v></c><c r="B3" t="inlineStr"><is><t>Padded XLSX Trainee</t></is></c><c r="E3" t="inlineStr"><is><t>TESDA</t></is></c></row></sheetData></worksheet>');$zip->close();
  $table=smi_read_xlsx($xlsx);check($table[1]['c'][0]==='000123'&&$table[2]['c'][0]==='000127','XLSX reader preserves text IDs and existing zero-padding formats');
  $analyzed=smi_analyze($db,$table,smi_options('{}'));check($analyzed['rows'][0]['course']==='TESDA'&&$analyzed['rows'][1]['state']==='new','XLSX Program column uses the same TESDA validation');
 }else echo "SKIP: XLSX fixture (ZipArchive unavailable)\n";
 invariant();
}finally{proc_terminate($server);proc_close($server);}
if(getenv('TOOLTRACK_TESDA_BROWSER_TEST'))require __DIR__.'/tesda_browser_cases.php';
echo "PASSED: $checks TESDA workflow checks\n";
