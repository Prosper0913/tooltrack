<?php
require_once __DIR__.'/sku_lib.php';
function toolCategoryIcons(): array {
    return ['fa-box'=>'Box','fa-utensils'=>'Utensils','fa-fire-burner'=>'Cookware','fa-ruler'=>'Measurement','fa-toolbox'=>'Toolbox','fa-plate-wheat'=>'Dinnerware','fa-martini-glass'=>'Glassware','fa-screwdriver-wrench'=>'Tools','fa-hammer'=>'Hammer','fa-bolt'=>'Electrical','fa-microchip'=>'Electronics','fa-hard-hat'=>'Safety','fa-shield-halved'=>'Shield','fa-gears'=>'Equipment','fa-scissors'=>'Scissors','fa-flask'=>'Laboratory','fa-laptop'=>'Computer','fa-camera'=>'Camera','fa-paintbrush'=>'Painting','fa-broom'=>'Cleaning','fa-wrench'=>'Wrench','fa-plug'=>'Power'];
}
function toolCategoryDefaultIcons(): array {
    return ['Utensils'=>'fa-utensils','Cookware'=>'fa-fire-burner','Measuring Tools'=>'fa-ruler','Accessories'=>'fa-toolbox','Dinnerware'=>'fa-plate-wheat','Cutleries'=>'fa-utensils','Glassware'=>'fa-martini-glass'];
}
function toolCategoryStorageReady(PDO $db): bool {
    return (bool)$db->query("SHOW COLUMNS FROM tool_sku_sequences LIKE 'icon_name'")->fetch();
}
function toolCategoryCatalog(PDO $db): array {
    $ready=toolCategoryStorageReady($db);
    $rows=$db->query('SELECT category_name AS name,category_code AS code,'.($ready?'icon_name':"'fa-box' AS icon_name").' FROM tool_sku_sequences ORDER BY category_name')->fetchAll();
    foreach($rows as &$row){
        if(!$ready)$row['icon_name']=toolCategoryDefaultIcons()[$row['name']]??'fa-box';
        if(!isset(toolCategoryIcons()[$row['icon_name']]))$row['icon_name']='fa-box';
    }unset($row);
    return $rows;
}
function installToolCategoryStorage(PDO $db): void {
    if(PHP_SAPI!=='cli')throw new LogicException('Category migration is CLI only.');
    if(!toolCategoryStorageReady($db)){
        $db->exec("ALTER TABLE tool_sku_sequences ADD COLUMN icon_name VARCHAR(40) NOT NULL DEFAULT 'fa-box'");
        $q=$db->prepare('UPDATE tool_sku_sequences SET icon_name=? WHERE category_name=?');
        foreach(toolCategoryDefaultIcons() as $name=>$icon)$q->execute([$icon,$name]);
    }
}