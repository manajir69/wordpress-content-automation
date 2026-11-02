<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Services\BlogAutomationService;

try {
    $db=Database::connection();
    $results=(new BlogAutomationService($db))->runDueSites();
    $summary=['checked_at'=>date('c'),'sites'=>count($results),'success'=>0,'failed'=>0,'skipped'=>0];
    foreach($results as $result){$key=in_array($result['result'],['success','failed','skipped'],true)?$result['result']:'skipped';$summary[$key]++;}
    $heartbeat=$db->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id,details) VALUES(NULL,"blog.cron_checked","system",NULL,?)');
    $heartbeat->execute([json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    if($results===[]){echo '['.date('c').'] SKIPPED: No enabled WordPress websites.'.PHP_EOL;exit(0);}
    $failed=false;
    foreach($results as $result){echo '['.date('c').'] '.strtoupper($result['result']).' ['.$result['site_name'].']: '.$result['message'].PHP_EOL;if($result['result']==='failed')$failed=true;}
    exit($failed?1:0);
} catch(Throwable $e){
    fwrite(STDERR,'['.date('c').'] FAILED: '.substr($e->getMessage(),0,1500).PHP_EOL);
    exit(1);
}
