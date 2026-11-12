<?php
// Historical filename retained for existing integrations. Only physical incident actions remain active.
function rpAction(PDO $db,array $b,array $actor):array {
    if(($b['action']??'')!=='inventory_resolution')throw new ReplacementPaymentError('Financial actions have been retired. Use the inventory resolution workflow.');
    $id=(int)($b['id']??0);$token=rpToken($b);$hash=rpPayload($b);
    $db->beginTransaction();
    try {
        // Match checkout/return/disposal lock order: tool first, then case.
        $q=$db->prepare('SELECT tool_id FROM replacement_requests WHERE id=?');$q->execute([$id]);$toolId=$q->fetchColumn();
        if($toolId)$db->prepare('SELECT id FROM tools WHERE id=? FOR UPDATE')->execute([$toolId]);
        $r=rpRow($db,$id,true);
        if($replay=rpReplay($db,$token,$hash,$actor)){$db->commit();return ['id'=>$replay,'duplicate'=>true];}
        if(!isset($b['version'])||(int)$b['version']!==(int)$r['payment_version'])throw new ReplacementPaymentError('This incident changed. Refresh before saving.');
        caseInventory($db,$r,$b,$actor);
        $db->prepare('UPDATE replacement_requests SET payment_version=payment_version+1 WHERE id=?')->execute([$id]);
        $after=rpRow($db,$id);$notes=mb_substr(trim((string)($b['notes']??'')),0,500);
        $affected=$b['quantity']??max(0,(int)($r['incident_quantity']??$r['quantity_needed'])-(int)$r['resolved_quantity']);
        rpEvent($db,$id,'inventory_resolution',$r,$after,($b['inventory_action']??'').': '.$affected.' unit(s); '.$notes,$token,$hash,$actor);
        $db->commit();logAudit('incident_inventory','Incident #'.$id.': '.($b['inventory_action']??'').'; '.$notes);
        return ['id'=>$id,'inventory_status'=>$after['inventory_status']];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
