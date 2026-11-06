<?php
function installLiveSync(PDO $db): int {
$db->exec('CREATE TABLE IF NOT EXISTS live_sync_versions (table_name VARCHAR(64) PRIMARY KEY, revision BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB');
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME <> 'live_sync_versions'")->fetchAll(PDO::FETCH_COLUMN);
foreach($tables as $table) {
    $quoted='`'.str_replace('`','``',$table).'`';
    $literal=$db->quote($table);
    $db->exec("INSERT IGNORE INTO live_sync_versions(table_name) VALUES ($literal)");
    foreach(['INSERT','UPDATE','DELETE'] as $event) {
        $name='tt_live_'.substr(hash('sha256',$table.'_'.$event),0,24);
        $exists=$db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
        $exists->execute([$name]);
        if(!$exists->fetchColumn()) $db->exec("CREATE TRIGGER `$name` AFTER $event ON $quoted FOR EACH ROW UPDATE live_sync_versions SET revision=revision+1 WHERE table_name=$literal");
    }
}
return count($tables);
}
