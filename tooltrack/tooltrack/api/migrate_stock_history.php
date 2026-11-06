<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/bootstrap.php';require_once dirname(__DIR__).'/includes/stock_lib.php';
installStockHistory(getDB());echo "Stock history ready; existing quantities and records unchanged.\n";