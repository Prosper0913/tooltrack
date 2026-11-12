/* Shared, database-backed category catalog and Admin editor. */
const inventoryCategories={rows:window.TOOL_CATEGORIES||[],icons:{},csrf:'',editing:false,busy:false};
function syncInventoryCategories(rows){
 inventoryCategories.rows=rows;window.TOOL_CATEGORIES=rows;
 for(const c of rows)CAT_ICONS[c.name]='fas '+c.icon_name;
 for(const id of ['toolCategoryFilter','t_category']){
  const select=document.getElementById(id);if(!select)continue;
  const selected=select.value;select.replaceChildren(new Option(id==='t_category'?'Select a category':'All Categories',''));
  rows.forEach(c=>select.add(new Option(c.name,c.name)));
  if(selected&&!rows.some(c=>c.name===selected))select.add(new Option(selected,selected));
  select.value=selected;
 }
 const grid=document.getElementById('mobileCategoryGrid');
 if(grid){grid.replaceChildren();rows.forEach(c=>{
  const button=document.createElement('button');button.type='button';button.dataset.category=c.name;
  const span=document.createElement('span'),icon=document.createElement('i');icon.className='fas '+c.icon_name;icon.setAttribute('aria-hidden','true');span.append(icon);button.append(span,document.createTextNode(c.name));
  button.onclick=()=>mobileInventoryCollection(c.name);grid.append(button);
 });}
 if(typeof toolMenuCache!=='undefined'&&toolMenuCache.size&&typeof renderToolsTable==='function')renderToolsTable([...toolMenuCache.values()]);
}
async function loadInventoryCategories(){
 const response=await apiFetch(API+'/categories.php');
 inventoryCategories.csrf=response.data.csrf||'';inventoryCategories.icons=response.data.icons||{};
 syncInventoryCategories(response.data.categories);return response.data;
}
function renderCategoryManager(){
 const list=document.getElementById('categoryExistingList');list.replaceChildren();
 inventoryCategories.rows.forEach(c=>{
  const button=document.createElement('button');button.type='button';button.className='category-existing';
  const icon=document.createElement('i');icon.className='fas '+c.icon_name;icon.setAttribute('aria-hidden','true');
  const text=document.createElement('span');text.textContent=c.name;const prefix=document.createElement('small');prefix.textContent=c.code;
  button.append(icon,text,prefix);button.title='Change icon for '+c.name;button.onclick=()=>editInventoryCategory(c);list.append(button);
 });
 const picker=document.getElementById('categoryIconPicker');picker.replaceChildren();
 for(const [name,label] of Object.entries(inventoryCategories.icons)){
  const wrapper=document.createElement('label'),radio=document.createElement('input');radio.type='radio';radio.name='categoryIcon';radio.value=name;radio.required=true;
  const icon=document.createElement('i');icon.className='fas '+name;icon.setAttribute('aria-hidden','true');const text=document.createElement('span');text.textContent=label;
  wrapper.append(radio,icon,text);picker.append(wrapper);
 }
}
function resetCategoryEditor(){
 if(inventoryCategories.busy)return;inventoryCategories.editing=false;
 document.getElementById('categoryEditor').reset();
 for(const id of ['categoryName','categoryPrefix'])document.getElementById(id).readOnly=false;
 document.getElementById('categoryEditorTitle').textContent='New category';document.getElementById('categorySaveButton').textContent='Add category';document.getElementById('categorySaveMessage').textContent='';
 const first=document.querySelector('#categoryIconPicker input[value="fa-box"]');if(first)first.checked=true;
}
function editInventoryCategory(category){
 if(inventoryCategories.busy)return;resetCategoryEditor();inventoryCategories.editing=true;
 document.getElementById('categoryName').value=category.name;document.getElementById('categoryPrefix').value=category.code;
 for(const id of ['categoryName','categoryPrefix'])document.getElementById(id).readOnly=true;
 document.getElementById('categoryEditorTitle').textContent='Change category icon';document.getElementById('categorySaveButton').textContent='Save icon';
 document.querySelectorAll('#categoryIconPicker input').forEach(r=>r.checked=r.value===category.icon_name);
 document.getElementById('categoryEditorTitle').scrollIntoView({block:'nearest'});
}
async function openCategoryManager(){
 if(window.CURRENT_ROLE!=='Admin')return;
 openModal('categoryManagerModal');document.getElementById('categorySaveButton').disabled=true;
 document.getElementById('categorySaveMessage').textContent='Loading categories…';
 try{
  const data=await loadInventoryCategories();renderCategoryManager();resetCategoryEditor();
  if(!data.ready)throw Error('Category management is not installed. Run api/migrate_tool_categories.php.');
  document.getElementById('categorySaveButton').disabled=false;
 }catch(e){document.getElementById('categorySaveMessage').textContent=e.message||'Could not load categories. Reopen to retry.';}
}
async function saveInventoryCategory(event){
 event.preventDefault();if(inventoryCategories.busy)return;
 const form=document.getElementById('categoryEditor');if(!form.reportValidity())return;
 const payload={name:document.getElementById('categoryName').value.trim().replace(/\s+/gu,' '),code:document.getElementById('categoryPrefix').value.trim().toUpperCase(),icon:form.querySelector('input[name="categoryIcon"]:checked')?.value,csrf:inventoryCategories.csrf};
 inventoryCategories.busy=true;document.getElementById('categorySaveButton').disabled=true;
 document.getElementById('categorySaveMessage').textContent='Saving…';
 try{
  const response=await apiFetch(API+'/categories.php',{method:inventoryCategories.editing?'PUT':'POST',body:JSON.stringify(payload)});
  syncInventoryCategories(response.data.categories);renderCategoryManager();
  const editing=inventoryCategories.editing;inventoryCategories.busy=false;resetCategoryEditor();
  if(!editing){document.getElementById('t_category').value=payload.name;if(document.getElementById('toolModal').classList.contains('active'))updateToolSkuPreview();}
  document.getElementById('categorySaveMessage').textContent=editing?'Category icon updated.':'Category added. It is now available in New Tool and inventory filters.';
  if(document.getElementById('toolsPage').classList.contains('active'))loadTools();
 }catch(e){document.getElementById('categorySaveMessage').textContent=e.message||'Could not save. Your entries have been retained.';}
 finally{inventoryCategories.busy=false;document.getElementById('categorySaveButton').disabled=false;}
}
document.addEventListener('DOMContentLoaded',()=>{loadInventoryCategories().catch(()=>{});});
