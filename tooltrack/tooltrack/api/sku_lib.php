<?php
// Persistent category counters and an issued-code register. Existing tool codes are never rewritten.
function toolSkuCategories(): array {
    return ['Utensils'=>'UTL','Cookware'=>'CKW','Measuring Tools'=>'MEA','Accessories'=>'ACC',
            'Dinnerware'=>'DIN','Cutleries'=>'CUT','Glassware'=>'GLS'];
}
function toolSkuFormat(string $categoryCode,int $number): string {
    if($number<1 || $number>4294967295) throw new RuntimeException('SKU sequence capacity reached.');
    return 'FPST-'.$categoryCode.'-'.str_pad((string)$number,4,'0',STR_PAD_LEFT);
}
function toolSkuPreview(PDO $db,string $category): string {
    $q=$db->prepare('SELECT category_code,last_number FROM tool_sku_sequences WHERE category_name=?');
    $q->execute([$category]);$row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('Select a supported tool category.');
    return toolSkuFormat($row['category_code'],(int)$row['last_number']+1);
}
// Must run inside the same transaction as INSERT tools. The category row serializes creators.
function allocateToolSku(PDO $db,string $category): string {
    if(!$db->inTransaction()) throw new LogicException('SKU allocation requires a transaction.');
    $q=$db->prepare('SELECT category_code,last_number FROM tool_sku_sequences WHERE category_name=? FOR UPDATE');
    $q->execute([$category]);$row=$q->fetch();
    if(!$row) throw new InvalidArgumentException('Select a supported tool category.');
    $next=(int)$row['last_number'];
    for($attempt=0;$attempt<100;$attempt++){
        $sku=toolSkuFormat($row['category_code'],++$next);
        $db->prepare('UPDATE tool_sku_sequences SET last_number=? WHERE category_name=?')->execute([$next,$category]);
        try {
            $db->prepare('INSERT INTO tool_sku_issued(sku,category_code,sequence_number) VALUES (?,?,?)')->execute([$sku,$row['category_code'],$next]);
            return $sku;
        }catch(PDOException $e){
            if((int)($e->errorInfo[1]??0)!==1062) throw $e;
            // Already issued, even if the tool was deleted. Advance; never clear the register.
        }
    }
    throw new RuntimeException('Could not allocate an unused SKU.');
}
// Explicit CLI migration only; never run DDL from a web request.
function installToolSkuStorage(PDO $db): void {
    if(PHP_SAPI!=='cli') throw new LogicException('SKU migration is CLI only.');
    $schema=(string)$db->query('SELECT DATABASE()')->fetchColumn();
    $lock='tooltrack_skus_'.substr(hash('sha256',$schema),0,24);
    $q=$db->prepare('SELECT GET_LOCK(?,15)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1) throw new RuntimeException('Another SKU migration is running.');
    try {
        $indexes=[];
        foreach($db->query('SHOW INDEX FROM tools')->fetchAll() as $i){
            if((int)$i['Non_unique']===0)$indexes[$i['Key_name']][]=$i;
        }
        $unique=false;
        foreach($indexes as $columns) if(count($columns)===1 && $columns[0]['Column_name']==='code' && $columns[0]['Sub_part']===null)$unique=true;
        if(!$unique){
            if($db->query('SELECT code FROM tools GROUP BY code HAVING COUNT(*)>1 LIMIT 1')->fetch()) throw new RuntimeException('Existing tool codes are not unique; migration stopped without rewriting records.');
            $db->exec('ALTER TABLE tools ADD UNIQUE KEY uq_tool_sku(code)');
        }
        $db->exec("CREATE TABLE IF NOT EXISTS tool_sku_sequences (
          category_name VARCHAR(80) NOT NULL PRIMARY KEY,
          category_code VARCHAR(8) NOT NULL UNIQUE,
          last_number INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // No foreign key or cascading delete: issued SKUs survive physical deletion of a tool.
        $db->exec("CREATE TABLE IF NOT EXISTS tool_sku_issued (
          sku VARCHAR(50) NOT NULL PRIMARY KEY,
          category_code VARCHAR(8) NULL,
          sequence_number INT UNSIGNED NULL,
          tool_id INT NULL,
          issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          KEY idx_sku_sequence(category_code,sequence_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->beginTransaction();
        foreach(toolSkuCategories() as $category=>$code){
            $db->prepare('INSERT INTO tool_sku_sequences(category_name,category_code) VALUES (?,?) ON DUPLICATE KEY UPDATE category_name=VALUES(category_name)')->execute([$category,$code]);
            $q=$db->prepare('SELECT category_code FROM tool_sku_sequences WHERE category_name=? FOR UPDATE');$q->execute([$category]);
            if($q->fetchColumn()!==$code) throw new RuntimeException('An existing SKU category mapping differs; migration stopped.');
        }
        $register=$db->prepare('INSERT INTO tool_sku_issued(sku,category_code,sequence_number,tool_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE tool_id=COALESCE(tool_id,VALUES(tool_id))');
        $known=array_values(toolSkuCategories());
        foreach($db->query('SELECT id,code FROM tools')->fetchAll() as $tool){
            $code=null;$number=null;
            if(preg_match('/^FPST-([A-Z]{2,8})-([0-9]{4,10})$/i',$tool['code'],$m) && in_array(strtoupper($m[1]),$known,true) && (int)$m[2]>0 && (int)$m[2]<=4294967295){$code=strtoupper($m[1]);$number=(int)$m[2];}
            $register->execute([$tool['code'],$code,$number,$tool['id']]);
        }
        $db->exec('UPDATE tool_sku_sequences s SET last_number=GREATEST(last_number,COALESCE((SELECT MAX(i.sequence_number) FROM tool_sku_issued i WHERE i.category_code=s.category_code),0))');
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}