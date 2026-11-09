<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
define('MOD','plugin');define('ST','aiDrive');define('ACT','verifyUpload');define('ACTION','plugin.aiDrive.verifyUpload');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__,3).'/index.php';$_SERVER['REQUEST_URI']='/index.php';
ob_start();require dirname(__DIR__,3).'/config/config.php';think_config($GLOBALS['config']['databaseDefault']);think_config($GLOBALS['config']['database']);KodIO::initSystemPath();require dirname(__DIR__).'/app.php';
try{$job=json_decode(stream_get_contents(STDIN),true);if(!is_array($job))throw new RuntimeException('Invalid verification input');$plugin=new aiDrivePlugin();$result=$plugin->verifyStoredUpload($job);ob_end_clean();echo json_encode(array('ok'=>true,'result'=>$result))."\n";}catch(Throwable $e){ob_end_clean();echo json_encode(array('ok'=>false,'error'=>in_array($e->getMessage(),array('Fresh upload stat mismatch','Fresh upload content mismatch'),true)?$e->getMessage():'Storage verification failed'))."\n";exit(1);}
