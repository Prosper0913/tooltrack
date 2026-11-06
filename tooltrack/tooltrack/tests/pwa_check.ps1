[CmdletBinding()]
param([string]$BaseUrl='https://192.168.1.4:8443/tooltrack/',[switch]$IphoneLayout)
$ErrorActionPreference='Stop'
$chrome='C:\Program Files\Google\Chrome\Application\chrome.exe'
if(-not (Test-Path -LiteralPath $chrome)){throw 'Install Chrome to run this test.'}
if(([Uri]$BaseUrl).Scheme -ne 'https'){throw 'Use the trusted HTTPS URL.'}
$pwaProfile=Join-Path $env:TEMP ('tooltrack-pwa-check-'+[guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $pwaProfile | Out-Null
$browser=Start-Process -FilePath $chrome -ArgumentList @('--headless','--disable-gpu','--no-first-run','--no-default-browser-check','--remote-debugging-port=0',('--user-data-dir="'+$pwaProfile+'"'),($BaseUrl+'pwa/install.html')) -WindowStyle Hidden -PassThru
$ws=[Net.WebSockets.ClientWebSocket]::new();$script:requestId=0;$script:checkCount=0
function Send-Cdp([string]$Method,[hashtable]$Params=@{},[string]$Session=''){
 $script:requestId++;$id=$script:requestId
 $message=@{id=$id;method=$Method;params=$Params};if($Session){$message.sessionId=$Session}
 $bytes=[Text.Encoding]::UTF8.GetBytes(($message|ConvertTo-Json -Depth 30 -Compress))
 $timeout=[Threading.CancellationTokenSource]::new(15000)
 try{
  $ws.SendAsync([ArraySegment[byte]]::new($bytes),[Net.WebSockets.WebSocketMessageType]::Text,$true,$timeout.Token).GetAwaiter().GetResult() | Out-Null
  while($true){
   $buffer=New-Object byte[] 65536;$stream=[IO.MemoryStream]::new()
   try{
    do{$received=$ws.ReceiveAsync([ArraySegment[byte]]::new($buffer),$timeout.Token).GetAwaiter().GetResult();$stream.Write($buffer,0,$received.Count)}while(-not $received.EndOfMessage)
    $reply=[Text.Encoding]::UTF8.GetString($stream.ToArray())|ConvertFrom-Json
   }finally{$stream.Dispose()}
   if($reply.id -eq $id){if($reply.error){throw ($Method+': '+$reply.error.message)};return $reply.result}
  }
 }finally{$timeout.Dispose()}
}
function Check-Pwa([bool]$Ok,[string]$Label){if(-not $Ok){throw ('FAIL: '+$Label)};$script:checkCount++;Write-Output ('PASS: '+$Label)}
try{
 $portFile=Join-Path $pwaProfile 'DevToolsActivePort'
 for($i=0;$i -lt 100 -and -not (Test-Path -LiteralPath $portFile);$i++){Start-Sleep -Milliseconds 100}
 if(-not (Test-Path -LiteralPath $portFile)){throw 'Chrome did not expose its test debugging port.'}
 $lines=[IO.File]::ReadAllLines($portFile)
 $ws.ConnectAsync([Uri]::new('ws://127.0.0.1:'+$lines[0]+$lines[1]),[Threading.CancellationToken]::None).GetAwaiter().GetResult() | Out-Null
 $targets=Send-Cdp 'Target.getTargets';$target=$targets.targetInfos | Where-Object {$_.type -eq 'page'} | Select-Object -First 1
 $session=(Send-Cdp 'Target.attachToTarget' @{targetId=$target.targetId;flatten=$true}).sessionId
 Send-Cdp 'Page.enable' @{} $session | Out-Null
 Send-Cdp 'Network.enable' @{} $session | Out-Null
 $expression=@'
(async()=>{
 const base=new URL(BASE_URL);
 if(!document.querySelector('link[rel="manifest"]'))throw new Error('Install page unavailable or certificate not trusted');
 const registration=await Promise.race([navigator.serviceWorker.ready,new Promise((_,reject)=>setTimeout(()=>reject(new Error('Service worker not ready')),10000))]);
 if(!navigator.serviceWorker.controller)await new Promise(resolve=>{navigator.serviceWorker.addEventListener('controllerchange',resolve,{once:true});setTimeout(resolve,1000)});
 const manifestResponse=await fetch(new URL('manifest.webmanifest',base),{cache:'no-store'});
 const manifest=await manifestResponse.json();const icons=[];
 for(const icon of manifest.icons){const response=await fetch(new URL(icon.src,base));const bitmap=await createImageBitmap(await response.blob());icons.push({expected:icon.sizes,actual:`${bitmap.width}x${bitmap.height}`})}
 const api=await fetch(new URL('api/borrowers.php',base),{cache:'no-store'});
 const login=await fetch(new URL('Login.html',base),{cache:'no-store'});
 const cachesFound=[];
 for(const key of await caches.keys()){const cache=await caches.open(key);cachesFound.push({key,urls:(await cache.keys()).map(r=>r.url)})}
 return {secure:isSecureContext,scope:registration.scope,controlled:!!navigator.serviceWorker.controller,display:manifest.display,start:new URL(manifest.start_url,base).href,manifestMime:manifestResponse.headers.get('content-type'),icons,apiStatus:api.status,apiCache:api.headers.get('cache-control'),loginCache:login.headers.get('cache-control'),caches:cachesFound};
})()
'@
 $expression=$expression.Replace('BASE_URL',($BaseUrl|ConvertTo-Json -Compress))
 for($i=0;$i -lt 30;$i++){$state=Send-Cdp 'Runtime.evaluate' @{expression='document.readyState';returnByValue=$true} $session;if($state.result.value -eq 'complete'){break};Start-Sleep -Milliseconds 100}
 if($IphoneLayout){
  # This remains the Chrome engine; only the viewport, touch input and UA are emulated.
  $iphoneUa='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1'
  Send-Cdp 'Emulation.setUserAgentOverride' @{userAgent=$iphoneUa;platform='iPhone'} $session | Out-Null
  Send-Cdp 'Emulation.setTouchEmulationEnabled' @{enabled=$true;maxTouchPoints=5} $session | Out-Null
  foreach($width in @(375,390,430)){
   Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$width;height=844;deviceScaleFactor=3;mobile=$true} $session | Out-Null
   foreach($relative in @('pwa/install.html','Login.html')){
    Send-Cdp 'Page.navigate' @{url=($BaseUrl+$relative)} $session | Out-Null
    for($n=0;$n -lt 50;$n++){
     Start-Sleep -Milliseconds 100
     try{$ready=Send-Cdp 'Runtime.evaluate' @{expression='document.readyState';returnByValue=$true} $session;if($ready.result.value -eq 'complete'){break}}catch{}
    }
    $layout=Send-Cdp 'Runtime.evaluate' @{expression='({viewport:innerWidth,scroll:document.documentElement.scrollWidth,apple:!!document.querySelector("link[rel=apple-touch-icon]"),iosHelp:document.body.innerText.includes("iPhone / iPad (Safari)"),status:document.getElementById("pwaStatus")?.textContent||""})';returnByValue=$true} $session
    $value=$layout.result.value
    Check-Pwa ($value.viewport -eq $width -and $value.scroll -le $width) ("Chrome iPhone emulation ${width}px: $relative fits without horizontal overflow")
    Check-Pwa $value.apple ("Apple Home Screen icon present: $relative at ${width}px")
    if($relative -eq 'pwa/install.html'){
     Check-Pwa $value.iosHelp ("iPhone certificate and Safari installation instructions at ${width}px")
     $statusCheck=Send-Cdp 'Runtime.evaluate' @{expression='navigator.serviceWorker.ready.then(()=>new Promise(r=>setTimeout(()=>r(document.getElementById("pwaStatus").textContent),150)))';awaitPromise=$true;returnByValue=$true} $session
     Check-Pwa ($statusCheck.result.value -like '*Safari*Add to Home Screen*') ("Safari-specific installation status at ${width}px (UA emulation)")
    }
   }
  }
  Send-Cdp 'Page.navigate' @{url=($BaseUrl+'pwa/install.html')} $session | Out-Null
  for($n=0;$n -lt 50;$n++){Start-Sleep -Milliseconds 100;try{$ready=Send-Cdp 'Runtime.evaluate' @{expression='document.readyState';returnByValue=$true} $session;if($ready.result.value -eq 'complete'){break}}catch{}}
 }
 $evaluation=Send-Cdp 'Runtime.evaluate' @{expression=$expression;awaitPromise=$true;returnByValue=$true} $session
 if($evaluation.exceptionDetails){throw ($evaluation.exceptionDetails|ConvertTo-Json -Depth 8 -Compress)}
 $data=$evaluation.result.value
 Check-Pwa $data.secure 'Trusted HTTPS secure context (no certificate bypass)'
 Check-Pwa ($data.scope -eq $BaseUrl) 'Service worker scope is limited to ToolTrack'
 Check-Pwa $data.controlled 'Service worker controls the install page'
 Check-Pwa ($data.display -eq 'standalone') 'Manifest requests standalone app windows'
 Check-Pwa ($data.start -eq ($BaseUrl+'index.php')) 'Installed app opens the authenticated entry point'
 Check-Pwa ($data.manifestMime -like 'application/manifest+json*') 'Manifest MIME type'
 foreach($icon in $data.icons){Check-Pwa ($icon.expected -eq $icon.actual) ('App icon dimensions '+$icon.expected)}
 Check-Pwa ($data.apiStatus -eq 401) 'Unauthenticated API access remains protected'
 Check-Pwa ($data.apiCache -like '*no-store*') 'API responses are not HTTP-cacheable'
 Check-Pwa ($data.loginCache -like '*no-store*') 'Login pages are not HTTP-cacheable'
 $urls=@($data.caches | ForEach-Object {$_.urls})
 $expected=@(($BaseUrl+'offline.html'),($BaseUrl+'assets/pwa/icon-192.png'),($BaseUrl+'assets/pwa/icon-512.png'))
 Check-Pwa ($urls.Count -eq 3 -and @($urls | Where-Object {$_ -notin $expected}).Count -eq 0) 'Cache Storage contains only the public offline page and two icons'
 $installability=Send-Cdp 'Page.getInstallabilityErrors' @{} $session
 Check-Pwa (@($installability.installabilityErrors).Count -eq 0) 'Chrome reports no PWA installability errors'
 Send-Cdp 'Network.emulateNetworkConditions' @{offline=$true;latency=0;downloadThroughput=0;uploadThroughput=0} $session | Out-Null
 # Chrome applies CDP offline emulation per target; include the service worker's own network target.
 $workerTargets=(Send-Cdp 'Target.getTargets').targetInfos | Where-Object {$_.type -eq 'service_worker' -and $_.url -eq ($BaseUrl+'sw.js')}
 $workerSessions=@()
 foreach($workerTarget in $workerTargets){
  $workerSession=(Send-Cdp 'Target.attachToTarget' @{targetId=$workerTarget.targetId;flatten=$true}).sessionId
  $workerSessions+=$workerSession
  Send-Cdp 'Network.enable' @{} $workerSession | Out-Null
  Send-Cdp 'Network.emulateNetworkConditions' @{offline=$true;latency=0;downloadThroughput=0;uploadThroughput=0} $workerSession | Out-Null
 }
 $offline=Send-Cdp 'Runtime.evaluate' @{expression="fetch(new URL('api/transactions.php',location.href.replace('/pwa/install.html','/')),{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}).then(()=>false,()=>true)";awaitPromise=$true;returnByValue=$true} $session
 Check-Pwa ($offline.result.value -eq $true) 'Offline transaction requests fail instead of being queued'
 Send-Cdp 'Page.navigate' @{url=($BaseUrl+'index.php')} $session | Out-Null
 $offlinePage=$null
 for($i=0;$i -lt 30;$i++){
  Start-Sleep -Milliseconds 100
  try{$page=Send-Cdp 'Runtime.evaluate' @{expression='({title:document.title,text:document.body.innerText})';returnByValue=$true} $session;$offlinePage=$page.result.value;if($offlinePage.title -like '*Offline*'){break}}catch{}
 }
 if($offlinePage.title -notlike '*Offline*'){Write-Output ($offlinePage|ConvertTo-Json -Compress)}
 Check-Pwa ($offlinePage.title -like '*Offline*' -and $offlinePage.text -like '*ToolTrack is offline*') 'Offline navigation shows no cached student data'
 Send-Cdp 'Network.emulateNetworkConditions' @{offline=$false;latency=0;downloadThroughput=-1;uploadThroughput=-1} $session | Out-Null
 Write-Output ('ALL '+$script:checkCount+' PWA CHECKS PASSED')
}finally{
 if($ws.State -eq [Net.WebSockets.WebSocketState]::Open){try{Send-Cdp 'Browser.close' | Out-Null}catch{}}
 $ws.Dispose()
}