/* Reorder existing checkout nodes on phones; no copied fields or alternate checkout. */
(function(){
 'use strict';
 function init(){
  const page=document.getElementById('borrowPage');if(!page)return;
  const phone=matchMedia('(max-width:767px)');
  const get=id=>document.getElementById(id);
  const body=page.querySelector('.br-form>.br-card-body');
  const menu=get('borrowViewsMenu');
  const searchHints=[
   {input:get('borrowerSearchInput'),mobile:'Name or ID'},
   {input:get('borrowToolSearchInput'),mobile:'Tool name or code'}
  ].map(hint=>({...hint,original:hint.input.placeholder}));
  const moved=[];
  function move(node,parent,before=null){
   const anchor=document.createComment('Original checkout position');
   node.before(anchor);moved.push({node,anchor});parent.insertBefore(node,before);
  }
  function layout(){
   // Restore original desktop/tablet structure before applying the phone sequence.
   while(moved.length){const {node,anchor}=moved.pop();anchor.replaceWith(node);}
   if(menu)menu.open=!phone.matches;
   searchHints.forEach(hint=>{hint.input.placeholder=phone.matches?hint.mobile:hint.original;});
   if(!phone.matches)return;
   move(get('brSecBorrower'),body,get('brSecTool'));
   move(get('borrowCartCard'),body,get('brSecLoan'));
   // Search is the first editable field; the same camera/upload controls follow it.
   move(get('toolScanZone'),get('brSecTool'),get('brToolPreview'));
   move(get('idScanZone'),get('brSecBorrower'),get('brBorrowerCard'));
  }
  if(menu)menu.addEventListener('click',event=>{
   const tab=event.target.closest('[data-circulation-tab]');
   if(tab&&phone.matches){menu.open=false;menu.querySelector('summary').textContent='Borrowing views: '+tab.textContent.trim();menu.querySelector('summary').focus();}
  });
  // Scanner status remains visible when secondary hardware options are collapsed.
  const hardware=document.createElement('details');hardware.className='br-id-hardware';
  const summary=document.createElement('summary');summary.textContent='Scanned Student ID';hardware.append(summary);
  const input=get('idScanInput');input.before(hardware);hardware.append(input);
  function hardwareLayout(){hardware.open=true;}
  phone.addEventListener('change',()=>{layout();hardwareLayout();});
  layout();hardwareLayout();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
