<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
define('MOD','plugin');define('ST','aiDrive');define('ACT','upgradeCheck');define('ACTION','plugin.aiDrive.upgradeCheck');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__,3).'/index.php';$_SERVER['REQUEST_URI']='/index.php';
ob_start();require dirname(__DIR__,3).'/config/config.php';think_config($GLOBALS['config']['databaseDefault']);think_config($GLOBALS['config']['database']);KodIO::initSystemPath();require dirname(__DIR__).'/app.php';
try{$plugin=new aiDrivePlugin();$result=$plugin->runStorageAcceptance();ob_end_clean();echo json_encode($result)."\n";}catch(Throwable $e){ob_end_clean();$message=preg_replace('#https?://[^\s]+|aidv_[a-f0-9]+#i','[redacted]',$e->getMessage());fwrite(STDERR,'Storage acceptance failed ('.get_class($e).'): '.$message."; no Token was rotated\n");exit(1);}
