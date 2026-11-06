/* ============================================================
   borrow.js — Borrow tab: tool scanner, ID scanner, borrower picker, Selected Tools cart, phone scan mode,
   review sheet, history sheet and handleBorrow() (borrow_batch).
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

// Closes the Borrow page tool search dropdown on outside click.
document.addEventListener('click',function(e){
  [['borrowToolSearchWrap','borrowToolDropdownList']].forEach(([w,l])=>{
    const wrap=document.getElementById(w), box=document.getElementById(l);
    if(wrap && box && !wrap.contains(e.target)) box.style.display='none';
  });
});

/* ── Searchable borrower dropdown (Borrow page) ─────────────────
   The hidden <select id="borrowerSelect"> stays the single source of truth
   (same id/value every other function reads). Only ACTIVE borrowers are offered. */
function openBorrowerDropdown(){
  borrowerTypeahead('borrowerDropdownList',document.getElementById('borrowerSearchInput').value.trim(),borrowerRow('selectBorrowerOption'),{status:'active'});
}

function filterBorrowerDropdown(){
  if(idScannedBorrowerId) clearIdScan();
  document.getElementById('borrowerSelect').value=''; // typing invalidates any prior pick
  brPickedBorrower=null; brRenderBorrower();
  openBorrowerDropdown();
}

function selectBorrowerOption(id){
  const b=borrowerResultsById.get(String(id));
  if(!b)return;
  if(String(b.id)!==idScannedBorrowerId) clearIdScan();   // <-- add this line
  const sel=document.getElementById('borrowerSelect');
  // The hidden <select> only ever holds the borrower that was picked (handleBorrow reads its value and option text).
  sel.innerHTML=`<option value="">Select Borrower…</option><option value="${b.id}">${escapeHtml(b.full_name)} — ${escapeHtml(b.id_number)}</option>`;
  sel.value=String(b.id);
  sel.dispatchEvent(new Event('change'));
  document.getElementById('borrowerSearchInput').value=`${b.full_name} — ${b.id_number}`;
  brPickedBorrower=b; brRenderBorrower();
  closeBorrowerDropdown();
}

function closeBorrowerDropdown(){
  document.getElementById('borrowerDropdownList').style.display='none';
}

document.addEventListener('click',function(e){
  const wrap=document.getElementById('borrowerSearchWrap');
  if(wrap && !wrap.contains(e.target))closeBorrowerDropdown();
});

/* ───────────────────────────────────────────────────────────
   9. QR SCANNERS (BORROW & RETURN)
─────────────────────────────────────────────────────────── */
let borrowQr=null, borrowScanning=false;

async function startBorrowScanner(){
  if(borrowScanning)return;
  if(idScanning) await stopIdScannerAsync();   // one camera at a time
  const S=(t,ty)=>setScanStatus('borrowScanStatus','borrowScanStatusText',t,ty);
  S('Requesting camera access…','scanning');
  try{
    await openCamera(borrowQr,'borrowCameraSelect',
      decoded=>{document.getElementById('borrowToolId').value=decoded;document.getElementById('clearBorrowToolBtn').classList.add('visible');matchBorrowToolCode(decoded);setScanStatus('borrowScanStatus','borrowScanStatusText','✓ Scanned: '+decoded,'success');stopBorrowScanner();},
      ()=>{
        document.getElementById('borrowReader').style.display='block';
        document.getElementById('borrowStartBtn').style.display='none';
        document.getElementById('borrowStopBtn').style.display='flex';
        brScanLive('tool',true);
      });
    borrowScanning=true;
    S('Scanning — point camera at QR code…','scanning');
  }catch(err){
    if(err && err.noUsbCamera){            // Camera unavailable: keep manual search editable.
      resetBorrowScannerUI();
      S('Camera unavailable — search by tool name or code below.','');
      const tc=btEl('borrowToolSearchInput'); if(tc) tc.focus({preventScroll:true});
      return;
    }
    S(cameraErrorText(err),'error');
    resetBorrowScannerUI();
  }
}

function stopBorrowScanner(){if(!borrowScanning){resetBorrowScannerUI();return;}borrowQr.stop().then(()=>{borrowScanning=false;resetBorrowScannerUI();}).catch(()=>{borrowScanning=false;resetBorrowScannerUI();});}

function resetBorrowScannerUI(){document.getElementById('borrowReader').style.display='none';document.getElementById('borrowStartBtn').style.display='flex';document.getElementById('borrowStopBtn').style.display='none';brScanLive('tool',false);}

function borrowScanUploadedImage(event){
  const file=event.target.files[0];if(!file)return;
  if(borrowScanning)stopBorrowScanner();
  document.getElementById('borrowUploadedQrImg').src=URL.createObjectURL(file);
  document.getElementById('borrowUploadPreview').style.display='flex';
  setScanStatus('borrowScanStatus','borrowScanStatusText','Reading QR from image…','scanning');
  const fs=new Html5Qrcode('borrowReader');
  fs.scanFile(file,true).then(decoded=>{document.getElementById('borrowToolId').value=decoded;document.getElementById('clearBorrowToolBtn').classList.add('visible');matchBorrowToolCode(decoded);setScanStatus('borrowScanStatus','borrowScanStatusText','✓ Scanned from image: '+decoded,'success');}).catch(()=>setScanStatus('borrowScanStatus','borrowScanStatusText','Could not read QR. Try a clearer photo.','error')).finally(()=>event.target.value='');
}

/* ───────────────────────────────────────────────────────────
   ID SCAN (Borrow page) — camera (QR + barcodes) or USB / phone scanner
   Looks the borrower up by ID number: found → selected in the Borrow
   form; not found → new-borrower fields opened with the ID filled in.
─────────────────────────────────────────────────────────── */
let idQr=null, idScanning=false;

let idLastCode='', idLastAt=0, idLookupBusy=false;

const idStatus=(t,ty)=>setScanStatus('idScanStatus','idScanStatusText',t,ty);

function idInfo(t){ const el=document.getElementById('idScanInfo'); if(el) el.textContent=t||''; }

let idScannedBorrowerId='';

   // borrower the last ID scan selected

function clearIdScan(){
  idStatus('','');
  idInfo('');
  idScannedBorrowerId=''; idLastCode='';
  const inp=document.getElementById('idScanInput'); if(inp) inp.value='';
}

async function startIdScanner(){
  if(idScanning) return;
  if(borrowScanning){                       // the tool scanner owns the camera right now
    try{ await borrowQr.stop(); }catch(_){}
    borrowScanning=false; resetBorrowScannerUI();
  }
  idStatus('Requesting camera access…','scanning');
  try{
    await openCamera(idQr,'idCameraSelect',
      onIdCameraDecode,
      ()=>{
        document.getElementById('idReader').style.display='block';
        document.getElementById('idStartBtn').style.display='none';
        document.getElementById('idStopBtn').style.display='flex';
        brScanLive('id',true);
      },
      // wide, short scan box: 1-D barcodes need width, a square box is for QR only
      {qrbox:(w,h)=>({width:Math.floor(w*.85),height:Math.max(80,Math.floor(Math.min(h*.6,180)))})});
    idScanning=true;
    idStatus('Scanning…','scanning');
  }catch(err){
    // Desktop only: no scanner-camera (or camera blocked on http://) is NOT an error for a USB keyboard-mode scanner.
    if(!isMobileDevice() && ((err&&err.noUsbCamera) || !window.isSecureContext || !navigator.mediaDevices)){
      resetIdScannerUI(); usbReadyMessage(); return;
    }
    idStatus(cameraErrorText(err),'error');
    resetIdScannerUI();
  }
}

function stopIdScannerAsync(){
  if(!idScanning){ resetIdScannerUI(); return Promise.resolve(); }
  return idQr.stop().catch(()=>{}).then(()=>{ idScanning=false; resetIdScannerUI(); });
}

function stopIdScanner(){ stopIdScannerAsync(); }

function resetIdScannerUI(){
  document.getElementById('idReader').style.display='none';
  document.getElementById('idStartBtn').style.display='flex';
  document.getElementById('idStopBtn').style.display='none';
  brScanLive('id',false);
}

        // the same code must repeat within this window
let idPending={code:'',at:0};

function onIdCameraDecode(raw){
  const code=cleanScannedId(raw);
  if(code.length<ID_MIN_CAMERA_LEN){
    idStatus('Scanning… (read "'+code+'" — too short, hold steady and fill the frame)','scanning');
    return;
  }
  // Looks like a full student ID (8–10 digits) → accept on the first read, no waiting.
  if(/^\d{8,10}$/.test(code)){ idPending={code:'',at:0}; handleIdScan(code,'camera'); return; }
  const now=Date.now();
  if(idPending.code===code && now-idPending.at<=ID_CONFIRM_MS){
    idPending={code:'',at:0};
    handleIdScan(code,'camera');
  }else{
    idPending={code,at:now};     // first sighting — wait for a second identical read
  }
}

// One entry point for BOTH paths (camera decode and USB/phone Enter).
async function handleIdScan(raw,source){
  const code=cleanScannedId(raw);
  if(!code) return;
  const now=Date.now();
  if(idLookupBusy) return;                                         // a lookup is already running
  if(code===idLastCode && now-idLastAt<ID_REPEAT_MS) return;       // repeat scan of the same code
  idLastCode=code; idLastAt=now; idLookupBusy=true;
  idStatus('Scanning…','scanning'); idInfo('Looking up '+code+'…');
  try{
    let b;
    if(source==='desktop-usb'){ b=await usbFindBorrower(code); }   // desktop USB scanner: exact id_number match, leading-zero safe
    else{
      const params=new URLSearchParams({page:1,per_page:5,lite:1,id_number:code});
      const rows=(await apiFetch(`${API}/borrowers.php?${params}`)).data||[];
      b=rows[0];
    }
    if(b){
      if(b.is_active==0){
        idStatus('Found, but this borrower is inactive','error');
        idInfo(`${b.full_name} (${b.id_number}) is deactivated — ask an Admin to reactivate.`);
        if(source==='desktop-usb') showToast(`${b.full_name} (${b.id_number}) is inactive — ask an Admin to reactivate.`,'error');
        clearPickedBorrower();                         // never leave the previous borrower selected by mistake
      }else{
        borrowerResultsById.set(String(b.id),b);
        idScannedBorrowerId=String(b.id); 
        selectBorrowerOption(b.id);               // selects them in the Borrow form
        idStatus(b.type==='Student'?'Student found':'Borrower found','success');
        idInfo([b.full_name,b.id_number,b.course?`${b.course} ${b.section_name||''}`.trim():b.type].join(' · '));
      }
      if(source==='camera') stopIdScanner();       // found → release the camera
    }else{
      idStatus('Not found — add them in Borrowers','error');
      idInfo('No borrower with ID '+code+'. Add them on the Borrowers page first.');
      if(source==='desktop-usb') showToast('No borrower found with ID '+code+'.','error');
      clearPickedBorrower();
      if(source==='camera') stopIdScanner();
    }
  }catch(_){
    idStatus('Lookup failed — try again','error');
    idLastCode='';                                 // let them retry the same code right away
  }finally{
    idLookupBusy=false;
  }
}

// Not found: clear any previously picked borrower so a stale one can't be used by mistake.
// (New borrowers are added from the Borrowers page, not the Borrow page.)
function clearPickedBorrower(){
  document.getElementById('borrowerSelect').value='';
  document.getElementById('borrowerSearchInput').value='';
  brPickedBorrower=null; brRenderBorrower();
}

// USB scanner / phone scanner app: they "type" the number, then press Enter.
document.addEventListener('keydown',e=>{
  if(!e.target || e.target.id!=='idScanInput' || e.key!=='Enter') return;
  e.preventDefault();
  const el=e.target, val=el.value;
  el.value='';
  handleIdScan(val,isMobileDevice()?'usb':'desktop-usb');
});

/* ── Desktop USB scanner (keyboard-emulation / "HID wedge") ─────────────────────────────
   The scanner is just a very fast keyboard: it "types" the ID and presses Enter (or Tab).
   It does NOT need to appear as a camera. Works whether or not #idScanInput has focus,
   as long as the Borrow page is open and the user is not typing in another field.
   Desktop only — every handler below returns immediately on phones/tablets. */
const USB_MAX_GAP_MS=80;    // scanners send keys ~5–30 ms apart; a person types slower than this
const USB_MIN_LEN=4;        // shortest burst accepted as an ID
let usbBuf='', usbLastKeyAt=0;

function usbBorrowPageActive(){ const p=document.getElementById('borrowPage'); return !!(p&&p.classList.contains('active')); }

function usbFocusIdInput(){ const el=document.getElementById('idScanInput'); if(el&&!el.disabled) el.focus({preventScroll:true}); }

function usbReadyMessage(){
  idStatus('USB scanner ready — scan the student ID now','scanning');
  idInfo('No camera needed. Scan the ID barcode with the USB scanner.');
  usbFocusIdInput();
}

// Same borrowers API as the camera path; picks the row whose id_number really equals the scan
// (ignores leading zeros / case differences) and prefers an ACTIVE borrower if both exist.
async function usbFindBorrower(code){
  const norm=s=>cleanScannedId(s).replace(/^0+/,'').toLowerCase();
  const stripped=code.replace(/^0+/,'');
  const tries=[code]; if(stripped && stripped!==code) tries.push(stripped);
  for(const q of tries){
    const params=new URLSearchParams({page:1,per_page:20,lite:1,id_number:q});
    const rows=(await apiFetch(`${API}/borrowers.php?${params}`)).data||[];
    const hits=rows.filter(r=>norm(r.id_number)===norm(code));
    if(hits.length){
      const exact=r=>cleanScannedId(r.id_number)===code, active=r=>r.is_active!=0;
      return hits.find(r=>exact(r)&&active(r)) || hits.find(active) || hits.find(exact) || hits[0];
    }
  }
  return null;
}

document.addEventListener('keydown',e=>{
  if(isMobileDevice() || !usbBorrowPageActive() || btBusy) return;
  if(e.ctrlKey||e.metaKey||e.altKey) return;
  const t=e.target, tag=t&&t.tagName;
  if(t&&t.id==='idScanInput') return;                                   // that field has its own Enter handler above
  if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT'||(t&&t.isContentEditable)){ usbBuf=''; return; }   // user is typing elsewhere
  if(document.querySelector('#borrowPage .br-sheet:not([hidden]), dialog[open], .modal-overlay.active')){ usbBuf=''; return; }
  const now=Date.now();
  if(e.key.length===1){                                                 // a character of the scan
    if(now-usbLastKeyAt>USB_MAX_GAP_MS) usbBuf='';                      // slow gap = human typing, start over
    usbBuf+=e.key; usbLastKeyAt=now;
    return;
  }
  if(e.key==='Enter'||e.key==='Tab'){                                   // scanner suffix
    const code=cleanScannedId(usbBuf), fast=(now-usbLastKeyAt)<=USB_MAX_GAP_MS;
    usbBuf='';
    if(code.length>=USB_MIN_LEN && fast){
      e.preventDefault(); e.stopPropagation();                          // stops Enter from "clicking" a focused button
      handleIdScan(code,'desktop-usb');
    }
  }
},true);

/* ── Borrow page: tool picker + Selected Tools cart ─────────────────────────────
   btCatalog = active tools straight from api/tools.php (fresh stock numbers).
   btCart    = [{code,name,category,qty}] - several tools, each with its own quantity.
   The tool picked by QR scan or manual search waits in btPending
   until "Add Tool" puts it in the cart. The scan display is separate from that selection.
   Confirm Borrow sends the whole cart in ONE all-or-nothing request (type=borrow_batch);
   the server re-checks stock on locked rows, so the numbers here are only for guidance. */
let btCatalog=[], btCart=[], btPending=null, btBusy=false;

const btEl=id=>document.getElementById(id);

const btFind=code=>{ const c=String(code||'').trim().toLowerCase(); return c?btCatalog.find(t=>String(t.code).toLowerCase()===c):undefined; };

const btAvail=code=>{
  const t=btFind(code),physical=t?Math.max(0,parseInt(t.available,10)||0):0;
  return physical;
};

// Called when the Borrow page opens and after every borrow: refresh stock, then clamp the cart to it.
// Resolves to true when the list had to be adjusted, false when it was fine, undefined when stock could not be refreshed.
async function loadToolsForBorrow(preserveDraft=false){
  let rows;
  try{ rows=await fetchAllPages(`${API}/tools.php?active_only=1`,{perPage:200,maxPages:100}); }
  catch(_){ return undefined; }             // apiFetch already showed the error
  rows.forEach(t=>{ t.available=Math.max(0,parseInt(t.available,10)||0); });
  btCatalog=rows;
  if(preserveDraft){brRenderToolPreview(true);brRenderOverview();return false;}
  let changed=false;
  btCart=btCart.filter(l=>{ const keep=btAvail(l.code)>0; if(!keep) changed=true; return keep; });
  btCart.forEach(l=>{ const av=btAvail(l.code); if(l.qty>av){ l.qty=av; changed=true; } });
  if(changed) showToast('Stock changed — the Selected Tools list was adjusted.','warning');
  btRender();
  brRenderToolPreview();                    // the picked tool's availability may have moved
  brRenderOverview();
  return changed;
}

function btPick(t,scanned=false){
  btPending=t.code;
  if(!scanned)clearBorrowScanDisplay();
  const si=btEl('borrowToolSearchInput'); if(si) si.value=`${t.name} (${t.code})`;
  const q=btEl('borrowQty');
  q.max=t.available;
  if((parseInt(q.value,10)||1)>t.available) q.value=Math.max(1,t.available);
  const inCart=btCart.find(l=>l.code===t.code);
  btEl('borrowToolSelectHint').textContent=`${t.available} unit(s) available · ${t.category}`+(inCart?` · ${inCart.qty} already in the list`:'');
  brRenderToolPreview();
}

/* ── Searchable "Tool" dropdown: search by tool name or code; out-of-stock tools are greyed out ── */
function openBorrowToolDropdown(){
  const q=btEl('borrowToolSearchInput').value.trim().toLowerCase();
  const list=q?btCatalog.filter(t=>(t.name||'').toLowerCase().includes(q)||(t.code||'').toLowerCase().includes(q)):btCatalog;
  const box=btEl('borrowToolDropdownList');
  if(!list.length){
    box.innerHTML='<div style="padding:10px 14px;color:var(--gray-500,#6b7280);font-size:13px">No matching tools</div>';
  }else{
    box.innerHTML=list.slice(0,50).map(t=>{
      const out=t.available<=0, inCart=btCart.find(l=>l.code===t.code);
      return `<div ${out?'':`data-code="${escapeHtml(t.code)}" onclick="selectBorrowToolOption(this.dataset.code)"`}
            style="padding:10px 14px;font-size:14px;${out?'color:var(--gray-400,#9ca3af);cursor:not-allowed':'cursor:pointer'}"
            ${out?'':`onmouseover="this.style.background='var(--gray-100,#f3f4f6)'" onmouseout="this.style.background=''"`}>
         ${escapeHtml(t.name)} <code style="font-size:11px">${escapeHtml(t.code)}</code> — ${out?'out of stock':t.available+' available'}${inCart?` · <span style="color:var(--primary,#6d28d9)">${inCart.qty} in list</span>`:''}
       </div>`;
    }).join('')+(list.length>50?`<div style="padding:8px 14px;font-size:12px;color:var(--gray-500,#6b7280)">Showing 50 of ${list.length} — type to narrow the list.</div>`:'');
  }
  box.style.display='block';
}

function filterBorrowToolDropdown(){
  // Searching invalidates the prior selection and its scanner display.
  btPending=null;
  clearBorrowScanDisplay();
  btEl('borrowToolSelectHint').textContent='';
  brRenderToolPreview();
  openBorrowToolDropdown();
}

function selectBorrowToolOption(code){
  const t=btFind(code);
  btEl('borrowToolDropdownList').style.display='none';
  if(t) btPick(t);
}

// Called by Camera and Upload QR after decoding a tool code.
function matchBorrowToolCode(code){
  const hint=btEl('borrowToolSelectHint'), si=btEl('borrowToolSearchInput');
  code=(code||'').trim();
  btPending=null;
  if(!code){ if(si)si.value=''; hint.textContent=''; brRenderToolPreview(); return; }
  const t=btFind(code);
  if(!t){ if(si)si.value=''; hint.textContent='⚠ No matching tool found for this code.'; }
  else if(t.available<=0){ if(si)si.value=''; hint.textContent=`⚠ "${t.name}" is out of stock — nothing available to borrow.`; }
  else btPick(t,true);
  brRenderToolPreview();
}

function clearBorrowScanDisplay(){
  btEl('borrowToolId').value='';
  btEl('clearBorrowToolBtn').classList.remove('visible');
  btEl('borrowUploadPreview').style.display='none';
  setScanStatus('borrowScanStatus','borrowScanStatusText','','');
}

function clearBorrowToolId(){
  btPending=null;
  clearBorrowScanDisplay();
  btEl('borrowToolSelectHint').textContent='';
  const si=btEl('borrowToolSearchInput'); if(si) si.value='';
  brRenderToolPreview();
}

/* ── Cart ── */
function btAddFromForm(){
  if(btBusy){ showToast('Saving the borrow — please wait a moment.','warning'); return false; }
  const code=btPending;
  if(!code){ showToast('Scan a QR code or search and select a tool first.','error'); return false; }
  const t=btFind(code);
  if(!t){ showToast(`Tool '${code}' not found.`,'error'); return false; }
  const av=btAvail(t.code);
  if(av<1){ showToast(`"${t.name}" is out of stock.`,'error'); return false; }
  const qty=Number(btEl('borrowQty').value);
  if(!Number.isInteger(qty)||qty<1){ showToast('Quantity must be a positive whole number.','error'); return false; }
  const line=btCart.find(l=>l.code===t.code);
  const want=(line?line.qty:0)+qty;
  if(want>av){
    showToast(`Only ${av} unit(s) of "${t.name}" available`+(line?` (${line.qty} already in the list).`:'.'),'error');
    return false;
  }
  if(line) line.qty=want; else btCart.push({code:t.code,name:t.name,category:t.category,qty});
  btLastAdded=t.code;
  btRender();
  clearBorrowToolId();                       // ready for the next tool
  const q=btEl('borrowQty'); q.value=1; q.removeAttribute('max');
  brSyncQty();
  return true;
}

function btSetQty(code,v){
  if(btBusy) return;
  const line=btCart.find(l=>l.code===code); if(!line) return;
  const av=btAvail(code);
  let n=Number(v);
  if(!Number.isInteger(n)||n<1||n>av){btCartEditPending=true;brSyncBar();showToast(`Enter a whole quantity from 1 to ${av}.`,'error');return;}
  btCartEditPending=false;line.qty=n; btRender();
}

function btStep(code,d){ const line=btCart.find(l=>l.code===code); if(line) btSetQty(code,line.qty+d); }

function btRemove(code){ if(btBusy) return; btCartEditPending=false;btCart=btCart.filter(l=>l.code!==code); btRender(); }

let btLastAdded=null;
let btCartEditPending=false;

                        // the line that was just added gets a short highlight
function btRender(){
  const list=btEl('btCartList'); if(!list) return;
  const types=btCart.length, units=btCart.reduce((s,l)=>s+l.qty,0);
  btEl('btCount').textContent=`${types} tool type${types===1?'':'s'}`;
  btEl('btTotal').textContent=units;
  const badge=btEl('bwCartBadge'); if(badge){ badge.textContent=units; badge.hidden=!units; }
  if(!types){
    list.innerHTML='<div class="empty-state"><i class="fas fa-toolbox"></i><p>No tools selected yet.<br>Scan or search a tool, set the quantity, then press Add Tool.</p></div>';
  }else{
    list.innerHTML=btCart.map(l=>{
      const av=btAvail(l.code), c=escapeHtml(l.code), n=escapeHtml(l.name), lock=btBusy?'disabled':'';
      return `<div class="bt-line${l.code===btLastAdded?' is-new':''}">
      <span class="bt-ico" aria-hidden="true"><i class="fas ${brToolIcon(l)}"></i></span>
      <div class="bt-info"><div class="bt-name">${n}</div><div class="bt-meta">${c} · Available: ${av}</div></div>
      <div class="bt-ctrl">
        <button type="button" class="bt-step" data-code="${c}" onclick="btStep(this.dataset.code,-1)" ${l.qty<=1||btBusy?'disabled':''} aria-label="Decrease quantity of ${n}"><i class="fas fa-minus"></i></button>
        <input type="number" class="bt-qty" data-code="${c}" value="${l.qty}" min="1" max="${av}" inputmode="numeric" oninput="btCartEditPending=true;brSyncBar()" onchange="btSetQty(this.dataset.code,this.value)" ${lock} aria-label="Quantity of ${n}">
        <button type="button" class="bt-step" data-code="${c}" onclick="btStep(this.dataset.code,1)" ${l.qty>=av||btBusy?'disabled':''} aria-label="Increase quantity of ${n}"><i class="fas fa-plus"></i></button>
        <button type="button" class="bt-del" data-code="${c}" onclick="btRemove(this.dataset.code)" ${lock} aria-label="Remove ${n}"><i class="fas fa-trash"></i></button>
      </div>
    </div>`;
    }).join('');
  }
  btLastAdded=null;
  brSyncBar();
}

document.addEventListener('DOMContentLoaded',btRender);

/* ───────────────────────────────────────────────────────────
   BORROW TAB — phone / tablet layer
   Drives only the new UI: scan mode, borrower card, "tool found" card, review step, history, overview.
   Borrowing itself is unchanged: btCart → handleBorrow → api/transactions.php (type=borrow_batch).
─────────────────────────────────────────────────────────── */
const brCompact=()=>window.matchMedia('(max-width:1200px)').matches;

   // phone + tablet layout
const brPhone=()=>window.matchMedia('(max-width:768px)').matches;

      // phone-only parts (full-screen scanner)
const brReduceMotion=()=>window.matchMedia('(prefers-reduced-motion: reduce)').matches;

let brPickedBorrower=null;

                                              // the borrower currently in #borrowerSelect

/* Icons: the inventory has no photos, so a tool gets an icon from its name, then its category. */
const BR_ICON_BY_NAME=[
  [/measur|scale|thermomet|timer|gauge/,'fa-scale-balanced'],
  [/blender|mixer|grinder|processor/,'fa-blender'],
  [/plate|bowl|dish|tray|platter/,'fa-bowl-food'],
  [/cup|mug|tumbler|jug|pitcher|glass|beaker|flask|bottle/,'fa-wine-glass'],
  [/knife|cleaver|fork|spoon|ladle|whisk|spatula|tong|peeler|grater/,'fa-utensils'],
  [/pot|pan|wok|kettle|steamer|stove|burner/,'fa-fire-burner'],
  [/hammer|wrench|screw|plier/,'fa-screwdriver-wrench']
];

const BR_ICON_BY_CAT=[
  [/utensil|cutler/,'fa-utensils'],[/cookware/,'fa-fire-burner'],[/measur/,'fa-scale-balanced'],
  [/dinnerware/,'fa-bowl-food'],[/glass/,'fa-wine-glass']
];

function brToolIcon(t){
  const name=String(t&&t.name||'').toLowerCase(), cat=String(t&&t.category||'').toLowerCase();
  for(const [re,ic] of BR_ICON_BY_NAME) if(re.test(name)) return ic;
  for(const [re,ic] of BR_ICON_BY_CAT) if(re.test(cat)) return ic;
  return 'fa-toolbox';
}

function brScrollTo(id){
  const el=btEl(id); if(!el||el.hidden) return;
  el.scrollIntoView({behavior:brReduceMotion()?'auto':'smooth',block:'start'});
}

const brFocusSection=id=>{ if(brCompact()) brScrollTo(id); };

/* ── Borrower information card ── */
function brBorrowerHtml(b,withClear){
  const course=b.course?`${b.course} ${b.section_name||''}`.trim():'';
  const loans=parseInt(b.active_borrows,10)||0;
  return `<span class="br-avatar" aria-hidden="true">${escapeHtml(getInitials(b.full_name))}</span>
    <div class="br-bcard-main"><b>${escapeHtml(b.full_name)}</b>
      <small>${escapeHtml([b.id_number,course].filter(Boolean).join(' · '))}</small>
      ${loans?`<small class="br-loans">${loans} active loan${loans===1?'':'s'}</small>`:''}</div>
    <span class="br-type">${escapeHtml(b.type||'Borrower')}</span>
    ${withClear?'<button type="button" class="br-bcard-x" onclick="brClearBorrower()" aria-label="Change borrower"><i class="fas fa-xmark"></i></button>':''}`;
}

function brCurrentBorrower(){
  const id=btEl('borrowerSelect').value;
  return (id&&brPickedBorrower&&String(brPickedBorrower.id)===String(id))?brPickedBorrower:null;
}

function brRenderBorrower(){
  const card=btEl('brBorrowerCard'); if(!card) return;
  const b=brCurrentBorrower();
  btEl('brSecBorrower').classList.toggle('has-borrower',!!b);
  if(b){ card.innerHTML=brBorrowerHtml(b,true); card.hidden=false; }
  else { card.hidden=true; card.innerHTML=''; }
  brSyncBar();
}

function brClearBorrower(){
  if(btBusy) return;
  clearIdScan(); clearPickedBorrower();
  const i=btEl('borrowerSearchInput'); if(i) i.focus();
}

/* ── "Tool found" card + quantity stepper ── */
function brQtyMax(){                         // units still allowed for the picked tool (stock minus what is already in the list)
  const t=btPending?btFind(btPending):null; if(!t) return null;
  const inCart=btCart.find(l=>l.code===t.code);
  return Math.max(0,btAvail(t.code)-(inCart?inCart.qty:0));
}

function brSyncQty(){
  const q=btEl('borrowQty'); if(!q) return;
  const n=parseInt(q.value,10)||1, max=brQtyMax();
  btEl('borrowQtyDec').disabled=btBusy||n<=1;
  btEl('borrowQtyInc').disabled=btBusy||(max!==null&&n>=max);
}

function brQtyClamp(){
  const q=btEl('borrowQty'), max=brQtyMax();
  let n=parseInt(q.value,10); if(!n||n<1) n=1;
  if(max!==null&&max>=1&&n>max) n=max;
  q.value=n; brSyncQty();
}

function brQtyStep(d){
  const q=btEl('borrowQty'); q.value=(parseInt(q.value,10)||1)+d; brQtyClamp();
}

function brRenderToolPreview(preserveDraft=false){
  const box=btEl('brToolPreview'); if(!box) return;
  const t=btPending?btFind(btPending):null;
  btEl('brSecTool').classList.toggle('has-tool',!!t);
  if(!t){ box.hidden=true; if(!preserveDraft)brSyncQty(); brSyncBar(); return; }
  const inCart=btCart.find(l=>l.code===t.code), av=btAvail(t.code);
  btEl('brToolIco').className='fas '+brToolIcon(t);
  btEl('brToolName').textContent=t.name;
  btEl('brToolMeta').textContent=`${t.code}${t.category?' · '+t.category:''}${inCart?` · ${inCart.qty} already in the list`:''}`;
  const el=btEl('brToolAvail'); el.textContent=`${av} available`; el.classList.toggle('low',av<=(parseInt(t.min_stock,10)||0));
  box.hidden=false;
  if(!preserveDraft)brQtyClamp(); brSyncBar();
}

/* ── Sticky confirm bar summary ── */
function brSyncBar(){
  const cnt=btEl('brBarCount'), who=btEl('brBarWho'); if(!cnt||!who) return;
  const units=btCart.reduce((s,l)=>s+l.qty,0);
  cnt.textContent=units?`${units} item${units===1?'':'s'} · ${btCart.length} tool${btCart.length===1?'':'s'}`:(btPending?'1 tool ready to add':'No tools yet');
  const b=brCurrentBorrower();
  who.textContent=b?b.full_name:'Select a borrower';
  const pending=!!(btPending||btEl('borrowToolId').value.trim());
  const btn=btEl('borrowSubmitBtn');if(btn)btn.disabled=btBusy||!units||pending||btCartEditPending||!b;
  if(pending)cnt.textContent='Press Add Tool to include the entered quantity';
  if(btCartEditPending)cnt.textContent='Finish editing the selected quantity, then review';
}

/* ── Overview (phone / tablet home) ── */
function brRenderOverview(){
  const a=btEl('bwAvail'); if(!a) return;
  a.textContent=btCatalog.reduce((s,t)=>s+(t.available||0),0);
  btEl('bwBorrowed').textContent=btCatalog.reduce((s,t)=>s+Math.max(0,parseInt(t.borrowed_qty,10)||0),0);
}

async function brLoadOverdue(){               // quiet on purpose: a failed counter should not raise a toast
  try{
    const r=await fetch(`${API}/transactions.php?${new URLSearchParams({type:'borrow',status:'overdue',page:1,per_page:1})}`);
    const j=await r.json();
    if(j&&j.success&&btEl('bwOverdue')) btEl('bwOverdue').textContent=j.total||0;
  }catch(_){}
}

function brOnOpen(){
  const d=btEl('borrowDueDate'); if(d) d.min=localToday();
  brLoadOverdue(); brRenderBorrower(); brRenderToolPreview();
  if(!isMobileDevice()) usbFocusIdInput();     // desktop: ready for the USB scanner as soon as the tab opens
}

/* ── Phone scan mode: one scanner screen with a Tool QR / ID QR switch ──
   The two real scanners (borrowQr / idQr) and their reader elements are untouched; while one is running the
   page shows #brScan around it (CSS, phones only). Tablets and desktops keep the inline scanner zones. */
let brScanSwitching=false;

function brLiveZone(){ return document.querySelector('#borrowPage .scanner-zone.is-live'); }

function brSyncLock(){
  const sheet=document.querySelector('#borrowPage .br-sheet:not([hidden])');
  document.body.classList.toggle('br-lock',!!(sheet||(brCompact()&&brLiveZone())));
}

function brSyncScanChrome(){
  const chrome=btEl('brScan'), live=brLiveZone();
  if(!chrome) return;
  chrome.classList.toggle('on',!!live);
  chrome.setAttribute('aria-hidden',live?'false':'true');
  if(live){
    const id=live.id==='idScanZone';
    btEl('brSegTool').classList.toggle('on',!id); btEl('brSegTool').setAttribute('aria-pressed',String(!id));
    btEl('brSegId').classList.toggle('on',id);    btEl('brSegId').setAttribute('aria-pressed',String(id));
    btEl('brScanUpload').hidden=id;
  }
  brSyncLock();
}

function brScanLive(which,on){
  const zone=btEl(which==='id'?'idScanZone':'toolScanZone');
  if(zone) zone.classList.toggle('is-live',on);
  if(on||!brScanSwitching) brSyncScanChrome();                // while switching, keep the screen up instead of flickering
  if(!on&&!brLiveZone()&&brCompact()&&!brScanSwitching){        // scanner finished: bring the result into view
    requestAnimationFrame(()=>brScrollTo(which==='id'?(brCurrentBorrower()?'brBorrowerCard':'brSecBorrower'):(btPending?'brToolPreview':'brSecTool')));
  }
}

function brMirrorStatus(text,type){
  const m=btEl('brScanMirror'); if(!m) return;
  m.className='br-scan-status'+(type?' '+type:''); m.textContent=text||''; m.hidden=!text;
}

async function brSwitchScan(which){
  if(brScanSwitching) return;
  const cur=btEl('idScanZone').classList.contains('is-live')?'id':(btEl('toolScanZone').classList.contains('is-live')?'tool':null);
  if(cur===which) return;
  brScanSwitching=true;
  try{ if(which==='id') await startIdScanner(); else await startBorrowScanner(); }
  finally{ brScanSwitching=false; brSyncScanChrome(); }
}

function brScanClose(){ stopBorrowScanner(); stopIdScanner(); }

function brQuick(what){
  if(btBusy) return;
  if(what==='cart'){ brScrollTo('borrowCartCard'); return; }
  if(what==='id'){ if(!brCompact()) brScrollTo('idScanZone'); startIdScanner(); return; }
  if(!brCompact()) brScrollTo('toolScanZone');
  startBorrowScanner();
}

/* ── Full-screen sheets (review / history) ── */
let brSheetOpener=null;

function brOpenSheet(id){
  const s=btEl(id); if(!s||!s.hidden) return;
  brSheetOpener=document.activeElement;
  s.hidden=false; brSyncLock();
  (s.querySelector('.br-sheet-x')||s).focus();
}

function brCloseSheet(id){
  const s=btEl(id); if(!s||s.hidden) return;
  s.hidden=true; brSyncLock();
  if(brSheetOpener&&document.contains(brSheetOpener)&&!brSheetOpener.disabled) brSheetOpener.focus();
  brSheetOpener=null;
}

function brCloseSheets(){ ['brReview','brHistory'].forEach(id=>{ const s=btEl(id); if(s) s.hidden=true; }); brSyncLock(); }

document.addEventListener('keydown',e=>{
  const sheet=document.querySelector('#borrowPage .br-sheet:not([hidden])');
  if(e.key==='Escape'){
    if(sheet){ if(!(btBusy&&sheet.id==='brReview')) brCloseSheet(sheet.id); e.preventDefault(); }
    else if(brCompact()&&brLiveZone()) brScanClose();
    return;
  }
  if(e.key==='Tab'&&sheet){                   // keep Tab inside the open sheet
    const f=[...sheet.querySelectorAll('button:not([disabled]),input:not([disabled])')].filter(el=>el.offsetParent!==null);
    if(!f.length) return;
    const first=f[0], last=f[f.length-1];
    if(e.shiftKey&&document.activeElement===first){ e.preventDefault(); last.focus(); }
    else if(!e.shiftKey&&document.activeElement===last){ e.preventDefault(); first.focus(); }
  }
});

/* ── Review step ── */
function brFormatDue(date,time){
  if(!date) return '—';
  const [year,month,day]=date.split('-');
  const numeric=`${month}/${day}/${year}`;
  if(!time) return numeric;
  const [hour,minute]=time.split(':');
  const h=Number(hour);
  return `${numeric} ${h%12||12}:${minute} ${h>=12?'PM':'AM'}`;
}

function brOpenReview(){
  const b=brCurrentBorrower(), due=btEl('borrowDueDate').value, notes=btEl('borrowNotes').value.trim();
  btEl('brReviewBorrower').innerHTML=b?brBorrowerHtml(b,false):'';
  btEl('brReviewCount').textContent=`${btCart.length} tools, ${btCart.reduce((n,l)=>n+l.qty,0)} units`;
  btEl('brReviewItems').innerHTML=btCart.map(l=>`<div class="br-ritem">
      <span class="bt-ico" aria-hidden="true"><i class="fas ${brToolIcon(l)}"></i></span>
      <div class="bt-info"><div class="bt-name">${escapeHtml(l.name)}</div><div class="bt-meta">${escapeHtml(l.code)}</div></div>
      <b class="br-rq">×${l.qty}</b></div>`).join('');
  btEl('brReviewDue').textContent=brFormatDue(due,btEl('borrowDueTime').value);
  btEl('brReviewNotes').textContent=notes||'—';
  brOpenSheet('brReview');
}

function brCloseReview(){ if(!btBusy) brCloseSheet('brReview'); }

/* ── History (same endpoint as Reports → Transaction Report) ── */
const brHist={rows:[],total:0,range:'all',loaded:false};

async function brOpenHistory(){
  brOpenSheet('brHistory');
  const list=btEl('brHistList');
  list.innerHTML='<div class="br-hempty"><span class="spinner dark"></span> Loading…</div>';
  try{
    const res=await apiFetch(`${API}/transactions.php?${new URLSearchParams({type:'borrow',grouped:1,status:'',page:1,per_page:100})}`);
    brHist.rows=res.data||[]; brHist.total=res.total||0; brHist.loaded=true;
    mtRememberLoans(brHist.rows.flatMap(g=>g.items||[]));
  }catch(_){
    list.innerHTML='<div class="br-hempty">Could not load the history. Close this screen and try again.</div>'; return;
  }
  brHistRender();
}

function brHistRange(r){
  brHist.range=r;
  document.querySelectorAll('#brHistChips button').forEach(b=>{ const on=b.dataset.r===r; b.classList.toggle('on',on); b.setAttribute('aria-pressed',String(on)); });
  brHistRender();
}

function brHistRender(){
  if(!brHist.loaded) return;
  const q=btEl('brHistSearch').value.trim().toLowerCase();
  const now=new Date(), day=new Date(now.getFullYear(),now.getMonth(),now.getDate());
  const since={all:null,today:day,week:new Date(day.getTime()-((day.getDay()+6)%7)*864e5),month:new Date(now.getFullYear(),now.getMonth(),1)}[brHist.range];
  const rows=brHist.rows.filter(t=>{
    if(since&&parseDbDate(t.created_at)<since) return false;
    return !q||[t.borrower,t.txn_id,...(t.items||[]).flatMap(l=>[l.tool_name,l.tool_code,l.borrower_id_number_snapshot])].some(v=>String(v||'').toLowerCase().includes(q));
  });
  const list=btEl('brHistList');
  if(!rows.length){ list.innerHTML='<div class="br-hempty">No borrows match.</div>'; return; }
  list.innerHTML=rows.map(t=>`<details class="br-hrow" data-borrow-session="${escapeHtml(t.group_key)}" ${borrowHistoryExpanded.has(t.group_key)?'open':''} ontoggle="this.open?borrowHistoryExpanded.add(this.dataset.borrowSession):borrowHistoryExpanded.delete(this.dataset.borrowSession)">
      <summary><span class="br-hrow-top">${loanStateBadge(t)}<code>${escapeHtml(t.txn_id)}</code></span><span class="br-hrow-main">${Number(t.tools_count)} tool type(s) · ${Number(t.qty)} units</span></summary>
      <div class="br-hrow-sub"><i class="fas fa-user"></i>${escapeHtml(t.borrower||'—')}</div>
      <div class="br-hrow-sub"><i class="fas fa-clock"></i>${escapeHtml(mtFmtDT(t.created_at))}${t.due_date?` · Due ${escapeHtml(brFormatDue(t.due_date,t.due_time))}`:''}</div>
      ${(t.items||[]).map(l=>`<div class="br-hrow-main"><b>${escapeHtml(l.tool_name||l.tool_code||'Historical tool')}</b><span>${escapeHtml(l.tool_code||'')}</span><small>Borrowed ${Number(l.qty)} · Returned ${Number(l.qty_returned)} · Missing ${Number(l.qty_missing)} · Returned damaged ${Number(l.qty_damaged)}</small>${loanStateBadge(l)}</div>`).join('')}
    </details>`).join('')+(brHist.total>brHist.rows.length?`<p class="br-hmore">Showing the latest ${brHist.rows.length} of ${brHist.total} checkouts. Open the full report for everything.</p>`:'');
}

function brHistFull(){ brCloseSheets(); navigateTo('reports'); viewReport('transactions','Transactions'); }

/* ───────────────────────────────────────────────────────────
   10. BORROW / RETURN SUBMISSION
   POST api/transactions.php
   Borrow body: { type:'borrow_batch', borrower_id, due_date, notes, items:[{tool_code, qty}] }
                → { success, data:{ items:[{id, txn_id, tool_name, tool_code, qty}], tools_count, total_units } }
   (the old single { type:'borrow', tool_code, qty } request is still supported by the API)
─────────────────────────────────────────────────────────── */

// Confirm Borrow button. Phones / tablets get a review step first; desktop submits straight away, as it always did.
function borrowConfirm(){
  if(btBusy) return;
  if(!btCollect()) return;
  brOpenReview();
}

// Reads + validates the form. Returns {borrowerId,due,notes}, or null after telling the person what is missing.
function btCollect(){
  const edited=document.querySelector('#btCartList input.bt-qty:focus');
  if(edited){edited.blur();}
  if(btCartEditPending){showToast('Finish editing the selected quantity before reviewing.','warning');return null;}
  const borrowerId=btEl('borrowerSelect').value;
  const due=btEl('borrowDueDate').value;
  const dueTime=btEl('borrowDueTime').value;
  const notes=btEl('borrowNotes').value.trim();

  // A tool that is picked but not added yet still counts, so borrowing a single tool works as it always did.
  if(btPending || btEl('borrowToolId').value.trim()){ showToast('Press Add Tool before reviewing this borrow.','warning'); return null; }
  if(!btCart.length){ showToast('Add at least one tool: scan a QR code or search, then press Add Tool.','error'); brFocusSection('brSecTool'); return null; }
  if(!borrowerId){ showToast('Please select a borrower (search or scan their ID).','error'); brFocusSection('brSecBorrower'); return null; }
  if(!due){ showToast('Please select a due date.','error'); brFocusSection('brSecLoan'); return null; }
  if(due<localToday()){ showToast('The due date cannot be in the past.','error'); brFocusSection('brSecLoan'); return null; }
  if(!/^([01]\d|2[0-3]):[0-5]\d$/.test(dueTime)){ showToast('Please select a valid due time.','error'); brFocusSection('brSecLoan'); return null; }
  return {borrowerId,due,dueTime,notes};
}

// While a borrow is being saved nothing else can change or be pressed (a second tap, Enter, or the other Confirm button).
function brSetBusy(on){
  setLoading('borrowSubmitBtn',on);
  setLoading('brReviewConfirm',on);
  ['borrowToolId','borrowToolSearchInput','borrowerSearchInput','idScanInput','borrowDueDate','borrowDueTime','borrowNotes','borrowQty','borrowAddBtn','brReviewCancel']
    .forEach(id=>{ const el=btEl(id); if(el) el.disabled=on; });
  btEl('brBar').classList.toggle('is-busy',on);
  btRender(); brSyncQty();
}

// The server decides the outcome; if the connection dropped after sending we cannot know whether it was saved.
function brNoteUncertain(err){
  const msg=String(err&&err.message||'');
  if(err instanceof TypeError||/^Server error|failed to fetch|network/i.test(msg)){
    showToast('Confirmation was interrupted. Retry the same selection; its submission token prevents a second loan.','warning');
  }
}

const btRetryTokens=new Map();
async function handleBorrow(){
  if(btBusy) return;                          // double tap / double Enter / the other Confirm button
  const req=btCollect(); if(!req) return;

  btBusy=true; brSetBusy(true);
  try{
    let d, stop=false;
    try{
      // 1. Fresh stock first. If the numbers moved since the page loaded, nothing is sent: the list is adjusted and re-checked by the person.
      const changed=false; // The server validates locked stock; keep retries identical after a lost response.
      if(changed===true){ showToast('Nothing was saved. Review the list, then confirm again.','warning'); stop=true; }
      else if(!btCart.length){ stop=true; }
      else{
        // 2. ONE all-or-nothing request. The server re-checks stock on locked rows, so these numbers are only guidance.
        const payload={
          type:'borrow_batch', borrower_id:parseInt(req.borrowerId,10), due_date:req.due, due_time:req.dueTime, notes:req.notes,
          override_reason:document.getElementById('borrowOverrideReason')?.value||'',
          items:btCart.map(l=>({tool_code:l.code,qty:l.qty}))
        };
        const key=JSON.stringify(payload);
        if(!btRetryTokens.has(key))btRetryTokens.set(key,crypto.randomUUID().replaceAll('-',''));
        payload.request_token=btRetryTokens.get(key);
        d=(await apiFetch(`${API}/transactions.php`,{method:'POST',body:JSON.stringify(payload)})).data;
        btRetryTokens.delete(key);
      }
    }catch(err){
      // Nothing was saved (all-or-nothing). Keep the list so it can be fixed, and refresh the stock numbers.
      brCloseSheet('brReview'); brNoteUncertain(err);
      return;
    }
    if(stop){ brCloseSheet('brReview'); return; }

    showToast(`Borrow recorded — ${d.tools_count} tool type${d.tools_count===1?'':'s'}, ${d.total_units} unit${d.total_units===1?'':'s'}`,'success');
    brCloseSheet('brReview');
    if(document.getElementById('borrowOverrideReason'))document.getElementById('borrowOverrideReason').value='';
    btCart=[]; btRender();
    clearBorrowToolId();
    clearPickedBorrower();
    clearIdScan();
    idInfo(''); idStatus('','');
    btEl('borrowNotes').value='';
    loadBorrowHistory();
    loadToolsForBorrow();                     // availability just changed
    brLoadOverdue();
    loadNotifications();
    if(brPhone()) window.scrollTo({top:0,behavior:brReduceMotion()?'auto':'smooth'});
  }finally{ btBusy=false; brSetBusy(false); }
}

/* Scanner + due-date setup for the Borrow page (was part of the single window.load bootstrap). */
window.addEventListener('load',()=>{
  borrowQr=new Html5Qrcode('borrowReader');
  const F=(typeof Html5QrcodeSupportedFormats!=='undefined')?Html5QrcodeSupportedFormats:null;
  idQr=new Html5Qrcode('idReader',F?{
    // Only the formats student IDs normally use. ITF / Codabar / Code 93 / UPC-E are left out on purpose:
    // they have weak start/stop checks, so a software decoder (iPhone Safari) can "read" a short fragment like "46".
    formatsToSupport:[F.QR_CODE,F.CODE_128,F.CODE_39,F.EAN_13],
    useBarCodeDetectorIfSupported:true
  }:undefined);
  // Deliberately NOT calling loadCameras() here — Html5Qrcode.getCameras()
  // triggers the browser's camera permission prompt, and we only want
  // that happening when the user actually presses "Camera" (see
  // startBorrowScanner), not on every page load.
  resetCameraSelects();
  // Plugging / unplugging a USB scanner → force a fresh camera scan next time
  if(navigator.mediaDevices && navigator.mediaDevices.addEventListener){
    navigator.mediaDevices.addEventListener('devicechange',()=>{
      if(!isMobileDevice() && !borrowScanning && !idScanning && !returnIdScanning) resetCameraSelects();
    });
  }
  const due=new Date(Date.now()+24*60*60*1000);
  document.getElementById('borrowDueDate').valueAsDate=due;
});

// A contained mobile time picker keeps its actions inside the viewport.
function brOpenDueTime(){
  const field=document.getElementById('borrowDueTime');
  if(field.disabled)return;
  const [hour,minute]=(field.value||'17:00').split(':').map(Number);
  document.getElementById('brTimeHour').value=String(hour%12||12);
  document.getElementById('brTimeMinute').value=String(minute);
  document.getElementById('brTimePeriod').value=hour>=12?'PM':'AM';
  document.getElementById('brTimePicker').showModal();
}
function brSaveDueTime(){
  const hour=Number(document.getElementById('brTimeHour').value)%12+(document.getElementById('brTimePeriod').value==='PM'?12:0);
  const minute=Number(document.getElementById('brTimeMinute').value);
  const field=document.getElementById('borrowDueTime');
  field.value=`${String(hour).padStart(2,'0')}:${String(minute).padStart(2,'0')}`;
  field.dispatchEvent(new Event('input',{bubbles:true}));
  field.dispatchEvent(new Event('change',{bubbles:true}));
  document.getElementById('brTimePicker').close();
}
document.addEventListener('DOMContentLoaded',()=>{
  const field=document.getElementById('borrowDueTime');
  const mobile=window.matchMedia('(max-width:768px)');
  const sync=()=>{field.readOnly=mobile.matches;if(!mobile.matches)document.getElementById('brTimePicker').close();};
  sync();mobile.addEventListener('change',sync);
  document.getElementById('brTimeHour').innerHTML=Array.from({length:12},(_,i)=>`<option value="${i+1}">${String(i+1).padStart(2,'0')}</option>`).join('');
  document.getElementById('brTimeMinute').innerHTML=Array.from({length:60},(_,i)=>`<option value="${i}">${String(i).padStart(2,'0')}</option>`).join('');
  field.addEventListener('click',event=>{if(mobile.matches){event.preventDefault();brOpenDueTime();}});
  field.addEventListener('keydown',event=>{if(mobile.matches&&(event.key==='Enter'||event.key===' ')){event.preventDefault();brOpenDueTime();}});
});
