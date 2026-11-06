<?php
// Real Staff forms and multipart imports against the disposable workflow database.
$browser='C:/Program Files/Google/Chrome/Application/chrome.exe';
if(!is_file($browser)){echo "SKIP: TESDA browser integration (Chrome unavailable)\n";return;}
$root=dirname(__DIR__);
foreach(['auth.php','borrower_enrollments.php'] as $file)copy($root.'/api/'.$file,$work.'/'.$file);
copy($root.'/backups/settings-panel.php',$work.'/settings-panel.php');
$bootstrap=file_get_contents($work.'/bootstrap.php');
file_put_contents($work.'/bootstrap.php',str_replace("getenv('TOOLTRACK_TEST_BODY')","file_get_contents('php://input')",$bootstrap));
$index=file_get_contents($root.'/index.php');
$index=str_replace(["__DIR__ . '/api/bootstrap.php'","__DIR__ . '/backups/settings-panel.php'"],["__DIR__ . '/bootstrap.php'","__DIR__ . '/settings-panel.php'"],$index);
$index=preg_replace('~<script src="https://[^"]+"></script>~','',$index);
$index=preg_replace('~<link[^>]+href="https://[^>]+>~','',$index);
$index=str_replace('<script src="assets/js/pwa.js" defer></script>','',$index);
$index=str_replace('<head>','<head><script>window.Html5Qrcode=function(){};window.Chart=function(){this.destroy=function(){};this.update=function(){};};</script>',$index);
file_put_contents($work.'/tesda-index.php',$index.'<pre id="tesdaBrowserResult">RUNNING</pre><script src="tesda-browser-test.js"></script>');
$router= <<<'PHP'
<?php
ini_set('session.save_path',__DIR__);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$relative=substr($path,strlen('/tooltrackv2/'));
if($relative==='staff.php'||$relative==='actor.php'){
 require __DIR__.'/bootstrap.php';$admin=$relative==='actor.php'&&($_GET['role']??'')==='Admin';
 $_SESSION=['user_id'=>$admin?1:2,'user_name'=>$admin?'Test Admin':'Test Staff','user_role'=>$admin?'Admin':'Staff'];
 if($relative==='staff.php'){require __DIR__.'/tesda-index.php';exit;}
 header('Content-Type: application/json');echo json_encode(['success'=>true]);exit;
}
if(str_starts_with($relative,'api/')){
 $file=basename($relative);if(is_file(__DIR__.'/'.$file)){require __DIR__.'/'.$file;if($file==='student_import.php')smi_handle();exit;}
}
if($relative==='tesda-browser-test.js'){header('Content-Type: application/javascript');readfile(__DIR__.'/'.$relative);exit;}
$root=TEST_ROOT;$file=realpath($root.'/'.$relative);
if($file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&in_array(pathinfo($file,PATHINFO_EXTENSION),['js','css','png','webmanifest'],true)){
 header('Content-Type: '.(['js'=>'application/javascript','css'=>'text/css','png'=>'image/png','webmanifest'=>'application/manifest+json'][pathinfo($file,PATHINFO_EXTENSION)]));readfile($file);exit;
}
http_response_code(404);
PHP;
file_put_contents($work.'/tesda-browser-router.php',str_replace('TEST_ROOT',var_export($root,true),$router));
$scenario= <<<'JS'
window.addEventListener('load',async()=>{
 const output=document.getElementById('tesdaBrowserResult'),passed=[],errors=[];
 window.addEventListener('error',e=>errors.push(e.message));window.confirm=()=>true;
 const assert=(ok,label)=>{if(!ok)throw Error(label);passed.push(label);};
 const el=id=>document.getElementById(id),until=async predicate=>{for(let i=0;i<100;i++){if(predicate())return;await new Promise(r=>setTimeout(r,50));}throw Error('Timed out waiting for browser control');};
 try{
  navigateTo('borrowers');await loadBorrowers();
  assert(!!document.querySelector('[onclick="openStudentImportModal()"]')&&!!document.querySelector('[onclick="openAddBorrowerModal()"]'),'Staff sees Add Borrower and Import Masterlist');
  await openAddBorrowerModal();el('b_course').value='TESDA';el('b_course').dispatchEvent(new Event('change',{bubbles:true}));
  assert(el('b_type').value==='Student'&&el('b_year').disabled,'TESDA automatically uses Student without school year');
  el('b_last').value='Browser TESDA';el('b_first').value='Manual';el('b_idnum').value='000125';await saveBorrower();
  const manual=(await apiFetch(API+'/borrowers.php?search=000125')).data[0];
  assert(manual?.course==='TESDA'&&manual.type==='Student'&&manual.id_number==='000125','Staff Add Borrower saves TESDA with a text ID');
  await fetch('actor.php?role=Admin');window.CURRENT_ROLE='Admin';await editBorrower(manual.id);
  assert(el('b_course').value==='TESDA'&&el('b_section').value===''&&el('b_year').disabled,'Edit Borrower reopens saved TESDA and optional academics');
  closeModal('borrowerModal');await fetch('actor.php?role=Staff');window.CURRENT_ROLE='Staff';
  openStudentImportModal();el('smiCourse').value='TESDA';smiCourseChanged();
  assert(el('smiYear').disabled&&!smiMeta().err,'TESDA import defaults allow blank year and section');
  el('smiCourse').value='';smiCourseChanged();
  const csv='Student ID,Full Name,Year Level,Section,Course / Strand,Phone Number\r\n000126,Browser Imported TESDA,,, tesda ,\r\n20268101,Browser FPST Student,2,Rizal,FPST,\r\n20268102,Browser TVL Student,12,A,TVL,\r\n';
  const file=new File([csv],'browser-mixed.csv',{type:'text/csv'}),dt=new DataTransfer();dt.items.add(file);el('smiFile').files=dt.files;el('smiFile').dispatchEvent(new Event('change',{bubbles:true}));
  assert(!el('smiPreviewBtn').disabled,'Mixed-program file can be checked without class defaults');
  await smiPreview();assert(SMI.data?.counts.new===3&&SMI.data.counts.invalid===0&&!el('smiCommitBtn').disabled,'Actual Check File shows three valid programs and enables Import');
  assert(SMI.data.rows[0].id==='000126'&&SMI.data.rows[0].course==='TESDA','Actual preview retains leading zeros and canonical TESDA');
  await smiCommit();assert(el('smiResultMsg').textContent.includes('3 new'),'Actual Staff Import button commits mixed file');closeStudentImportModal();
  await refreshBorrowerFilterOptions();el('borrowerTypeFilter').value='Student';el('borrowerCourseFilter').value='FPST';fillBorrowerSectionFilter('1-A');assert(el('borrowerSectionFilter').value==='1-A','FPST section selected before switching');
  el('borrowerCourseFilter').value='TESDA';onBorrowerCourseFilterChange();await loadBorrowers();
  assert(el('borrowerSectionFilter').value===''&&el('borrowerTableBody').textContent.includes('Browser TESDA')&&el('borrowerTableBody').textContent.includes('Browser Imported TESDA'),'Student/TESDA directory clears stale section and shows manual and imported records');
  assert([...el('borrowerCourseFilter').options].map(o=>o.textContent).join(',')==='All,FPST,TVL,TESDA','Directory Program options match requested classification');
  navigateTo('borrow');
  for(const query of ['Browser Imported TESDA','000126']){
   el('borrowerSearchInput').value=query;filterBorrowerDropdown();
   await until(()=>[...el('borrowerDropdownList').querySelectorAll('[onclick]')].some(row=>row.textContent.includes('000126')));
   const row=[...el('borrowerDropdownList').querySelectorAll('[onclick]')].find(row=>row.textContent.includes('000126'));assert(row.textContent.includes('TESDA'),'Borrow search shows TESDA for '+query);row.click();assert(el('borrowerSelect').value!=='','Borrow picker selects same student for '+query);
  }
  navigateTo('borrowers');openStudentImportModal();const retryFile=new DataTransfer();retryFile.items.add(new File([csv],'browser-mixed.csv',{type:'text/csv'}));el('smiFile').files=retryFile.files;smiFileChosen();await smiPreview();
  assert(SMI.data?.counts.existing===3&&SMI.data.counts.new===0,'Reimport preview matches the same three borrowers: '+JSON.stringify(SMI.data?.counts)+'; files='+el('smiFile').files.length);closeStudentImportModal();
  assert(errors.length===0,'No uncaught browser errors');
  output.textContent='PASS: '+passed.length+' real-browser TESDA workflow assertions\n'+passed.join('\n');
 }catch(e){output.textContent='FAIL: '+e.stack+'\n'+errors.join('\n');}
});
JS;
file_put_contents($work.'/tesda-browser-test.js',$browserScenario??$scenario);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);$address=stream_socket_get_name($socket,false);fclose($socket);
$server=proc_open([PHP_BINARY,'-d','upload_tmp_dir='.$work,'-d','display_errors=0','-S',$address,$work.'/tesda-browser-router.php'],[0=>['pipe','r'],1=>['file',$work.'/tesda-browser-server.log','a'],2=>['file',$work.'/tesda-browser-server-error.log','a']],$serverPipes,$work);fclose($serverPipes[0]);
try{
 for($i=0;$i<50;$i++){$s=@stream_socket_client('tcp://'.$address,$errno,$error,.1);if($s){fclose($s);break;}usleep(20000);}
 $profile=$root.'/tests/'.($browserProfileName??'.tesda-browser-chrome');
 $browserArgs=[$browser,'--headless','--disable-gpu','--no-sandbox','--disable-extensions','--user-data-dir='.$profile,'--dump-dom','--virtual-time-budget=20000'];
 if(isset($browserWindowSize))$browserArgs[]='--window-size='.$browserWindowSize;
 $browserArgs[]='http://'.$address.'/tooltrackv2/staff.php';
 $proc=proc_open($browserArgs,[0=>['pipe','r'],1=>['file',$work.'/tesda-browser.html','w'],2=>['file',$work.'/tesda-browser.log','w']],$browserPipes);fclose($browserPipes[0]);proc_close($proc);
 $html=file_get_contents($work.'/tesda-browser.html');preg_match('~<pre id="tesdaBrowserResult">(.*?)</pre>~s',$html,$match);$result=html_entity_decode($match[1]??'Missing browser result');
 if(!str_starts_with($result,'PASS:'))echo $result."\n".substr(file_get_contents($work.'/tesda-browser-server-error.log'),-3000)."\n";
 check(str_starts_with($result,'PASS:'),$result);
}finally{proc_terminate($server);proc_close($server);}
