<?php
// Included by the isolated workflow runner; never access the live database from this file.
if(PHP_SAPI!=='cli'||!isset($db,$work))exit;
if(!preg_match('/^tooltrack_test_[a-f0-9]{12}$/',(string)$db->query('SELECT DATABASE()')->fetchColumn()))throw new RuntimeException('SKU tests require an isolated test database.');
require_once dirname(__DIR__).'/api/sku_lib.php';
function skuFields(string $category='Cookware',string $name='Test SKU tool'):array {
    return ['name'=>$name,'category'=>$category,'quantity'=>7,'min_stock'=>1,'price'=>2,'description'=>'SKU workflow test'];
}
$db->prepare('INSERT INTO tools(name,code,category,quantity,available) VALUES (?,?,?,2,2)')->execute(['Legacy SKU tool','SP-101','Utensils']);$legacy=(int)$db->lastInsertId();
$db->prepare('INSERT INTO tools(name,code,category,quantity,available) VALUES (?,?,?,2,2)')->execute(['Existing formatted SKU','FPST-CKW-0042','Cookware']);
$before=$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll();
installToolSkuStorage($db);
check($before===$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll(),'SKU migration preserves every existing code');
check((int)$db->query('SELECT COUNT(*) FROM tool_sku_issued')->fetchColumn()===count($before),'SKU migration permanently registers legacy codes');
foreach(toolSkuCategories() as $category=>$code){
    $preview=expectApi('SKU preview '.$category,'tools.php','GET',[],true,'Staff',['sku_preview'=>1,'category'=>$category]);
    check((bool)preg_match('/^FPST-'.$code.'-[0-9]{4,}$/',$preview['code']),'Consistent category code '.$code);
}
$counter=(int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn();
expectApi('Repeated preview is read-only','tools.php','GET',[],true,'Staff',['sku_preview'=>1,'category'=>'Cookware']);
check((int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn()===$counter,'Preview and category changes do not allocate SKUs');
$created=expectApi('Create generates SKU on server','tools.php','POST',array_merge(skuFields(),['code'=>'MANUAL-SKU-IGNORED']),true,'Staff');
check($created['code']==='FPST-CKW-0043','Existing formatted codes seed the category high-water mark');
$id=(int)$created['id'];
$q=$db->prepare('SELECT tool_id FROM tool_sku_issued WHERE sku=?');$q->execute([$created['code']]);check((int)$q->fetchColumn()===$id,'SKU assignment is saved with the tool record');
$db->prepare('INSERT INTO tools(name,code,category,quantity,available) VALUES (?,?,?,1,1)')->execute(['External SKU collision','FPST-CKW-0044','Cookware']);$external=(int)$db->lastInsertId();
$next=expectApi('Duplicate-key collision advances safely','tools.php','POST',skuFields());
check($next['code']==='FPST-CKW-0045','External code collision is not reused');
$issued=(int)$db->query('SELECT COUNT(*) FROM tool_sku_issued')->fetchColumn();
$counter=(int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn();
$edit=array_merge(skuFields(),['id'=>$id,'code'=>$created['code'],'quantity'=>10,'previous_quantity'=>7]);
$stock=expectApi('Add stock keeps SKU','tools.php','PUT',$edit);
check($stock['code']===$created['code']&&(int)$stock['quantity']===10&&(int)$stock['available']===10,'Stock addition preserves SKU and inventory math');
check((int)$db->query('SELECT COUNT(*) FROM tool_sku_issued')->fetchColumn()===$issued,'Adding stock issues no SKU');
$edit['previous_quantity']=10;$edit['category']='Utensils';
$changed=expectApi('Edit category keeps assigned SKU','tools.php','PUT',$edit);
check($changed['code']===$created['code']&&$changed['category']==='Utensils','Reclassification does not rewrite SKU');
check((int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn()===$counter,'Editing does not advance category counter');
$edit['code']='FPST-UTL-9999';expectApi('Backend blocks SKU editing','tools.php','PUT',$edit,false);
check(row('tools',$id)['code']===$created['code'],'Rejected SKU correction leaves record unchanged');
$legacyEdit=array_merge(skuFields('Utensils'),['id'=>$legacy,'code'=>'SP-999','quantity'=>2]);
expectApi('Legacy SKU editing blocked','tools.php','PUT',$legacyEdit,false);
check(row('tools',$legacy)['code']==='SP-101','Legacy SKU remains unchanged');
$db->prepare('DELETE FROM tools WHERE id=?')->execute([(int)$next['id']]);
$db->prepare('DELETE FROM tools WHERE id=?')->execute([$external]);
$afterDelete=expectApi('Create after deleting assigned SKUs','tools.php','POST',skuFields());
check($afterDelete['code']==='FPST-CKW-0046','Deleted highest SKU is never reused');
check((int)$db->query("SELECT COUNT(*) FROM tool_sku_issued WHERE sku IN ('FPST-CKW-0044','FPST-CKW-0045')")->fetchColumn()===2,'Issued register survives physical deletion');
$race=concurrentApi('tools.php','POST',skuFields('Cookware','Concurrent SKU tool'),8);
check(count(array_filter($race,fn($r)=>$r['success']))===8,'Eight simultaneous tool creations succeed');
$codes=array_map(fn($r)=>$r['data']['code'],$race);sort($codes);
check(count(array_unique($codes))===8,'Concurrent tools have distinct SKUs');
$expected=array_map(fn($n)=>toolSkuFormat('CKW',$n),range(47,54));
check($codes===$expected,'Concurrent category sequence is ordered without duplicated numbers');
$utensil=expectApi('Independent category sequence','tools.php','POST',skuFields('Utensils'));
check($utensil['code']==='FPST-UTL-0001','Each category starts its own sequence');
expectApi('Invalid category is rejected','tools.php','POST',skuFields('Unrecognized'),false);
$counter=(int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn();
$issued=(int)$db->query('SELECT COUNT(*) FROM tool_sku_issued')->fetchColumn();
$db->exec("CREATE TRIGGER sku_test_fail BEFORE INSERT ON tools FOR EACH ROW BEGIN IF NEW.name='Test forced failure' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated failed save'; END IF; END");
try{expectApi('Failed save returns safe error','tools.php','POST',skuFields('Cookware','Test forced failure'),false);}
finally{$db->exec('DROP TRIGGER sku_test_fail');}
check((int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn()===$counter,'Failed save rolls back category counter');
check((int)$db->query('SELECT COUNT(*) FROM tool_sku_issued')->fetchColumn()===$issued,'Failed save leaves no orphan SKU assignment');
check((int)$db->query("SELECT COUNT(*) FROM tools WHERE name='Test forced failure'")->fetchColumn()===0,'Failed save inserts no tool');
$afterFailure=expectApi('Successful save after failure','tools.php','POST',skuFields());
check($afterFailure['code']==='FPST-CKW-0055','Counter resumes correctly after rollback');
$duplicateBlocked=false;
try{$db->prepare('INSERT INTO tools(name,code,category,quantity,available) VALUES (?,?,?,1,1)')->execute(['Duplicate attempt',$afterFailure['code'],'Cookware']);}
catch(PDOException $e){$duplicateBlocked=(int)($e->errorInfo[1]??0)===1062;}
check($duplicateBlocked,'Database unique constraint rejects duplicated SKU');
$before=$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll();installToolSkuStorage($db);
check($before===$db->query('SELECT id,code FROM tools ORDER BY id')->fetchAll(),'Migration rerun is idempotent and preserves codes');
check((int)$db->query("SELECT last_number FROM tool_sku_sequences WHERE category_name='Cookware'")->fetchColumn()===55,'Migration never resets high-water mark after deletions');
check((int)$db->query("SELECT COUNT(*) FROM stock_history WHERE tool_id=$id AND transaction_type='Stock Added' AND event_kind<>'registration'")->fetchColumn()===1,'Stock addition history is preserved');
invariant();check(true,'Inventory conservation after SKU creation, editing and deletion');
$skuLoan=expectApi('Generated SKU can be borrowed','transactions.php','POST',['type'=>'borrow','tool_code'=>$afterFailure['code'],'borrower_id'=>1,'qty'=>2,'due_date'=>date('Y-m-d',strtotime('+1 day')),'due_time'=>'14:30']);
$skuLoanId=(int)$skuLoan['id'];
expectApi('Generated SKU supports mixed return and missing report','transactions.php','POST',['type'=>'return','borrow_txn_id'=>$skuLoanId,'returnee_name'=>'Test Borrower','request_token'=>'new_sku_return','items'=>['good'=>1,'missing'=>1],'missing_reason'=>'Generated SKU missing test','missing_date'=>date('Y-m-d')]);
$skuCase=(int)$db->query("SELECT id FROM missing_reports WHERE borrow_txn_id=$skuLoanId")->fetchColumn();
check(row('missing_reports',$skuCase)['status']==='confirmed','Generated SKU missing case is linked correctly');
expectApi('Recover the generated-SKU original','missing_tools.php','POST',['action'=>'recover','id'=>$skuCase,'qty'=>1,'to'=>'good']);
$restored=row('tools',(int)$afterFailure['id']);
check($restored['code']===$afterFailure['code']&&(int)$restored['available']===7&&(int)$restored['missing_qty']===0,'Generated SKU survives borrow, return and recovery with exact stock totals');
$db->prepare('INSERT INTO tools(name,code,category,quantity,available) VALUES (?,?,?,1,1)')->execute(['Lowercase historical SKU','fpst-gls-0012','Glassware']);
$lowerId=(int)$db->lastInsertId();installToolSkuStorage($db);
check(row('tools',$lowerId)['code']==='fpst-gls-0012','Migration preserves lowercase historical code exactly');
$glass=expectApi('Category sequence respects case-insensitive existing SKUs','tools.php','POST',skuFields('Glassware'));
check($glass['code']==='FPST-GLS-0013','Case-insensitive database uniqueness is respected while seeding');
invariant();check(true,'All SKU-linked inventory workflows remain consistent');