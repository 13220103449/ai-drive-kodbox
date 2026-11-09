<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/lib/ApplicationBackup.class.php';
if(count($argv)<4){fwrite(STDERR,"Usage: application-backup.php create|verify|stage-restore SITE_ROOT PRIVATE_DIRECTORY [ARCHIVE] [NEW_RESTORE_DIRECTORY]\n");exit(2);}
$marker='';
try{$service=new AiDriveApplicationBackup($argv[2],$argv[3]);switch($argv[1]){case 'create':$path=realpath($argv[2]).'/data/ai-drive/maintenance.flag';$handle=@fopen($path,'x');if(!$handle)throw new RuntimeException('Maintenance already active; do not remove another task marker');fwrite($handle,strval(time()));fclose($handle);$marker=$path;$result=$service->create();break;case 'verify':$manifest=$service->verify($argv[4]);$result=array('verified'=>true,'files'=>count($manifest['files']),'scope'=>$manifest['scope']);break;case 'stage-restore':$result=$service->stageRestore($argv[4],$argv[5]);break;default:throw new RuntimeException('Unknown backup operation');}echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");$failed=true;}finally{if($marker&&is_file($marker))unlink($marker);}if(isset($failed))exit(1);
