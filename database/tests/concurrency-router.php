<?php
/** Test-only timing instrumentation; no delay or change to application voting code. */
declare(strict_types=1);
$trace=getenv('COOPVOTE_CONCURRENCY_TRACE');
if (getenv('COOPVOTE_ENV')!=='test' || !$trace || !str_starts_with($trace,'/tmp/coopvote-db-tests-perf-')) {
    http_response_code(404);exit;
}
if (($_GET['r']??'')==='voter/vote') {
    $id=$_SERVER['HTTP_X_CONCURRENCY_REQUEST']??'';
    if (!preg_match('/^[a-z0-9-]+$/D',$id)) {http_response_code(400);exit;}
    $record=static function(string $event) use($trace,$id): void {
        file_put_contents($trace,json_encode(['request'=>$id,'event'=>$event,'pid'=>getmypid(),'monotonic_ns'=>hrtime(true),'http_status'=>http_response_code()])."\n",FILE_APPEND|LOCK_EX);
    };
    $record('start');
    register_shutdown_function(static function() use($record): void {$record('end');});
}
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/api/index.php') {
    require dirname(__DIR__,2).'/api/index.php';return true;
}
return require __DIR__.'/router.php';
