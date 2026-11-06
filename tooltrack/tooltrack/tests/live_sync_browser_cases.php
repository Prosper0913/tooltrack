<?php
// Uses the disposable workflow schema and two independent PHP cookie sessions.
$browser='C:/Program Files/Google/Chrome/Application/chrome.exe';
if(!is_file($browser)){echo "SKIP: Chrome browser integration (not installed)\n";return;}
$root=dirname(__DIR__);
// CLI API workers inject request bodies through an environment variable;
// the HTTP integration server must read the actual browser request stream.
$bootstrap=file_get_contents($work.'/bootstrap.php');
file_put_contents($work.'/bootstrap.php',str_replace("getenv('TOOLTRACK_TEST_BODY')","file_get_contents('php://input')",$bootstrap));
copy($root.'/api/auth.php',$work.'/auth.php');
copy($root.'/backups/settings-panel.php',$work.'/settings-panel.php');
$index=file_get_contents($root.'/index.php');
$index=str_replace(["__DIR__ . '/api/bootstrap.php'","__DIR__ . '/backups/settings-panel.php'"],["__DIR__ . '/bootstrap.php'","__DIR__ . '/settings-panel.php'"],$index);
// Offline integration: scanners and chart drawing are outside this synchronization test.
$index=preg_replace('~<script src="https://[^"]+"></script>~','',$index);
$index=preg_replace('~<link[^>]+href="https://[^>]+>~','',$index);
$index=str_replace('<head>','<head><script>window.Html5Qrcode=function(){};window.Chart=function(){this.destroy=function(){};this.update=function(){};};</script>',$index);
$db->exec("INSERT INTO tools(name,code,category,quantity,available,min_stock,price) VALUES('Browser live tool','BROWSER-LIVE','Cookware',5,5,1,5)");
$index.='<pre id="browserSyncResult">RUNNING</pre><script src="browser-test.js"></script>';
file_put_contents($work.'/index.php',$index);
$port=random_int(21000,29000);
$router= <<<'PHP'
<?php
ini_set('session.save_path',__DIR__);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$relative=substr($path,strlen('/tooltrackv2/'));
if($relative==='staff.php'||isset($_GET['test_staff']))session_name('TT_STAFF_TEST');
if($relative==='admin.php'||$relative==='staff.php'){
 require __DIR__.'/bootstrap.php';$admin=$relative==='admin.php';
 $_SESSION=['user_id'=>$admin?1:2,'user_name'=>$admin?'Test Admin':'Test Staff','user_role'=>$admin?'Admin':'Staff'];
 if($admin){require __DIR__.'/index.php';exit;}
 header('Content-Type: text/html');echo '<script src="staff-test.js"></script>';exit;
}
if(str_starts_with($relative,'api/')){
 $file=basename($relative);if(is_file(__DIR__.'/'.$file)){require __DIR__.'/'.$file;exit;}
}
if(in_array($relative,['browser-test.js','staff-test.js'],true)){header('Content-Type: application/javascript');readfile(__DIR__.'/'.$relative);exit;}
$root=ROOT;
$file=realpath($root.'/'.$relative);
if($file&&str_starts_with($file,$root.DIRECTORY_SEPARATOR)&&is_file($file)&&in_array(pathinfo($file,PATHINFO_EXTENSION),['js','css','png','webmanifest'],true)){
 header('Content-Type: '.(['js'=>'application/javascript','css'=>'text/css','png'=>'image/png','webmanifest'=>'application/manifest+json'][pathinfo($file,PATHINFO_EXTENSION)]));readfile($file);exit;
}
http_response_code(404);
PHP;
$router=str_replace('ROOT',var_export($root,true),$router);
file_put_contents($work.'/router.php',$router);
file_put_contents($work.'/browser-test.js',str_replace('PORT',(string)$port, <<<'JS'
window.addEventListener('load',async()=>{
 const result=document.getElementById('browserSyncResult'),errors=[];
 window.addEventListener('error',e=>errors.push(e.message));
 try{
  navigateTo('tools');await loadTools();
  const before=toolMenuCache.get([...toolMenuCache.keys()].find(id=>toolMenuCache.get(id).code==='BROWSER-LIVE'));
  if(!before||Number(before.available)!==5)throw Error('Initial inventory did not load');
  const row=[...document.querySelectorAll('#toolsTableBody tr[data-record]')].find(r=>r.dataset.record===String(before.id));
  toggleTableDetail(row.querySelector('.tt-expand'));
  editTool(before.id);await new Promise(r=>setTimeout(r,100));
  document.getElementById('t_name').value='Unsaved browser draft';
  const frame=document.createElement('iframe');frame.hidden=true;frame.src='staff.php';document.body.append(frame);
  const message=await new Promise((resolve,reject)=>{window.addEventListener('message',e=>{if(e.source===frame.contentWindow)resolve(e.data);});setTimeout(()=>reject(Error('Staff transaction timed out')),8000);});
  if(!message.success)throw Error('Staff borrow failed: '+JSON.stringify(message));
  await new Promise(r=>setTimeout(r,6500));
  const current=toolMenuCache.get(before.id);
  if(Number(current.available)!==3)throw Error('Remote inventory remained stale: '+JSON.stringify(current));
  if(document.getElementById('t_name').value!=='Unsaved browser draft')throw Error('Draft was overwritten');
  if(row!==document.querySelector('#toolsTableBody tr[data-record="'+before.id+'"]')||row.nextElementSibling.hidden)throw Error('Expanded row was reset');
  if(errors.length)throw Error('Browser error: '+errors.join('; '));
  result.textContent='PASS: Independent Admin/Staff browser sessions; real staff checkout; inventory 5 → 3 without reload; open draft and expanded row retained.';
 }catch(e){result.textContent='FAIL: '+e.stack;}
});
JS));
file_put_contents($work.'/staff-test.js', <<<'JS'
(async()=>{try{const r=await fetch('api/transactions.php?test_staff=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type:'borrow_batch',borrower_id:1,due_date:new Date(Date.now()+86400000).toLocaleDateString('en-CA'),due_time:'17:30',items:[{tool_code:'BROWSER-LIVE',qty:2}],request_token:'browser_live_checkout_001'})});parent.postMessage(await r.json(),'*');}catch(e){parent.postMessage({success:false,message:e.message},'*');}})();
JS);
$server=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$work.'/router.php'],[0=>['pipe','r'],1=>['file',$work.'/server-out.log','a'],2=>['file',$work.'/server-error.log','a']],$serverPipes,$work);
try{
    fclose($serverPipes[0]);
    $url='http://127.0.0.1:'.$port.'/tooltrackv2/admin.php';
    for($i=0;$i<30;$i++){ $socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($socket){fclose($socket);break;}usleep(100000); }
    $profile=$root.'/tests/.live-sync-e2e-chrome';
    $proc=proc_open([$browser,'--headless','--disable-gpu','--no-sandbox','--disable-extensions','--user-data-dir='.$profile,'--dump-dom','--virtual-time-budget=18000',$url],[0=>['pipe','r'],1=>['file',$work.'/browser-out.html','w'],2=>['file',$work.'/browser-error.log','w']],$browserPipes);
    fclose($browserPipes[0]);proc_close($proc);
    $html=file_get_contents($work.'/browser-out.html');
    preg_match('~<pre id="browserSyncResult">(.*?)</pre>~s',$html,$match);
    $result=html_entity_decode($match[1]??'No browser result');
    if(!str_starts_with($result,'PASS:'))echo substr(file_get_contents($work.'/server-error.log'),-10000)."\n".substr(file_get_contents($work.'/browser-error.log'),-3000)."\n";
    check(str_starts_with($result,'PASS:'),$result);
}finally{proc_terminate($server);proc_close($server);}
