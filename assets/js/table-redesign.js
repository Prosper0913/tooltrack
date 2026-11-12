/* FPST ToolTrack — grouped inventory and borrower tables.
   Presentation only: existing APIs, actions, roles, exports and modals are reused. */
(function(){
  'use strict';
  const configs={
    tools:{page:'toolsPage',body:'toolsTableBody',columns:[['tool','Tool'],['inventory','Inventory'],['condition','Condition'],['activity','Borrowing'],['price','Unit Price'],['actions','Actions']]},
    borrowers:{page:'borrowersPage',body:'borrowerTableBody',columns:[['borrower','Borrower'],['academic','Academic Info'],['contact','Contact'],['borrowing','Borrowing'],['status','Status'],['actions','Actions']]}
  };
  const hidden={tools:new Set(),borrowers:new Set()};
  const esc=s=>escapeHtml(String(s??''));
  const num=n=>Number(n)||0;
  const detail=(label,value)=>`<div class="tt-detail-pair"><span>${label}</span><strong>${value}</strong></div>`;
  const cell=(key,html,extra='')=>`<td data-col="${key}" ${extra}>${html}</td>`;
  const heading=(key,title,extra='')=>`<th data-col="${key}" ${extra}>${title}</th>`;
  function setup(type){
    const c=configs[type], page=document.getElementById(c.page); if(!page)return;
    const table=page.querySelector('.table-container table');if(!table)return;
    table.classList.add('tt-grouped-table');
    const headers=type==='tools'?
      [heading('tool','Tool'),heading('inventory','Active Stock / Available'),heading('condition','Condition / Active Issues'),heading('activity','Borrowing'),heading('price','Unit Price'),heading('actions','Actions')]:
      [heading('borrower',`<span class="tt-sort" onclick="sortBorrowers('full_name')">Borrower <i class="fas fa-sort"></i></span>`),heading('academic','Academic Info'),heading('contact','Contact'),heading('borrowing',`<span class="tt-sort" onclick="sortBorrowers('active_borrows')">Borrowing <i class="fas fa-sort"></i></span>`),heading('status','Status'),heading('actions','Actions')];
    table.querySelector('thead').innerHTML='<tr>'+headers.join('')+'</tr>';
    const toolbar=document.createElement('div');toolbar.className='tt-table-toolbar';
    toolbar.innerHTML=`<span class="tt-table-hint"><i class="fas fa-circle-info"></i> Select a row's arrow for more details</span><div class="tt-column-control"><button type="button" class="tt-column-button" aria-expanded="false"><i class="fas fa-table-columns"></i> Columns <i class="fas fa-chevron-down"></i></button><div class="tt-column-popover" hidden></div></div>`;
    page.querySelector('.table-container').before(toolbar);
    const pop=toolbar.querySelector('.tt-column-popover');
    pop.innerHTML=c.columns.filter(([key])=>key!=='tool'&&key!=='borrower'&&key!=='actions').map(([key,label])=>`<label><input type="checkbox" data-col="${key}" checked> ${label}</label>`).join('');
    const toggle=toolbar.querySelector('.tt-column-button');
    toggle.addEventListener('click',()=>{pop.hidden=!pop.hidden;toggle.setAttribute('aria-expanded',String(!pop.hidden));});
    pop.addEventListener('change',e=>{if(e.target.matches('input[data-col]')){const key=e.target.dataset.col;e.target.checked?hidden[type].delete(key):hidden[type].add(key);applyColumns(type);}});
    document.addEventListener('click',e=>{if(!toolbar.contains(e.target)){pop.hidden=true;toggle.setAttribute('aria-expanded','false');}});
    applyColumns(type);
  }
  function applyColumns(type){
    const page=document.getElementById(configs[type].page);if(!page)return;
    page.querySelectorAll('.tt-grouped-table [data-col]').forEach(el=>{el.hidden=hidden[type].has(el.dataset.col);});
    page.querySelectorAll('.tt-detail-row td').forEach(td=>td.colSpan=configs[type].columns.length-hidden[type].size);
  }
  function condition(t){
    const good=num(t.good_qty),minor=num(t.minor_qty),damaged=num(t.damaged_qty),missing=num(t.missing_qty),total=Math.max(1,good+minor+damaged);
    const segments=[['good',good],['minor',minor],['damaged',damaged]];
    const bar=segments.filter(([,n])=>n>0).map(([name,n])=>`<span class="tt-segment tt-${name}" style="width:${n/total*100}%" title="${name}: ${n}"></span>`).join('');
    return `<div class="tt-condition-bar" aria-label="Physical condition: ${good} good, ${minor} minor wear, ${damaged} damaged">${bar}</div><small>${good} good · ${minor} minor wear</small><small class="tt-issue">${[missing?`${missing} missing`:'',damaged?`${damaged} damaged`:''].filter(Boolean).join(' · ')||'No active issues'}</small>`;
  }
  function expandButton(id,type){return `<button type="button" class="tt-expand" aria-expanded="false" aria-label="Show ${type} details" onclick="toggleTableDetail(this)"><i class="fas fa-chevron-right"></i></button>`;}
  window.toggleTableDetail=function(button){
    const row=button.closest('tr'),next=row.nextElementSibling;if(!next||!next.classList.contains('tt-detail-row'))return;
    if(row.closest('#toolsPage')&&matchMedia('(max-width:767px)').matches){openInventoryToolDrawer(button);return;}
    const open=next.hidden;next.hidden=!open;button.setAttribute('aria-expanded',String(open));row.classList.toggle('tt-row-expanded',open);
  };
  window.toggleToolMore=function(button){
    if(matchMedia('(max-width:767px)').matches){openInventoryToolDrawer(button);return;}
    const extra=button.closest('.tt-tool-extra');
    const open=extra.classList.toggle('tt-more-open');
    button.setAttribute('aria-expanded',String(open));
    button.textContent=open?'Less details':'More details';
  };
  window.openToolDetailsDrawer=function(title,content){
    let drawer=document.getElementById('toolDetailsDrawer');
    if(!drawer){
      drawer=document.createElement('dialog');drawer.id='toolDetailsDrawer';drawer.className='tm-drawer tool-details-drawer';drawer.setAttribute('aria-labelledby','toolDrawerTitle');document.body.append(drawer);
      drawer.addEventListener('click',event=>{
        if(event.target.closest('[data-tool-drawer-close]')||event.target.closest('.tt-detail-long button'))drawer.close();
        if(event.target===drawer){const rect=drawer.getBoundingClientRect();if(event.clientY<rect.top||event.clientY>rect.bottom||event.clientX<rect.left||event.clientX>rect.right)drawer.close();}
      });
    }
    drawer.innerHTML=`<div class="tm-pull" aria-hidden="true"></div><header><div><h2 id="toolDrawerTitle">${esc(title)}</h2><p>Tool details</p></div><button type="button" data-tool-drawer-close aria-label="Close tool details">×</button></header><div class="tool-drawer-content">${content}</div>`;
    drawer.querySelectorAll('.tt-tool-more').forEach(button=>button.remove());
    drawer.querySelectorAll('.tool-drawer-content [id]').forEach(node=>node.removeAttribute('id'));
    if(!drawer.open)drawer.showModal();drawer.scrollTop=0;
  };
  window.openInventoryToolDrawer=function(button){
    const row=button.closest('tr');
    const summary=row.classList.contains('tt-detail-row')?row.previousElementSibling:row;
    const details=summary.nextElementSibling;
    if(!details?.classList.contains('tt-detail-row'))return;
    openToolDetailsDrawer(summary.querySelector('.tool-info h4')?.textContent||'Tool',details.cells[0].innerHTML);
  };
  const originalTools=window.renderToolsTable;
  function patchGroupedRows(body,html){
    const template=document.createElement('template');template.innerHTML='<table><tbody>'+html+'</tbody></table>';
    const old=new Map([...body.querySelectorAll('tr[data-record]')].map(row=>[row.dataset.record,row]));
    const desired=[];
    const incoming=[...template.content.querySelector('tbody').children];
    for(let i=0;i<incoming.length;i++){
      const fresh=incoming[i],previous=old.get(fresh.dataset.record);
      if(!previous){desired.push(fresh);continue;}
      const expanded=previous.classList.contains('tt-row-expanded'),detail=previous.nextElementSibling;
      if(previous.innerHTML!==fresh.innerHTML)previous.innerHTML=fresh.innerHTML;
      previous.className=fresh.className;desired.push(previous);
      if(incoming[i+1]?.classList.contains('tt-detail-row')){
        const next=incoming[++i],kept=detail?.classList.contains('tt-detail-row')?detail:next;
        const moreOpen=kept.querySelector('.tt-tool-extra')?.classList.contains('tt-more-open');
        if(kept.innerHTML!==next.innerHTML)kept.innerHTML=next.innerHTML;
        if(moreOpen){
          kept.querySelector('.tt-tool-extra')?.classList.add('tt-more-open');
          const more=kept.querySelector('.tt-tool-more');
          if(more){more.setAttribute('aria-expanded','true');more.textContent='Less details';}
        }
        kept.hidden=!expanded;previous.classList.toggle('tt-row-expanded',expanded);
        previous.querySelector('.tt-expand')?.setAttribute('aria-expanded',String(expanded));desired.push(kept);
      }
    }
    // Reconcile children in place: retain unchanged row nodes and their listeners.
    desired.forEach((row,index)=>{if(body.children[index]!==row)body.insertBefore(row,body.children[index]||null);});
    while(body.children.length>desired.length)body.lastElementChild.remove();
  }
  window.renderToolsTable=function(tools){
    if(typeof closeToolMenu==='function')closeToolMenu();
    toolMenuCache.clear();tools.forEach(t=>toolMenuCache.set(+t.id,t));
    const body=document.getElementById('toolsTableBody');
    if(!tools.length){body.innerHTML='<tr class="empty-row"><td colspan="6">No tools found.</td></tr>';return;}
    const html=tools.map(t=>{
      const good=num(t.good_qty),minor=num(t.minor_qty),loans=num(t.current_loans),available=good+minor;
      const has=key=>t[key]!==undefined&&t[key]!==null&&String(t[key]).trim()!=='';
      const description=has('description')?`<div class="tt-detail-long">${detail('Description',esc(t.description))}</div>`:'';
      const loss=num(t.gross_written_off)>0?`<div class="tt-detail-long">${detail('Loss History',`<span>${num(t.gross_written_off)} written off historically · ${num(t.later_loss_recoveries)} later recovered · ${Math.max(0,num(t.gross_written_off)-num(t.later_loss_recoveries))} net lost</span><button class="card-btn secondary" onclick="openToolLossHistory(${num(t.id)})">Loss History</button>`)}</div>`:'';
      const identification=[has('code')?detail('Tool Code',`<code>${esc(t.code)}</code>`):'',has('category')?detail('Category',esc(t.category)):''].join('');
      const additional=[has('active_borrowers')?detail('Active Borrowers',num(t.active_borrowers)):'',has('current_loans')?detail('Current Loans',loans):'',has('price')?detail('Unit Price',`₱${num(t.price).toFixed(2)}`):'',description,loss].join('');
      const info=`<div class="tt-details-grid tt-tool-panel"><section class="tt-tool-section tt-tool-stock"><h4>Stock and Condition</h4><div class="tt-tool-section-grid">${detail('Total units',num(t.quantity))}${detail('Available units',available)}${has('borrowed_qty')?detail('Units on loan',num(t.borrowed_qty)):''}${detail('Good Condition',good)}${detail('Minor Wear',minor)}${detail('Damaged',num(t.damaged_qty))}${detail('Missing',num(t.missing_qty))}</div></section>${identification||additional?`<div class="tt-tool-extra"><button type="button" class="tt-tool-more" aria-expanded="false" aria-controls="tool-extra-${num(t.id)}" onclick="toggleToolMore(this)">More details</button><div class="tt-more-content" id="tool-extra-${num(t.id)}">${identification?`<section class="tt-tool-section"><h4>Identification</h4><div class="tt-tool-section-grid">${identification}</div></section>`:''}${additional?`<section class="tt-tool-section"><h4>Additional Information</h4><div class="tt-tool-section-grid">${additional}</div></section>`:''}</div></div>`:''}</div>`;
      return `<tr data-record="${num(t.id)}" class="tt-data-row${t.is_active==0?' tt-muted':''}${(num(t.damaged_qty)>0||num(t.missing_qty)>0)?' tt-has-issue':''}">`+
      cell('tool',`<div class="tt-name-cell">${expandButton(t.id,'tool')}<div class="tool-icon"><i class="${CAT_ICONS[t.category]||'fas fa-box'}"></i></div><div class="tool-info"><h4>${esc(t.name)}${t.is_active==0?' <small>(Retired)</small>':''}</h4><span>${esc(t.code)} · ${esc(t.category)}</span>${t.status?`<div class="tt-card-status"><span class="status-badge ${esc(t.status)}"><span class="status-dot"></span>${esc(statusLabel(t.status))}</span></div>`:''}</div><span class="tt-mobile-stock"><strong>${num(t.quantity)}</strong><small>Total units</small></span></div>`)+
      cell('inventory',`<strong class="tt-quantity">${num(t.quantity)} active</strong><small>${available} available</small>`)+
      cell('condition',condition(t))+
      cell('activity',`<strong>${loans} on loan</strong><small>${num(t.active_borrowers)} active borrowers</small>`)+
      cell('price',`₱${num(t.price).toFixed(2)}`)+
      cell('actions',`<div class="tool-stock-actions"><button type="button" class="tool-mobile-details" onclick="openInventoryToolDrawer(this)">More details <i class="fas fa-chevron-right" aria-hidden="true"></i></button>${stockActionButton(t)}<button type="button" class="kebab-btn" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(t.name)}" onclick="openToolMenu(event,${+t.id})"><i class="fas fa-ellipsis-vertical"></i></button></div>`)+
      `</tr><tr class="tt-detail-row" hidden><td colspan="6">${info}</td></tr>`;
    }).join('');patchGroupedRows(body,html);applyColumns('tools');
  };
  window.renderBorrowersTable=function(borrowers){
    borrowerMenuCache.clear();if(typeof closeBorrowerMenu==='function')closeBorrowerMenu();
    borrowers.forEach(b=>borrowerMenuCache.set(Number(b.id),b));
    const body=document.getElementById('borrowerTableBody');
    if(!borrowers.length){body.innerHTML='<tr class="empty-row"><td colspan="6">No borrowers found.</td></tr>';return;}
    const html=borrowers.map(b=>{
      const status=b.is_active==0?'Inactive':'Active';
      const details=`<div class="tt-details-grid">${detail('ID Number',`<code>${esc(b.id_number)}</code>`)}${detail('Type',esc(b.type))}${detail('Program',esc(b.course||'—'))}${detail('Section',esc(b.section_name||'—'))}${detail('Year Level',esc(b.year_level||'—'))}${detail('Phone',esc(b.phone||'—'))}${detail('Email',esc(b.email||'—'))}${detail('Active Borrows',num(b.active_borrows))}${detail('Total Borrows',num(b.total_borrows))}${detail('Status',status)}</div>`;
      return `<tr data-record="${num(b.id)}" class="tt-data-row${b.is_active==0?' tt-muted':''}">`+
      cell('borrower',`<div class="tt-name-cell">${expandButton(b.id,'borrower')}<div class="user-avatar">${esc(getInitials(b.full_name))}</div><div class="tool-info"><h4>${esc(b.full_name)}${num(b.flagged_count)+num(b.open_missing_count)>0?' <i class="fas fa-flag tt-issue" title="Has an issue history"></i>':''}</h4><span>${esc(b.id_number)} · ${esc(b.type)}</span></div></div>`)+
      cell('academic',`<strong>${esc(b.course||'—')}</strong><small>${esc(b.section_name||'—')} · ${esc(b.year_level||'—')}</small>`)+
      cell('contact',`<span class="tt-truncate" title="${esc(b.phone||b.email||'—')}">${esc(b.phone||b.email||'—')}</span>`)+
      cell('borrowing',`${activeBorrowsBadge(b)}<small>${num(b.total_borrows)} total borrows</small>`)+
      cell('status',`<span class="tt-status ${b.is_active==0?'tt-inactive':'tt-active'}">${status}</span>`)+
      cell('actions',`<button type="button" class="kebab-btn" aria-haspopup="menu" aria-expanded="false" aria-label="Actions for ${esc(b.full_name)}" onclick="openBorrowerMenu(event,${Number(b.id)})"><i class="fas fa-ellipsis-vertical"></i></button>`)+
      `</tr><tr class="tt-detail-row" hidden><td colspan="6">${details}</td></tr>`;
    }).join('');patchGroupedRows(body,html);applyColumns('borrowers');
  };
  setup('tools');setup('borrowers');
})();
