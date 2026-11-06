async function runLossUiChecks(source){
 const checks=[],assert=(ok,label)=>{if(!ok)throw Error(label);checks.push(label);};
 const container=document.createElement('div');document.body.append(container);
 const d={source:'missing',id:3,original:4,eligible:2,pending_quantity:0,gross_written_off:0,later_recovered:0,net_loss:0,recovered:2,outcome:'Partially Closed',age_days:20,needs_review:true,review_days:14,tool:{name:'Oven <test>',quantity:4,available:2},requests:[]};
 let posts=[],prompts=[],failOnce=false,refreshes=0,allow=true;
 const esc=v=>String(v).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
 const fetcher=async(url,options)=>{if(!options)return {data:structuredClone(d)};const b=JSON.parse(options.body);posts.push(b);if(failOnce){failOnce=false;d.tool.quantity-=b.quantity;throw Error('response lost');}return {data:{}};};
 const funcs=new Function('chargeFetch','escapeHtml','rrFmtDT','rpToken','showToast','renderMissingCase','rpView','mtRefreshAll','rpRefreshInventory','API','confirm',source+';return {lossMount,lossSave};')(fetcher,esc,v=>v,()=> 'loss_ui_token_123456',()=>{},async()=>{refreshes++;},async()=>{refreshes++;},()=>{},()=>{},'api',message=>{prompts.push(message);return allow;});
 const field=(id,value)=>document.getElementById(id).value=value;
 window.CURRENT_ROLE='Staff';await funcs.lossMount('missing',3,container);
 assert(container.textContent.includes('Request Write-off')&&!container.textContent.includes('Approve write-off'),'Staff sees request action');
 assert(container.textContent.includes('Needs Review'),'Over-threshold incident shows reminder');
 assert(!container.querySelector('test'),'Tool data is escaped');
 field('lossQty','1.5');await funcs.lossSave('request');assert(posts.length===0,'Fractional UI quantity rejected');
 field('lossQty','1');field('lossReason','Unrecoverable');await funcs.lossSave('request');assert(posts.length===0,'Staff investigation notes required');
 field('lossNotes','Checked all storage rooms');await funcs.lossSave('request');assert(posts.length===1&&posts[0].action==='request','Staff request submits investigation notes');
 window.CURRENT_ROLE='Admin';await funcs.lossMount('missing',3,container);field('lossQty','1');field('lossReason','No recovery possible');
 allow=false;await funcs.lossSave('direct');assert(posts.length===1,'Cancelled write-off changes nothing');allow=true;
 failOnce=true;await funcs.lossSave('direct');await funcs.lossSave('direct');
 assert(posts.length===3&&JSON.stringify(posts[1])===JSON.stringify(posts[2]),'Uncertain response retries exact original payload/token');
 assert(prompts.some(p=>p.includes('from 4 to 3')&&p.includes('remain 2')&&p.includes('accountability')),'Confirmation states exact total, availability and accountability impact');
 assert(refreshes===2,'Successful actions refresh incident details');
 container.remove();return checks;
}
