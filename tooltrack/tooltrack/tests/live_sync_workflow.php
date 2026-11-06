<?php
if(PHP_SAPI!=='cli')exit;
putenv('TOOLTRACK_LIVE_SYNC_TEST=1');
require __DIR__.'/damage_loss_workflow.php';
