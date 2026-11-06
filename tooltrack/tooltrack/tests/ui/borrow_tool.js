(async()=>{
 const checks=[],check=(value,label)=>{if(!value)throw Error(label);checks.push(label);};
 try{
  new Function(fixture.source);check(true,'Borrow script syntax');
  const shell=new DOMParser().parseFromString(fixture.shell,'text/html');
  document.body.append(document.importNode(shell.getElementById('brSecTool'),true));
  const el=id=>document.getElementById(id),scan=el('borrowToolId'),search=el('borrowToolSearchInput');
  const extract=name=>{let start=fixture.source.indexOf('async function '+name+'(');if(start<0)start=fixture.source.indexOf('function '+name+'(');return fixture.source.slice(start,fixture.source.indexOf('\n}',start)+2);};
  const picker=fixture.source.slice(fixture.source.indexOf('let btCatalog='),fixture.source.indexOf('\nfunction btSetQty('));
  let cameraSuccess,uploadCode='TOOL-A',messages=[];
  const api=new Function('document','openCamera','Html5Qrcode','setScanStatus','showToast','escapeHtml',`
   let borrowQr={},borrowScanning=false,idScanning=false,btLastAdded=null;
   function brRenderToolPreview(){} function brSyncQty(){} function btRender(){}
   function brScanLive(){} function stopBorrowScanner(){borrowScanning=false;}
   function resetBorrowScannerUI(){} function cameraErrorText(e){return e.message;}
   ${picker}
   ${extract('startBorrowScanner')}
   ${extract('borrowScanUploadedImage')}
   return {startBorrowScanner,borrowScanUploadedImage,selectBorrowToolOption,filterBorrowToolDropdown,openBorrowToolDropdown,clearBorrowToolId,btAddFromForm,
    catalog:rows=>btCatalog=rows,cart:()=>btCart,pending:()=>btPending};
  `)(document,async(q,id,success)=>{cameraSuccess=success;},class{scanFile(){return Promise.resolve(uploadCode);}},(id,textId,text)=>{el(textId).textContent=text;},message=>messages.push(message),s=>String(s));
  Object.assign(window,api);
  api.catalog([{id:1,code:'TOOL-A',name:'Whisk',category:'Utensils',available:5},{id:2,code:'TOOL-B',name:'Bowl',category:'Cookware',available:8}]);
  check(scan.readOnly&&!scan.disabled,'Scan field uses native readonly and remains focusable');
  check(shell.querySelector('label[for=borrowToolId]').textContent==='Scanned Tool Code'&&scan.placeholder==='Scanned code will appear here.','Scan label and placeholder');
  check(!search.readOnly&&!search.disabled&&search.placeholder==='Search tool by name or code...','Manual search remains editable with its placeholder');
  const divider=el('brSecTool').querySelector('.or-divider');check(divider.textContent==='or search manually'&&divider.nextElementSibling.htmlFor==='borrowToolSearchInput','Divider directly precedes manual search');
  await api.startBorrowScanner();cameraSuccess('TOOL-A');
  check(scan.value==='TOOL-A'&&api.pending()==='TOOL-A'&&search.value.includes('Whisk'),'Camera result populates display and selects scanned tool');
  scan.focus();scan.select();check(scan.selectionStart===0&&scan.selectionEnd===6,'Scanned code can be selected for copying');
  document.execCommand('insertText',false,'typed or pasted');check(scan.value==='TOOL-A','Readonly blocks native text insertion');
  document.execCommand('delete');check(scan.value==='TOOL-A','Readonly blocks native deletion');
  search.value='';api.openBorrowToolDropdown();el('borrowToolDropdownList').querySelector('[data-code="TOOL-B"]').click();
  check(scan.value===''&&api.pending()==='TOOL-B'&&search.value.includes('Bowl'),'Selecting Tool B directly after scanning A clears old scan');
  el('borrowQty').value='2';el('borrowAddBtn').click();
  check(api.cart().length===1&&api.cart()[0].code==='TOOL-B'&&api.cart()[0].qty===2,'Add Tool adds final selection B, never scanned A');
  check(scan.value===''&&search.value===''&&api.pending()===null,'Adding resets the tool entry and scan display');
  const input={files:[new File(['fixture'],'qr.png',{type:'image/png'})],value:'fixture'};
  api.borrowScanUploadedImage({target:input});for(let i=0;i<8;i++)await Promise.resolve();
  check(scan.value==='TOOL-A'&&api.pending()==='TOOL-A'&&input.value==='','QR upload result selects A and resets file input');
  search.focus();search.value='bowl';search.dispatchEvent(new Event('input',{bubbles:true}));
  check(scan.value===''&&api.pending()===null,'Typing a manual search clears scan and previous selection');
  check(el('borrowToolDropdownList').querySelector('[data-code="TOOL-B"]')&&!el('borrowToolDropdownList').querySelector('[data-code="TOOL-A"]'),'Tool name suggestions remain filtered');
  const arrow=new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true,cancelable:true});search.dispatchEvent(arrow);check(!arrow.defaultPrevented,'Scan display does not intercept search keyboard navigation');
  el('borrowToolDropdownList').querySelector('[data-code="TOOL-B"]').click();check(api.pending()==='TOOL-B'&&scan.value==='','Manual search selection stays separate from scanner display');
  api.clearBorrowToolId();scan.value='TOOL-A';check(api.btAddFromForm()===false&&api.cart()[0].qty===2,'Scan display alone cannot supply a cart selection');
  api.clearBorrowToolId();check(scan.value===''&&search.value===''&&!el('clearBorrowToolBtn').classList.contains('visible'),'Explicit tool-entry reset clears scanner display');
  document.getElementById('result').textContent=checks.map(c=>'PASS: '+c).join('\n')+'\nPASSED: '+checks.length;
 }catch(e){document.getElementById('result').textContent='FAIL: '+e.stack;}
})();
