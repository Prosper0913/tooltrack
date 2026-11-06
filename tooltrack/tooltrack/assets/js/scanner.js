/* ============================================================
   scanner.js — Shared camera / QR / barcode helpers used by BOTH Borrow and Return scanners.
   Camera policy (phone = rear camera only, PC = external USB scanner only), openCamera(), ID cleanup + misread guards.
   Plain (non-module) script: shares the global scope with the other
   assets/js files so inline onclick="" handlers keep working.
   ============================================================ */

function setScanStatus(statusId,textId,text,type){
  const el=document.getElementById(statusId);
  el.className='scan-status '+(type||'');
  el.style.display=type?'flex':'none';
  if(textId)document.getElementById(textId).textContent=text;
  if(statusId==='borrowScanStatus'||statusId==='idScanStatus') brMirrorStatus(text,type);   // phone scan mode shows the same message
}

/* ── Camera policy ──────────────────────────────────────────
   • PHONE / TABLET : rear ("environment") camera ONLY. The front camera
                      is never used and the camera dropdown is hidden.
   • PC / LAPTOP    : the built-in webcam is NOT used. The camera only
                      works when an external USB scanner-camera is
                      plugged in (it shows up as an extra video device).
   Tune the two patterns below if your scanner/webcam has an unusual
   name — the detected labels are printed in the browser console
   ("[camera] detected devices").                                   */
const CAMERA_POLICY = {
  // Desktop: labels treated as built-in / virtual cameras → ignored
  builtIn: /integrated|built-?in|internal|facetime|isight|truevision|macbook|imac|surface|ir camera|infrared|windows hello|laptop|virtual|obs|snap camera|manycam|droidcam|epoccam|iriun|camo/i,
  // Desktop: labels of known scanner cameras → always allowed
  scanner: /scan|barcode|qr|symbol|zebra|honeywell|datalogic|newland|netum|eyoyo|opticon|unitech|mindeo/i,
  // Mobile: label hints when facingMode is not supported
  rear: /back|rear|environment/i,
  front: /front|user|selfie|facetime/i,
  // Desktop: false = NEVER touch any camera on a PC/laptop (USB keyboard-mode scanners only). Phones are unaffected.
  desktopCamera: false
};

function isMobileDevice(){
  if(navigator.userAgentData && navigator.userAgentData.mobile===true) return true;
  const ua=navigator.userAgent||'';
  if(/Android|iPhone|iPad|iPod|Mobile|Windows Phone/i.test(ua)) return true;
  // "Desktop site" mode hides the phone from the user agent, so also trust the hardware:
  // a touch screen whose main pointer is a finger is a phone/tablet, not a PC with a webcam.
  try{
    if(navigator.maxTouchPoints>0 && window.matchMedia && window.matchMedia('(pointer:coarse)').matches) return true;
  }catch(_){}
  // iPadOS 13+ pretends to be a Mac; touch support gives it away
  return /Macintosh/i.test(ua) && navigator.maxTouchPoints>1;
}

function isExternalScannerCamera(dev){
  const label=dev.label||'';
  if(CAMERA_POLICY.scanner.test(label)) return true;
  return !CAMERA_POLICY.builtIn.test(label);
}

function setCameraFieldVisible(selectId,show){
  const field=document.getElementById(selectId.replace('Select','Field'));
  if(field) field.style.display=show?'block':'none';
}

function resetCameraSelects(){
  ['borrowCameraSelect','idCameraSelect','returnIdCameraSelect'].forEach(id=>{
    const sel=document.getElementById(id); if(!sel) return;
    sel.innerHTML='<option value="">Press \'Camera\' to enable</option>';
    setCameraFieldVisible(id,false);
  });
}

function cameraErrorText(err){
  return String((err && (err.message||err.name)) || err || 'Camera access failed.');
}

/* Desktop only: list cameras, drop built-in webcams, fill the dropdown. */
function loadCameras(selectId){
  return Html5Qrcode.getCameras().then(devices=>{
    console.info('[camera] detected devices:', (devices||[]).map(d=>d.label||d.id));
    const sel=document.getElementById(selectId);
    sel.innerHTML='';
    const allowed=(devices||[]).filter(isExternalScannerCamera);
    if(!allowed.length){
      sel.innerHTML='<option value="">No USB scanner camera detected</option>';
      setCameraFieldVisible(selectId,false);
      return allowed;
    }
    allowed.forEach((d,i)=>{const o=document.createElement('option');o.value=d.id;o.textContent=d.label||`Scanner camera ${i+1}`;sel.appendChild(o);});
    setCameraFieldVisible(selectId,allowed.length>1);
    return allowed;
  }).catch(err=>{
    // Surface the REAL reason — on a desktop this is very often the page
    // being loaded over http:// from something other than localhost
    // (camera access needs https:// or http://localhost).
    const msg=String(err && (err.message||err)) || 'Unknown error';
    const hint = window.isSecureContext ? msg : `Camera blocked: this page must be opened via https:// or http://localhost — "${location.hostname}" doesn't qualify for camera access in most browsers.`;
    document.getElementById(selectId).innerHTML=`<option value="">${window.isSecureContext?'Camera error: '+msg:'Not a secure context'}</option>`;
    console.error('Camera enumeration failed:', hint);
    throw new Error(hint);
  });
}

/* Mobile fallback: find the rear camera by label (never the front one). */
async function findRearCameraId(){
  const devices=await Html5Qrcode.getCameras();
  const list=devices||[];
  let rear=list.find(d=>CAMERA_POLICY.rear.test(d.label||'') && !CAMERA_POLICY.front.test(d.label||''));
  // Some phones give no usable labels: take the LAST camera that is not labelled "front"
  // (the front camera is normally listed first; the last entry is the main rear one).
  if(!rear){
    const notFront=list.filter(d=>!CAMERA_POLICY.front.test(d.label||''));
    if(notFront.length) rear=notFront[notFront.length-1];
  }
  if(!rear) throw new Error('No rear camera found on this device.');
  return rear.id;
}

/* Starts the camera according to the policy above.
   beforeStart() is called once a camera source is known (used to show the viewport). */
async function openCamera(qr,selectId,onDecode,beforeStart,cfgExtra={}){
  // PC / laptop: camera disabled — stop here, before getCameras()/getUserMedia can open the webcam or ask for permission.
  if(!isMobileDevice() && !CAMERA_POLICY.desktopCamera){
    const off=new Error('Camera is turned off on PC/laptop. Use the USB scanner (it types the code), or open this page on a phone to use the camera.');
    off.noUsbCamera=true;   // Borrow page turns this into "USB scanner ready" instead of an error
    throw off;
  }
  if(!window.isSecureContext || !navigator.mediaDevices){
    throw new Error('Camera blocked: this page must be opened via https:// or http://localhost.');
  }
  const scanCfg={fps:15,qrbox:(w,h)=>{const s=Math.min(w,h)*.7;return{width:Math.floor(s),height:Math.floor(s)};},...cfgExtra};
  const onFail=()=>{};

  if(isMobileDevice()){
    setCameraFieldVisible(selectId,false);          // no camera choice on phones
    if(beforeStart) beforeStart();
    try{
      // "exact" = rear camera or fail — it will never fall back to the front cam
      await qr.start({facingMode:{exact:'environment'}},scanCfg,onDecode,onFail);
    }catch(err){
      if(err && (err.name==='NotAllowedError' || /permission|denied|notallowed/i.test(String(err)))) throw err;
      const id=await findRearCameraId();            // some browsers reject facingMode
      await qr.start(id,scanCfg,onDecode,onFail);
    }
    return;
  }

  // PC / laptop → only an external USB scanner camera is accepted
  const sel=document.getElementById(selectId);
  if(!sel.value){
    const allowed=await loadCameras(selectId);
    if(!allowed.length){
      const noCam=new Error('No USB scanner camera detected. Plug in the scanner and press Camera again (the laptop webcam is disabled).');
      noCam.noUsbCamera=true;   // desktop only: lets the Borrow page fall back to USB keyboard-mode scanning
      throw noCam;
    }
  }
  if(beforeStart) beforeStart();
  try{
    await qr.start(sel.value,scanCfg,onDecode,onFail);
  }catch(err){
    sel.innerHTML='<option value="">Press \'Camera\' to enable</option>'; // force re-detect next time
    throw err;
  }
}

const ID_REPEAT_MS=2500;

   // ignore the same code scanned again within this window

// Same cleanup as the borrower form (dashes/spaces), plus any other stray
// characters a scanner can add (code-39 "*" markers, prefixes, CR/LF).
function cleanScannedId(raw){ return String(raw||'').replace(/[^0-9A-Za-z]/g,''); }

// Camera-only misread guard. Phone cameras can decode a fragment of a barcode (e.g. "46"),
// so a camera read must (1) be long enough to be an ID and (2) be read twice in a row.
const ID_MIN_CAMERA_LEN=6;

       // shortest ID accepted from the camera — lower it if your IDs are shorter
const ID_CONFIRM_MS=2000;
