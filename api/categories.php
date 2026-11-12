<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/category_lib.php';
requireLogin();$db=getDB();$method=$_SERVER['REQUEST_METHOD'];
if($method==='GET'){
    $csrf=null;
    if(($_SESSION['user_role']??'')===ROLE_ADMIN){
        if(empty($_SESSION['inventory_category_csrf']))$_SESSION['inventory_category_csrf']=bin2hex(random_bytes(32));
        $csrf=$_SESSION['inventory_category_csrf'];
    }
    session_write_close();
    ok(['categories'=>toolCategoryCatalog($db),'icons'=>toolCategoryIcons(),'csrf'=>$csrf,'ready'=>toolCategoryStorageReady($db)]);
}
if(!in_array($method,['POST','PUT'],true))fail('Method not allowed.',405);
requireRole(ROLE_ADMIN);$b=body();
if(empty($_SESSION['inventory_category_csrf'])||!is_string($b['csrf']??null)||!hash_equals($_SESSION['inventory_category_csrf'],$b['csrf']))fail('Security token expired. Reopen category management.',403);
if(!toolCategoryStorageReady($db))fail('Run api/migrate_tool_categories.php to enable category management.',503);
$name=preg_replace('/\s+/u',' ',trim((string)($b['name']??'')));
$icon=(string)($b['icon']??'');$code=strtoupper(trim((string)($b['code']??'')));
if(!$name||mb_strlen($name)>50||preg_match('/[\x00-\x1F\x7F]/u',$name))fail('Enter a category name of 1–50 characters.',422);
if(!isset(toolCategoryIcons()[$icon]))fail('Select an icon from the available icons.',422);
try{
    if($method==='PUT'){
        $q=$db->prepare('SELECT category_name FROM tool_sku_sequences WHERE category_name=?');$q->execute([$name]);
        $canonical=$q->fetchColumn();if($canonical===false)fail('Category not found.',404);
        $db->prepare('UPDATE tool_sku_sequences SET icon_name=? WHERE category_name=?')->execute([$icon,$canonical]);
        logAudit('CATEGORY_ICON_UPDATED','Updated icon for category '.$canonical);
    }else{
        if(!preg_match('/^[A-Z]{2,8}$/',$code))fail('Use a unique SKU prefix of 2–8 letters, such as PWR.',422);
        $db->prepare('INSERT INTO tool_sku_sequences(category_name,category_code,icon_name) VALUES (?,?,?)')->execute([$name,$code,$icon]);
        logAudit('CATEGORY_CREATED','Created category '.$name.' with SKU prefix '.$code);
    }
    ok(['categories'=>toolCategoryCatalog($db)]);
}catch(PDOException $e){
    if((int)($e->errorInfo[1]??0)===1062)fail('That category name or SKU prefix already exists.',409);
    error_log('Category save: '.$e->getMessage());fail('Could not save the category. Please try again.',500);
}