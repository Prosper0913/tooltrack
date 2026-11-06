<?php
if(PHP_SAPI!=='cli')exit;
putenv('TOOLTRACK_TESDA_TEST=1');
require __DIR__.'/damage_loss_workflow.php';
