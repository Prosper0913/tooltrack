/* Admin-only database backup controls. The server enforces roles and CSRF separately. */
let ttBackupToken='',ttBackupSelected='',ttBackupBusy=false;
const ttB=id=>document.getElementById(id);
function ttBackupMessage(message,error=false){const el=ttB('backupMessage');el.textContent=message;el.classList.toggle('error',error);}
async function ttOpenBackups(){
  ttB('backupAdminPassword').value='';ttB('backupConfirmation').value='';
  openModal('backupSettingsModal');await ttLoadBackups();
}
async function ttBackupRequest(action,data={},fileForm=null,downloadName=null){
  const options={method:'POST',credentials:'same-origin'};
  if(fileForm){fileForm.set('action',action);fileForm.set('csrf',ttBackupToken);options.body=fileForm;}
  else{options.headers={'Content-Type':'application/json'};options.body=JSON.stringify({...data,action,csrf:ttBackupToken});}
  const response=await fetch('api/backups.php',options);
  if(!response.ok){let j;try{j=await response.json();}catch(_){throw new Error(`Backup request failed (${response.status}). Check upload limits or the PHP error log.`);}throw new Error(j.message||'Backup request failed.');}
  if(downloadName){const blob=await response.blob();const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=downloadName;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);return;}
  const j=await response.json();if(!j.success)throw new Error(j.message||'Backup request failed.');return j.data;
}
async function ttLoadBackups(){
  if(ttBackupBusy)return;
  try{
    const response=await fetch('api/backups.php',{cache:'no-store',credentials:'same-origin'});const j=await response.json();if(!j.success)throw new Error(j.message||'Could not load backups.');
    const d=j.data;ttBackupToken=d.csrf;ttB('backupSetup').hidden=d.configured;ttB('backupControls').hidden=!d.configured;
    if(!d.configured){ttB('backupLast').textContent='Not configured';ttB('backupStatus').textContent='Setup required';ttBackupMessage(d.message);return;}
    ttB('backupFolder').value=d.folder;
    ttB('backupLast').textContent=d.last_backup?new Date(d.last_backup.at).toLocaleString():'No backups yet';
    ttB('backupStatus').textContent=d.recovery_required?'Recovery required':(d.last_operation?`${d.last_operation.action.replaceAll('_',' ')}: ${d.last_operation.status}`:'Ready');
    ttB('backupHistory').replaceChildren();
    if(!d.history.length)ttB('backupHistory').textContent='No backups yet.';
    for(const item of d.history){
      const row=document.createElement('div');row.className='tt-backup-row';
      const info=document.createElement('div');const title=document.createElement('b');title.textContent=item.filename||item.action.replaceAll('_',' ');const detail=document.createElement('small');detail.textContent=`${new Date(item.at).toLocaleString()} · ${item.status} · ${item.message}${item.test_database?' Database: '+item.test_database:''}${item.bytes?' · '+(item.bytes/1024).toFixed(1)+' KB':''}`;info.append(title,detail);row.append(info);
      if(item.action==='backup'&&item.status==='success'&&item.available){
        const actions=document.createElement('div');actions.className='tt-backup-actions';
        const download=document.createElement('button');download.type='button';download.className='card-btn secondary tt-backup-control';download.textContent='Download Backup';download.onclick=()=>ttBackupRun('Downloading backup…',()=>ttBackupRequest('download',{id:item.id},null,item.filename),'Encrypted backup downloaded.');
        const select=document.createElement('button');select.type='button';select.className='card-btn secondary tt-backup-control';select.textContent='Use for restore';select.onclick=()=>{ttBackupSelected=item.id;ttB('backupUpload').value='';ttB('backupSelected').textContent='Selected: '+item.filename;ttB('backupSelected').scrollIntoView({block:'center',behavior:'smooth'});};actions.append(download,select);row.append(actions);
      }ttB('backupHistory').append(row);
    }
    if(d.recovery_required)ttBackupMessage('A restore needs manual recovery. The application is locked to protect the database. See the private recovery report.',true);
  }catch(e){ttBackupMessage(e.message,true);}
}
async function ttBackupRun(progress,work,success){
  if(ttBackupBusy)return;
  ttBackupBusy=true;document.querySelectorAll('.tt-backup-control').forEach(el=>el.disabled=true);ttBackupMessage(progress);
  try{const result=await work();ttBackupMessage(typeof success==='function'?success(result):success);return result;}
  catch(e){ttBackupMessage(e.message,true);return null;}
  finally{ttBackupBusy=false;document.querySelectorAll('.tt-backup-control').forEach(el=>el.disabled=false);ttB('backupAdminPassword').value='';await ttLoadBackups();}
}
function ttBackupNow(){return ttBackupRun('Creating, encrypting and verifying the database backup. Please wait…',()=>ttBackupRequest('backup'),'Backup saved and verified. Check Google Drive for Desktop for cloud upload status.');}
function ttBackupSaveFolder(){return ttBackupRun('Saving backup destination…',()=>ttBackupRequest('configure',{folder:ttB('backupFolder').value,password:ttB('backupAdminPassword').value}),'Backup destination saved. Add this folder in Google Drive for Desktop preferences.');}
function ttBackupExportKey(){return ttBackupRun('Preparing recovery key…',()=>ttBackupRequest('export_key',{password:ttB('backupAdminPassword').value},null,'tooltrack-recovery-key.json'),'Recovery key downloaded. Move it to safe offline storage, separate from your backups.');}
function ttBackupUploadChanged(){ttBackupSelected='';ttB('backupSelected').textContent=ttB('backupUpload').files[0]?'Uploaded file: '+ttB('backupUpload').files[0].name:'Select a backup from history, or upload an encrypted backup below.';}
async function ttBackupRestore(live){
  if(!ttBackupSelected&&!ttB('backupUpload').files[0]){ttBackupMessage('Select a backup from history or upload a .ttbackup file.',true);return;}
  if(live&&(ttB('backupConfirmation').value!=='RESTORE tooltrack_db'||!ttB('backupAdminPassword').value)){ttBackupMessage('Enter your administrator password and type RESTORE tooltrack_db to approve replacing live data.',true);return;}
  if(!confirm(live?'Replace the live ToolTrack database with this backup? This includes users and passwords. A verified safety backup will be created first.':'Create a separate test database and verify this backup? Live records will remain intact.'))return;
  const form=new FormData();form.set('id',ttBackupSelected);form.set('password',ttB('backupAdminPassword').value);form.set('confirmation',ttB('backupConfirmation').value);
  if(ttB('backupUpload').files[0])form.set('backup',ttB('backupUpload').files[0]);
  if(ttB('backupRecovery').files[0])form.set('recovery',ttB('backupRecovery').files[0]);
  // Live restore ends this session, so avoid requesting authenticated history afterward.
  if(live){
    if(ttBackupBusy)return;ttBackupBusy=true;document.querySelectorAll('.tt-backup-control').forEach(el=>el.disabled=true);ttBackupMessage('Verifying the backup, creating a safety backup and restoring. Do not close this page…');
    try{const r=await ttBackupRequest('restore',{},form);ttB('backupAdminPassword').value='';ttBackupMessage(`Restore completed and verified. Safety backup: ${r.safety_backup}. Sign in using an account from the restored backup.`);setTimeout(()=>location.href='Login.html',4500);}
    catch(e){ttBackupMessage(e.message,true);ttBackupBusy=false;document.querySelectorAll('.tt-backup-control').forEach(el=>el.disabled=false);ttB('backupAdminPassword').value='';await ttLoadBackups();}
  }else await ttBackupRun('Restoring into a separate test database and checking every table…',()=>ttBackupRequest('test_restore',{},form),r=>`Test restore passed: ${r.tables} tables, ${r.rows} rows verified. Test database retained: ${r.database}.`);
}
document.addEventListener('DOMContentLoaded',()=>{if(location.hash==='#backups'&&ttB('backupSettingsModal'))ttOpenBackups();});