param([int]$Width=1440,[int]$Height=1000,[string]$Screen='page')
$ErrorActionPreference='Stop'
$fixtureRoot=$PSScriptRoot
$profilePath=Join-Path $fixtureRoot '.chrome-incident-cdp'
$browser=Start-Process -FilePath 'C:\Program Files\Google\Chrome\Application\chrome.exe' -ArgumentList @('--headless','--disable-gpu','--no-sandbox','--remote-debugging-port=9239',"--user-data-dir=$profilePath",'about:blank') -WindowStyle Hidden -PassThru
$socket=New-Object Net.WebSockets.ClientWebSocket
$script:commandId=0
function Send-Cdp([string]$Method,$Parameters=@{}){
 $script:commandId++;$requestId=$script:commandId
 $json=@{id=$requestId;method=$Method;params=$Parameters}|ConvertTo-Json -Depth 15 -Compress
 $bytes=[Text.Encoding]::UTF8.GetBytes($json)
 $null=$socket.SendAsync([ArraySegment[byte]]::new($bytes),[Net.WebSockets.WebSocketMessageType]::Text,$true,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
 do{
  $stream=New-Object IO.MemoryStream
  do{$buffer=New-Object byte[] 65536;$received=$socket.ReceiveAsync([ArraySegment[byte]]::new($buffer),[Threading.CancellationToken]::None).GetAwaiter().GetResult();if($received.MessageType -eq [Net.WebSockets.WebSocketMessageType]::Close){throw 'Browser socket closed.'};$stream.Write($buffer,0,$received.Count)}while(!$received.EndOfMessage)
  $response=[Text.Encoding]::UTF8.GetString($stream.ToArray())|ConvertFrom-Json
  $stream.Dispose()
 }while($response.id -ne $requestId)
 if($response.error){throw ($response.error|ConvertTo-Json -Compress)}
 return $response.result
}
try{
 $targets=$null
 for($attempt=0;$attempt -lt 50;$attempt++){try{$targets=Invoke-RestMethod 'http://localhost:9239/json/list';break}catch{Start-Sleep -Milliseconds 100}}
 if(!$targets){throw 'Chrome debugging endpoint did not start.'}
 $target=$targets|Where-Object type -eq 'page'|Select-Object -First 1
 $null=$socket.ConnectAsync([Uri]$target.webSocketDebuggerUrl,[Threading.CancellationToken]::None).GetAwaiter().GetResult()
 $null=Send-Cdp 'Emulation.setDeviceMetricsOverride' @{width=$Width;height=$Height;deviceScaleFactor=1;mobile=($Width -lt 700)}
 $url=([Uri](Join-Path $fixtureRoot 'incident-runner.html')).AbsoluteUri+'?screen='+$Screen
 $null=Send-Cdp 'Page.navigate' @{url=$url}
 for($attempt=0;$attempt -lt 100;$attempt++){
  Start-Sleep -Milliseconds 100
  $evaluation=Send-Cdp 'Runtime.evaluate' @{expression="document.getElementById('result')?.textContent || 'RUNNING'";returnByValue=$true}
  $resultText=$evaluation.result.value
  if($resultText -match 'PASSED:|FAIL:'){break}
 }
 $name=if($Width -lt 700){'mobile'}else{'desktop'}
 [IO.File]::WriteAllText((Join-Path $fixtureRoot "incident-layout-$name.log"),$resultText)
 Start-Sleep -Milliseconds 500
 $metrics=Send-Cdp 'Runtime.evaluate' @{expression="JSON.stringify({viewport:{width:innerWidth,height:innerHeight,visualWidth:visualViewport.width,visualHeight:visualViewport.height,offsetTop:visualViewport.offsetTop,offsetLeft:visualViewport.offsetLeft,scale:visualViewport.scale},modal:document.querySelector('#rpDetailsModal.active .modal')?.getBoundingClientRect()})";returnByValue=$true}
 [IO.File]::WriteAllText((Join-Path $fixtureRoot "incident-$name-metrics.json"),$metrics.result.value)
 $capture=Send-Cdp 'Page.captureScreenshot' @{format='png';captureBeyondViewport=$false}
 [IO.File]::WriteAllBytes((Join-Path $fixtureRoot "incident-$name.png"),[Convert]::FromBase64String($capture.data))
 $resultText
 if($resultText -notmatch 'PASSED:'){throw 'Incident browser checks failed.'}
}finally{
 if($socket.State -eq [Net.WebSockets.WebSocketState]::Open){try{$null=Send-Cdp 'Browser.close'}catch{}}
 $socket.Dispose()
 if(!$browser.HasExited){$browser.WaitForExit(3000)|Out-Null}
}
