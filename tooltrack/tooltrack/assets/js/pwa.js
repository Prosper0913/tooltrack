/* Install support only: no changes to application modules, forms or authentication. */
(()=>{
  'use strict';
  const base=new URL('../../',document.currentScript.src);
  const status=document.getElementById('pwaStatus');
  const install=document.getElementById('pwaInstall');
  let pendingInstall=null;
  const ios=/iPad|iPhone|iPod/.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
  const say=text=>{if(status)status.textContent=text;};
  window.addEventListener('beforeinstallprompt',event=>{
    if(!install)return; // Let the browser offer its standard install UI on normal application pages.
    event.preventDefault();pendingInstall=event;install.hidden=false;
  });
  if(install)install.addEventListener('click',async()=>{
    if(!pendingInstall)return;
    install.disabled=true;
    try{await pendingInstall.prompt();await pendingInstall.userChoice;}
    finally{pendingInstall=null;install.hidden=true;install.disabled=false;}
  });
  window.addEventListener('appinstalled',()=>{if(install)install.hidden=true;say('ToolTrack is installed.');});
  if(!window.isSecureContext){say('Open the trusted HTTPS address to enable installation.');return;}
  if(!('serviceWorker' in navigator)){say('Use Safari on iPhone, Chrome on Android, or Chrome/Edge on Windows.');return;}
  window.addEventListener('load',async()=>{
    try{
      await navigator.serviceWorker.register(new URL('sw.js',base),{scope:base.pathname,updateViaCache:'none'});
      await navigator.serviceWorker.ready;
      say(ios?'HTTPS and service worker are ready. In Safari, tap Share → Add to Home Screen.':'HTTPS and service worker are ready. Use your browser’s Install app command.');
    }catch(error){say('Service worker could not start. Check that the certificate is trusted, then reload.');console.error('ToolTrack PWA registration failed:',error);}
  });
})();