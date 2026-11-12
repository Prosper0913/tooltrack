/* ============================================================
   users.js — Admin only: User Accounts (create/reset/delete) and the Audit Trail.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

/* ───────────────────────────────────────────────────────────
   USER ACCOUNTS (Admin only — page/nav are hidden server-side
   for Staff, and api/users.php enforces it independently)
─────────────────────────────────────────────────────────── */
async function loadUsers(){
  const tbody=document.getElementById('usersTableBody');
  if(!tbody) return; // page not rendered for this role
  if(!window.ttLiveSync?.rendering)tbody.innerHTML='<tr class="empty-row"><td colspan="5"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/users.php`);
    renderUsersTable(res.data||[]);
  }catch(_){tbody.innerHTML='<tr class="empty-row"><td colspan="5">Failed to load users.</td></tr>';}
}

function renderUsersTable(users){
  const tbody=document.getElementById('usersTableBody');
  if(!users.length){tbody.innerHTML='<tr class="empty-row"><td colspan="5">No users found.</td></tr>';return;}
  tbody.innerHTML=users.map(u=>`
    <tr>
      <td data-label="Name">${u.name}</td>
      <td data-label="Username"><code>${u.username}</code></td>
      <td data-label="Role"><span class="status-badge ${u.role==='Admin'?'low-stock':'available'}"><span class="status-dot"></span>${u.role}</span></td>
      <td data-label="Created">${new Date(u.created_at).toLocaleDateString('en-US',{year:'numeric',month:'short',day:'numeric'})}</td>
      <td data-label="Actions"><div class="action-btns">
        <button class="action-btn" title="Reset Password" onclick="openResetPasswordModal(${u.id},'${u.name.replace(/'/g,"\\'")}')"><i class="fas fa-key"></i></button>
        <button class="action-btn delete" title="Delete" onclick="deleteUser(${u.id},'${u.name.replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button>
      </div></td>
    </tr>`).join('');
}

let resetPasswordUserId=null;

function openResetPasswordModal(id,name){
  resetPasswordUserId=id;
  document.getElementById('rp_name').textContent=name;
  document.getElementById('rp_new').value='';
  document.getElementById('rp_confirm').value='';
  openModal('resetPasswordModal');
}

async function saveResetPassword(){
  const next=document.getElementById('rp_new').value;
  const confirmVal=document.getElementById('rp_confirm').value;
  if(!next||!confirmVal){showToast('Both fields are required.','error');return;}
  if(next.length<8){showToast('Password must be at least 8 characters.','error');return;}
  if(next!==confirmVal){showToast('Passwords don\'t match.','error');return;}
  setLoading('saveResetPasswordBtn',true);
  try{
    await apiFetch(`${API}/users.php`,{method:'PATCH',body:JSON.stringify({id:resetPasswordUserId,new_password:next})});
    showToast('Password reset.');
    closeModal('resetPasswordModal');
  }catch(_){}
  setLoading('saveResetPasswordBtn',false);
}

function openAddUserModal(){
  document.getElementById('u_name').value='';
  document.getElementById('u_username').value='';
  document.getElementById('u_password').value='';
  document.getElementById('u_role').value='Staff';
  openModal('userModal');
}

async function saveUser(){
  const name=document.getElementById('u_name').value.trim();
  const username=document.getElementById('u_username').value.trim();
  const password=document.getElementById('u_password').value;
  const role=document.getElementById('u_role').value;
  if(!name||!username||!password){showToast('All fields are required.','error');return;}
  if(password.length<8){showToast('Password must be at least 8 characters.','error');return;}
  setLoading('saveUserBtn',true);
  try{
    await apiFetch(`${API}/users.php`,{method:'POST',body:JSON.stringify({name,username,password,role})});
    showToast('User created.');
    closeModal('userModal');
    loadUsers();
  }catch(_){}
  setLoading('saveUserBtn',false);
}

function deleteUser(id,name){
  document.getElementById('deleteConfirmText').textContent=`Delete user "${name}"? This cannot be undone.`;
  document.getElementById('confirmDeleteBtn').onclick=async()=>{
    try{
      await apiFetch(`${API}/users.php?id=${id}`,{method:'DELETE'});
      showToast('User deleted.');
      closeModal('confirmDeleteModal');
      loadUsers();
    }catch(_){}
  };
  openModal('confirmDeleteModal');
}

/* ───────────────────────────────────────────────────────────
   AUDIT TRAIL (Admin only)
   GET api/audit_log.php?page=&per_page=&action=&search=&date_from=&date_to=
─────────────────────────────────────────────────────────── */
let auditCurrentPage=1;

const AUDIT_PER_PAGE=25;

let auditActionsLoaded=false;

const AUDIT_ACTION_LABEL={
  damage_loss_incident:'Damage/Loss Incident Filed',damage_loss_review_charge:'Damage/Loss Charge Reviewed',
  damage_loss_claim_payment:'Payment Claim Recorded',damage_loss_confirm_payment:'Payment Receipt Confirmed',
  damage_loss_reject_payment:'Payment Claim Rejected',damage_loss_close_case:'Financial Case Closed',damage_loss_acknowledge_recovery:'Recovery Financial Review',
  login:'Login', login_failed:'Login Failed', logout:'Logout',
  borrow:'Borrow', return:'Return',
  tool_create:'Tool Created', tool_edit:'Tool Edited', tool_retire:'Tool Retired', tool_reactivate:'Tool Reactivated', tool_condition_update:'Tool Condition Updated', tool_dispose:'Damaged Tools Disposed',
  borrower_create:'Borrower Added', borrower_edit:'Borrower Edited',
  borrower_deactivate:'Borrower Deactivated', borrower_reactivate:'Borrower Reactivated',
  user_create:'User Created', user_delete:'User Deleted',
  password_reset:'Password Reset', password_change:'Password Changed',
  replacement_request:'Replacement Requested', replacement_resolved:'Replacement Resolved',
  missing_reported:'Missing Reported', missing_confirmed:'Missing Confirmed', missing_rejected:'Missing Rejected',
  missing_replacement_requested:'Missing: Replacement Requested', missing_replacement_approved:'Missing: Replacement Approved',
  missing_replacement_rejected:'Missing: Replacement Rejected', missing_replacement_received:'Missing: Replacement Received', missing_recovered:'Missing: Tool Recovered',
  cms_sync:'CMS Sync',
};

const AUDIT_ACTION_BADGE={
  damage_loss_incident:'borrowed',damage_loss_review_charge:'neutral',damage_loss_claim_payment:'borrowed',
  damage_loss_confirm_payment:'available',damage_loss_reject_payment:'low-stock',damage_loss_close_case:'returned',damage_loss_acknowledge_recovery:'neutral',
  login:'available', login_failed:'low-stock', logout:'neutral',
  borrow:'borrowed', return:'available',
  tool_create:'available', tool_edit:'neutral', tool_retire:'low-stock', tool_reactivate:'available', tool_condition_update:'neutral', tool_dispose:'low-stock',
  borrower_create:'available', borrower_edit:'neutral',
  borrower_deactivate:'low-stock', borrower_reactivate:'available',
  user_create:'available', user_delete:'low-stock',
  password_reset:'low-stock', password_change:'neutral',
  replacement_request:'borrowed', replacement_resolved:'available',
  missing_reported:'borrowed', missing_confirmed:'low-stock', missing_rejected:'neutral',
  missing_replacement_requested:'borrowed', missing_replacement_approved:'available',
  missing_replacement_rejected:'neutral', missing_replacement_received:'available', missing_recovered:'available',
  cms_sync:'neutral',
};

async function loadAuditActionOptions(){
  if(auditActionsLoaded) return;
  try{
    const res=await apiFetch(`${API}/audit_log.php?actions=1`);
    const sel=document.getElementById('auditActionFilter');
    (res.data||[]).forEach(a=>{
      const o=document.createElement('option');
      o.value=a; o.textContent=AUDIT_ACTION_LABEL[a]||a;
      sel.appendChild(o);
    });
    auditActionsLoaded=true;
  }catch(_){}
}

async function loadAuditLog(){
  loadAuditActionOptions();
  const action=document.getElementById('auditActionFilter').value;
  const search=document.getElementById('auditSearchFilter').value;
  const date_from=document.getElementById('auditDateFrom').value;
  const date_to=document.getElementById('auditDateTo').value;
  const params=new URLSearchParams({page:auditCurrentPage,per_page:AUDIT_PER_PAGE,action,search,date_from,date_to});
  if(!window.ttLiveSync?.rendering)document.getElementById('auditTableBody').innerHTML='<tr class="empty-row"><td colspan="7"><span class="spinner dark"></span> Loading…</td></tr>';
  try{
    const res=await apiFetch(`${API}/audit_log.php?${params}`);
    renderAuditLogTable(res.data||[]);
    const totalPages=Math.ceil((res.total||0)/AUDIT_PER_PAGE);
    renderPagination('auditPaginationInfo','auditPaginationBtns',auditCurrentPage,totalPages,AUDIT_PER_PAGE,res.total||0,p=>{auditCurrentPage=p;loadAuditLog();});
  }catch(_){document.getElementById('auditTableBody').innerHTML='<tr class="empty-row"><td colspan="7">Failed to load audit trail.</td></tr>';}
}

function renderAuditLogTable(rows){
  const tbody=document.getElementById('auditTableBody');
  if(!rows.length){tbody.innerHTML='<tr class="empty-row"><td colspan="7">No matching activity.</td></tr>';return;}
  tbody.innerHTML=rows.map(r=>`
    <tr>
      <td data-label="Timestamp">${new Date(r.created_at).toLocaleString([],{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'})}</td>
      <td data-label="User">${r.username_snapshot||'—'}</td>
      <td data-label="Role">${r.role_snapshot?`<span class="status-badge ${r.role_snapshot==='Admin'?'low-stock':'available'}"><span class="status-dot"></span>${r.role_snapshot}</span>`:'—'}</td>
      <td data-label="Action"><span class="status-badge ${AUDIT_ACTION_BADGE[r.action]||'neutral'}"><span class="status-dot"></span>${AUDIT_ACTION_LABEL[r.action]||r.action}</span></td>
      <td data-label="Details">${r.description}</td>
      <td data-label="IP Address"><code>${r.ip_address||'—'}</code></td>
      <td data-label="Device">${r.device||'—'}</td>
    </tr>`).join('');
}

function applyAuditFilters(){auditCurrentPage=1;loadAuditLog();}

function clearAuditFilters(){
  document.getElementById('auditActionFilter').value='';
  document.getElementById('auditSearchFilter').value='';
  document.getElementById('auditDateFrom').value='';
  document.getElementById('auditDateTo').value='';
  auditCurrentPage=1;
  loadAuditLog();
}

async function exportAuditLogCSV(){
  try{
    const action=document.getElementById('auditActionFilter').value;
    const search=document.getElementById('auditSearchFilter').value;
    const date_from=document.getElementById('auditDateFrom').value;
    const date_to=document.getElementById('auditDateTo').value;
    const qs=new URLSearchParams({action,search,date_from,date_to}).toString();
    showToast('Preparing export…','warning');
    const all=await fetchAllPages(`${API}/audit_log.php?${qs}`,{perPage:100,maxPages:500});
    const res={data:all};
    const rows=[['Timestamp','User','Role','Action','Details','IP Address','Device']];
    (res.data||[]).forEach(r=>rows.push([r.created_at,r.username_snapshot||'',r.role_snapshot||'',AUDIT_ACTION_LABEL[r.action]||r.action,r.description,r.ip_address||'',r.device||'']));
    downloadCSV(rows,'audit_trail.csv');
    showToast('Audit trail exported.','success');
  }catch(_){}
}
