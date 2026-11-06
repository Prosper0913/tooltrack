[CmdletBinding()]
param(
 [string]$ServerIp,
 [ValidateRange(1024,65535)][int]$Port=8443,
 [string]$XamppRoot='C:\xampp',
 [switch]$TrustCurrentUser,
 [switch]$ConfigureFirewall,
 [switch]$RestartApache
)
$ErrorActionPreference='Stop'
$project=Split-Path -Parent $PSScriptRoot
$apache=Join-Path $XamppRoot 'apache'
$openssl=Join-Path $apache 'bin\openssl.exe'
$httpd=Join-Path $apache 'bin\httpd.exe'
$main=Join-Path $apache 'conf\httpd.conf'
$tls=Join-Path $apache 'conf\ssl.tooltrack'
$extra=Join-Path $apache 'conf\extra\httpd-tooltrack-pwa.conf'
$utf8=[System.Text.UTF8Encoding]::new($false)
if(-not (Test-Path -LiteralPath $openssl)){throw 'XAMPP OpenSSL was not found.'}
if(-not $ServerIp){$ServerIp=(Get-NetIPConfiguration | Where-Object {$_.IPv4DefaultGateway} | Select-Object -First 1).IPv4Address.IPAddress}
$parsedIp=$null
if(-not [System.Net.IPAddress]::TryParse($ServerIp,[ref]$parsedIp) -or $parsedIp.AddressFamily -ne [System.Net.Sockets.AddressFamily]::InterNetwork){throw 'Pass the server Wi-Fi IPv4 address using -ServerIp.'}
$principal=[Security.Principal.WindowsPrincipal]::new([Security.Principal.WindowsIdentity]::GetCurrent())
$isAdmin=$principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if(($ConfigureFirewall -or $RestartApache) -and -not $isAdmin){throw 'Firewall configuration and Apache restart require Run as administrator.'}
New-Item -ItemType Directory -Force -Path $tls | Out-Null
# Certificate private keys stay outside htdocs and are readable only by this user, Administrators and SYSTEM.
$acl=[Security.AccessControl.DirectorySecurity]::new()
$acl.SetAccessRuleProtection($true,$false)
foreach($sid in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')){
 $identity=[Security.Principal.SecurityIdentifier]::new($sid)
 $rule=[Security.AccessControl.FileSystemAccessRule]::new($identity,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
 $acl.AddAccessRule($rule)
}
Set-Acl -LiteralPath $tls -AclObject $acl
function Invoke-OpenSsl([string[]]$Arguments){
 $previous=$ErrorActionPreference;$ErrorActionPreference='Continue'
 try{$output=& $openssl @Arguments 2>&1;$code=$LASTEXITCODE}finally{$ErrorActionPreference=$previous}
 if($code -ne 0){throw ('OpenSSL failed: '+($output -join "`n"))}
}
function Invoke-Httpd([string[]]$Arguments){
 $previous=$ErrorActionPreference;$ErrorActionPreference='Continue'
 try{$output=& $httpd @Arguments 2>&1;$code=$LASTEXITCODE}finally{$ErrorActionPreference=$previous}
 return @{Code=$code;Output=($output -join "`n")}
}
$rootKey=Join-Path $tls 'root-ca.key'
$rootCert=Join-Path $tls 'root-ca.crt'
$rootConfig=Join-Path $tls 'root-ca.cnf'
$rootText=@"
[req]
prompt=no
distinguished_name=dn
x509_extensions=ca
[dn]
CN=FPST ToolTrack Local CA
O=FPST ToolTrack
[ca]
basicConstraints=critical,CA:true,pathlen:0
keyUsage=critical,keyCertSign,cRLSign
subjectKeyIdentifier=hash
"@
[System.IO.File]::WriteAllText($rootConfig,$rootText,$utf8)
if((Test-Path -LiteralPath $rootKey) -xor (Test-Path -LiteralPath $rootCert)){throw 'Local CA files are incomplete. Restore the matching certificate and key before proceeding.'}
if(-not (Test-Path -LiteralPath $rootCert)){
 Invoke-OpenSsl @('req','-x509','-newkey','rsa:3072','-nodes','-sha256','-days','3650','-keyout',$rootKey,'-out',$rootCert,'-config',$rootConfig)
}
$serverKey=Join-Path $tls 'server.key'
$serverCert=Join-Path $tls 'server.crt'
$serverCsr=Join-Path $tls 'server.csr'
$serverConfig=Join-Path $tls 'server.cnf'
$serverText=@"
[req]
prompt=no
distinguished_name=dn
req_extensions=req_ext
[dn]
CN=FPST ToolTrack LAN Server
O=FPST ToolTrack
[req_ext]
subjectAltName=@names
[server_cert]
basicConstraints=critical,CA:false
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=@names
subjectKeyIdentifier=hash
authorityKeyIdentifier=keyid,issuer
[names]
DNS.1=localhost
DNS.2=tooltrack.local
IP.1=127.0.0.1
IP.2=$ServerIp
"@
[System.IO.File]::WriteAllText($serverConfig,$serverText,$utf8)
Invoke-OpenSsl @('req','-new','-newkey','rsa:2048','-nodes','-sha256','-keyout',$serverKey,'-out',$serverCsr,'-config',$serverConfig)
Invoke-OpenSsl @('x509','-req','-in',$serverCsr,'-CA',$rootCert,'-CAkey',$rootKey,'-CAcreateserial','-days','365','-sha256','-extfile',$serverConfig,'-extensions','server_cert','-out',$serverCert)
$download=Join-Path $PSScriptRoot 'tooltrack-local-ca.cer'
Invoke-OpenSsl @('x509','-in',$rootCert,'-outform','DER','-out',$download)
$certificate=[Security.Cryptography.X509Certificates.X509Certificate2]::new($download)
$hash=[Security.Cryptography.SHA256]::Create()
$fingerprint=([BitConverter]::ToString($hash.ComputeHash($certificate.RawData))).Replace('-',':');$hash.Dispose()
if($TrustCurrentUser){Import-Certificate -FilePath $download -CertStoreLocation 'Cert:\CurrentUser\Root' | Out-Null}
$docRoot=(Join-Path $XamppRoot 'htdocs').Replace('\','/')
$projectApache=$project.Replace('\','/')
$certApache=$serverCert.Replace('\','/');$keyApache=$serverKey.Replace('\','/')
$vhost=@"
# FPST ToolTrack LAN PWA. Other existing XAMPP ports and virtual hosts are unchanged.
Listen $Port
<VirtualHost *:$Port>
 ServerName tooltrack.local:$Port
 ServerAlias localhost $ServerIp
 DocumentRoot "$docRoot"
 SSLEngine on
 SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
 SSLCertificateFile "$certApache"
 SSLCertificateKeyFile "$keyApache"
 <Directory "$docRoot">
  Require all denied
 </Directory>
 <Directory "$projectApache">
  Options -Indexes
  AllowOverride All
  Require all granted
  DirectoryIndex index.php Login.html
 </Directory>
 <Location "/tooltrack/">
  Header always set Cache-Control "no-store, private, max-age=0"
  Header always set Pragma "no-cache"
  Header always set X-Content-Type-Options "nosniff"
 </Location>
 ErrorLog "logs/tooltrack-https-error.log"
 CustomLog "logs/tooltrack-https-access.log" common
</VirtualHost>
"@
$oldMain=[System.IO.File]::ReadAllText($main)
$oldExtra=if(Test-Path -LiteralPath $extra){[System.IO.File]::ReadAllText($extra)}else{$null}
if(-not (Test-Path -LiteralPath ($main+'.tooltrack-pwa.bak'))){Copy-Item -LiteralPath $main -Destination ($main+'.tooltrack-pwa.bak')}
[System.IO.File]::WriteAllText($extra,$vhost,$utf8)
$include='Include conf/extra/httpd-tooltrack-pwa.conf'
if(-not ($oldMain -match '(?m)^\s*Include\s+conf/extra/httpd-tooltrack-pwa\.conf\s*$')){[System.IO.File]::WriteAllText($main,$oldMain+"`r`n"+$include+"`r`n",$utf8)}
$syntax=Invoke-Httpd @('-t')
if($syntax.Code -ne 0){
 [System.IO.File]::WriteAllText($main,$oldMain,$utf8)
 if($null -ne $oldExtra){[System.IO.File]::WriteAllText($extra,$oldExtra,$utf8)}
 throw ('Apache configuration was not applied: '+$syntax.Output)
}
if($ConfigureFirewall){
 $ruleName="FPSTToolTrackHTTPS-$Port"
 if(-not (Get-NetFirewallRule -Name $ruleName -ErrorAction SilentlyContinue)){
  New-NetFirewallRule -Name $ruleName -DisplayName "FPST ToolTrack HTTPS ($Port, local Wi-Fi)" -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -RemoteAddress LocalSubnet -Profile Private -Program $httpd | Out-Null
 }
}
if($RestartApache){
 $result=Invoke-Httpd @('-k','restart')
 if($result.Code -ne 0){
  # XAMPP control-panel Apache may not be installed as a Windows service.
  $processes=@(Get-CimInstance Win32_Process -Filter "name='httpd.exe'" | Where-Object {$_.ExecutablePath -and [string]::Equals($_.ExecutablePath,$httpd,[StringComparison]::OrdinalIgnoreCase)})
  if(-not $processes){throw ('Apache restart failed: '+$result.Output)}
  foreach($process in $processes){Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue}
  Start-Process -FilePath $httpd -ArgumentList @('-d',('"'+$apache+'"')) -WorkingDirectory $apache -WindowStyle Hidden
  Start-Sleep -Seconds 2
 }
}
$info=@{url="https://${ServerIp}:$Port/tooltrack/";install_url="https://${ServerIp}:$Port/tooltrack/pwa/install.html";certificate_sha256=$fingerprint;port=$Port}
[System.IO.File]::WriteAllText((Join-Path $PSScriptRoot 'https-info.json'),($info|ConvertTo-Json),$utf8)
[System.IO.File]::WriteAllText((Join-Path $tls 'setup-result.json'),(@{success=$true;restarted=[bool]$RestartApache;firewall=[bool]$ConfigureFirewall;url=$info.url}|ConvertTo-Json),$utf8)
Write-Output ('HTTPS URL: '+$info.url)
Write-Output ('Install page: '+$info.install_url)
Write-Output ('CA SHA-256: '+$fingerprint)
Write-Output 'Apache syntax: OK. Private keys remain outside the website.'
if(-not $RestartApache){Write-Output 'Restart Apache from the XAMPP Control Panel, or rerun elevated with -ConfigureFirewall -RestartApache.'}