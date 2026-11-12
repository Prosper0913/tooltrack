/* ============================================================
   a11y-polish.js — keyboard + screen-reader layer for FPST ToolTrack.
   Additive and idempotent. It never replaces app.js behaviour:
   it only reuses the existing .click() handlers and the existing
   .modal-close buttons, so closeModal()/navigateTo() stay in charge.
   ============================================================ */
(function(){
  'use strict';
  const $$=(s,r)=>Array.from((r||document).querySelectorAll(s));
  const FOCUSABLE='a[href],button:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  const visible=el=>el.getClientRects().length>0&&getComputedStyle(el).visibility!=='hidden';

  /* 1. Sidebar items are <a> without href, so Tab skipped them. Make them real keyboard targets. */
  function enhanceNav(){
    $$('.sidebar .nav-item').forEach(a=>{
      if(!a.hasAttribute('tabindex'))a.setAttribute('tabindex','0');
      if(!a.hasAttribute('role'))a.setAttribute('role','link');
      if(a.classList.contains('active'))a.setAttribute('aria-current','page');else a.removeAttribute('aria-current');
    });
  }
  document.addEventListener('keydown',e=>{
    if(e.key!=='Enter'&&e.key!==' ')return;
    const a=e.target.closest&&e.target.closest('.sidebar .nav-item');
    if(a){e.preventDefault();a.click();}
  });

  /* 2. Skip link straight to the page content */
  function addSkipLink(){
    const main=document.querySelector('main.main-content');
    if(!main||document.querySelector('.skip-link'))return;
    if(!main.id)main.id='mainContent';
    main.setAttribute('tabindex','-1');
    const a=document.createElement('a');
    a.className='skip-link';a.href='#'+main.id;a.textContent='Skip to main content';
    a.addEventListener('click',e=>{e.preventDefault();main.focus({preventScroll:false});});
    document.body.insertBefore(a,document.body.firstChild);
  }

  /* 3. Modals: dialog semantics, focus in / focus restored, Esc closes, Tab stays inside */
  const openers=new WeakMap();
  let uid=0;
  function describeModal(overlay){
    const m=overlay.querySelector('.modal');if(!m)return null;
    m.setAttribute('role','dialog');m.setAttribute('aria-modal','true');
    m.setAttribute('tabindex','-1');
    const t=m.querySelector('.modal-title');
    if(t&&!m.hasAttribute('aria-labelledby')){if(!t.id)t.id='modalTitle'+(++uid);m.setAttribute('aria-labelledby',t.id);}
    return m;
  }
  function onOpen(overlay){
    const m=describeModal(overlay);if(!m)return;
    openers.set(overlay,document.activeElement);
    requestAnimationFrame(()=>{if(!m.contains(document.activeElement))m.focus({preventScroll:true});});
  }
  function onClose(overlay){
    const o=openers.get(overlay);openers.delete(overlay);
    if(o&&document.contains(o)&&visible(o)&&typeof o.focus==='function')o.focus({preventScroll:true});
  }
  const mo=new MutationObserver(list=>list.forEach(r=>{
    const el=r.target;if(!el.classList||!el.classList.contains('modal-overlay'))return;
    const was=(r.oldValue||'').split(/\s+/).includes('active'),now=el.classList.contains('active');
    if(now&&!was)onOpen(el);else if(!now&&was)onClose(el);
  }));
  function watchOverlays(){
    $$('.modal-overlay').forEach(o=>{
      if(o.dataset.a11yWatched)return;o.dataset.a11yWatched='1';describeModal(o);
      mo.observe(o,{attributes:true,attributeFilter:['class'],attributeOldValue:true});
    });
  }
  const topOverlay=()=>{const l=$$('.modal-overlay.active');return l[l.length-1]||null;};
  document.addEventListener('keydown',e=>{
    if(document.querySelector('dialog[open]'))return;            // native <dialog> handles its own Esc / focus
    const ov=topOverlay();
    if(e.key==='Escape'){
      if(ov){const c=ov.querySelector('.modal-close');if(c){e.preventDefault();c.click();}return;}
      const sb=document.querySelector('.sidebar.open');
      if(sb&&typeof window.closeSidebar==='function'){window.closeSidebar();}
      return;
    }
    if(e.key==='Tab'&&ov){
      const items=$$(FOCUSABLE,ov.querySelector('.modal')||ov).filter(visible);
      if(!items.length){e.preventDefault();return;}
      const first=items[0],last=items[items.length-1],cur=document.activeElement;
      if(e.shiftKey&&(cur===first||cur===ov.querySelector('.modal'))){e.preventDefault();last.focus();}
      else if(!e.shiftKey&&cur===last){e.preventDefault();first.focus();}
    }
  });

  /* 4. Names for icon-only buttons and scrollable table regions */
  function nameControls(){
    $$('.modal-close:not([aria-label])').forEach(b=>b.setAttribute('aria-label','Close dialog'));
    $$('.mobile-menu-btn:not([aria-expanded])').forEach(b=>{b.setAttribute('aria-expanded','false');b.setAttribute('aria-controls','sidebar');});
    const sb=document.querySelector('aside.sidebar');if(sb&&!sb.id)sb.id='sidebar';
  }
  function syncMenuState(){
    const b=document.getElementById('mobileMenuBtn'),sb=document.querySelector('.sidebar');
    if(b&&sb)b.setAttribute('aria-expanded',sb.classList.contains('open')?'true':'false');
  }
  /* A horizontally scrolling table must be reachable by keyboard; phone card layouts are not scrollable, so they get no extra tab stop. */
  function scrollRegions(){
    $$('.table-container').forEach(c=>{
      const overflowing=c.scrollWidth>c.clientWidth+2;
      if(overflowing&&!c.hasAttribute('tabindex')){
        const t=c.closest('.card')&&c.closest('.card').querySelector('.card-title');
        c.setAttribute('tabindex','0');c.setAttribute('role','region');
        c.setAttribute('aria-label',(t?t.textContent.trim()+' — ':'')+'scrollable table');c.dataset.a11yScroll='1';
      }else if(!overflowing&&c.dataset.a11yScroll){
        c.removeAttribute('tabindex');c.removeAttribute('role');c.removeAttribute('aria-label');delete c.dataset.a11yScroll;
      }
    });
  }

  let timer=null;
  function refresh(){enhanceNav();nameControls();watchOverlays();scrollRegions();syncMenuState();}
  function schedule(){clearTimeout(timer);timer=setTimeout(refresh,250);}
  function init(){
    addSkipLink();refresh();
    new MutationObserver(schedule).observe(document.body,{childList:true,subtree:true,attributes:true,attributeFilter:['class']});
    window.addEventListener('resize',schedule,{passive:true});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
