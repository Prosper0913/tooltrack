/* Shared mobile presentation for tables without an existing specialist card layout.
   Keep the actual rows, form controls and handlers; never duplicate record data. */
(function(){
 'use strict';
 const specialized='#toolsPage,#borrowersPage,#returnPage,#replacementsPage,[data-report="overdue"]';
 function enhance(){
  document.querySelectorAll('table.data-table').forEach(table=>{
   // Existing grouped/return/incident cards retain their workflow-specific layouts.
   const existing=table.closest(specialized)||
    table.tBodies[0]?.id==='borrowHistoryBody'||
    (table.tBodies[0]?.id==='reportTableBody'&&table.closest('[data-report="transactions"]')&&table.closest('[data-transactions-grouped="true"]'));
   table.classList.toggle('tt-mobile-cards',!existing);
   if(existing)return;
   const headers=Array.from(table.tHead?.rows[table.tHead.rows.length-1]?.cells||[]);
   const labels=headers.flatMap(th=>Array.from({length:th.colSpan||1},()=>th.textContent.trim()));
   Array.from(table.tBodies).forEach(body=>Array.from(body.rows).forEach(row=>{
    // Spanning detail, total, loading/error and empty rows are kept in full width.
    const spanning=Array.from(row.cells).some(cell=>cell.colSpan>1);
    row.classList.toggle('tt-mobile-message',spanning||row.classList.contains('empty-row'));
    let column=0;
    Array.from(row.cells).forEach((cell,index)=>{
     if(!cell.hasAttribute('data-label')||cell.dataset.mobileAutoLabel==='true'){
      const label=spanning?'':labels[column]||'';
      if(cell.getAttribute('data-label')!==label)cell.setAttribute('data-label',label);
      cell.dataset.mobileAutoLabel='true';
     }
     const identity=row.querySelector('.report-identity');
     cell.classList.toggle('tt-mobile-identity',!spanning&&(identity?cell===identity:index===0));
     cell.classList.toggle('tt-mobile-actions',!spanning&&/^(actions?|options?)$/i.test(labels[column]||cell.dataset.label||''));
     column+=cell.colSpan||1;
    });
   }));
  });
 }
 let scheduled=false;
 function schedule(){if(scheduled)return;scheduled=true;requestAnimationFrame(()=>{scheduled=false;enhance();});}
 function init(){
  enhance();
  // Reports/imports and live updates replace rows after the initial render.
  new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true});
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
