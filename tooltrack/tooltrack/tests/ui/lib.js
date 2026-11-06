const {chromium}=require('playwright');const fs=require('fs');const path=require('path');
const CDN=process.env.TT_CDN||path.join(__dirname,'cdn/node_modules'); /* optional offline copies of the CDN libraries */
const MAP=[
 [/font-awesome\/6\.4\.0\/css\/all\.min\.css/,CDN+'/@fortawesome/fontawesome-free/css/all.min.css','text/css'],
 [/font-awesome\/6\.4\.0\/webfonts\/(.+)$/,m=>CDN+'/@fortawesome/fontawesome-free/webfonts/'+m[1].split('?')[0],null],
 [/cdn\.jsdelivr\.net\/npm\/chart\.js$/,CDN+'/chart.js/dist/chart.umd.js','application/javascript'],
 [/chartjs-plugin-datalabels@2$/,CDN+'/chartjs-plugin-datalabels/dist/chartjs-plugin-datalabels.min.js','application/javascript'],
 [/html5-qrcode@2\.3\.8\/html5-qrcode\.min\.js/,CDN+'/html5-qrcode/html5-qrcode.min.js','application/javascript'],
 [/qrcodejs\/1\.0\.0\/qrcode\.min\.js/,CDN+'/qrcodejs/qrcode.js','application/javascript'],
];
async function newCtx(browser,base,vp,user='admin',pass='Test#12345'){
 const ctx=await browser.newContext({viewport:vp});
 await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/,async route=>{
  const url=route.request().url();
  if(/fonts\.googleapis|fonts\.gstatic/.test(url))return route.fulfill({status:200,contentType:'text/css',body:''});
  for(const [re,file,type] of MAP){const m=url.match(re);if(m){const f=typeof file==='function'?file(m):file;if(fs.existsSync(f))return route.fulfill({status:200,body:fs.readFileSync(f),contentType:type||undefined});}}
  return route.abort();
 });
 const p=await ctx.newPage();const errs=[];const apiBad=[];
 p.on('console',m=>{if(m.type()==='error')errs.push('console: '+m.text())});
 p.on('pageerror',e=>errs.push('pageerror: '+e.message));
 p.on('response',async r=>{const u=r.url();if(u.includes('/api/')&&r.status()>=400)apiBad.push(r.status()+' '+r.request().method()+' '+u.replace(base,''))});
 await p.goto(base+'/Login.html');
 const st=await p.evaluate(async([u,pw])=>{const x=await fetch('api/auth.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:u,password:pw})});return x.status},[user,pass]);
 if(st!==200)throw new Error('login failed '+st);
 await p.goto(base+'/index.php');await p.waitForTimeout(700);
 return {ctx,p,errs,apiBad};
}
module.exports={chromium,newCtx};
