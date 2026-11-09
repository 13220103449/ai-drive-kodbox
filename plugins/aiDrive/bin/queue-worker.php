<?php
// Run via cron as the website owner; never exposed as an HTTP worker endpoint.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
define('MOD','plugin');define('ST','aiDrive');define('ACT','queueWorker');define('ACTION','plugin.aiDrive.queueWorker');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__,3).'/index.php';$_SERVER['REQUEST_URI']='/index.php';
ob_start();require dirname(__DIR__,3).'/config/config.php';think_config($GLOBALS['config']['databaseDefault']);think_config($GLOBALS['config']['database']);KodIO::initSystemPath();require dirname(__DIR__).'/app.php';
try{$plugin=new aiDrivePlugin();$result=$plugin->runQueuedUploads();ob_end_clean();echo json_encode(array('processed'=>!!$result,'job'=>$result),JSON_UNESCAPED_UNICODE)."\n";}catch(Throwable $e){ob_end_clean();fwrite(STDERR,"AI Drive queue worker failed; inspect private application logs\n");exit(1);}
