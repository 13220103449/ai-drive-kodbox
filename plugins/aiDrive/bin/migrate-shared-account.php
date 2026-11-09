<?php
/**
 * Shared-account migration CLI (v0.8.0).
 *
 * Usage (run inside a PHP CLI with access to the KodBox tree, e.g. the php82 container):
 *   php migrate-shared-account.php status
 *   php migrate-shared-account.php prepare --source-user-id 6 --name agent
 *   php migrate-shared-account.php plan --agent "ZCode"
 *   php migrate-shared-account.php migrate --agent "ZCode" --yes
 *
 * `prepare` converts an EMPTY dedicated account into the shared account (renames it,
 * joins 智能体, stores sharedUserID in the plugin config). `migrate` copies the Agent's
 * old personal space into the shared account under <homeDir>/, reconciles the tree
 * (paths, sizes, sampled SHA-256), re-points share/version/trash references and finally
 * switches the Agent record. The Agent token and API semantics never change.
 */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
define('MOD','plugin');define('ST','aiDrive');define('ACT','migrateCli');define('ACTION','plugin.aiDrive.migrateCli');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__,3).'/index.php';$_SERVER['REQUEST_URI']='/index.php';
ob_start();require dirname(__DIR__,3).'/config/config.php';think_config($GLOBALS['config']['databaseDefault']);think_config($GLOBALS['config']['database']);KodIO::initSystemPath();require dirname(__DIR__).'/app.php';
ob_end_clean();

function cliArg($argv,$key,$default=''){
	$last=count($argv)-1;
	foreach($argv as $index=>$value){
		if($value==='--'.$key) return ($index<$last && isset($argv[$index+1]) && strpos($argv[$index+1],'--')!==0)?$argv[$index+1]:'1';
		if(strpos($value,'--'.$key.'=')===0) return substr($value,strlen('--'.$key.'='));
	}
	return $default;
}
function cliFail($message){fwrite(STDERR,'migrate-shared-account: '.preg_replace('#aidv_[a-f0-9]+#i','[redacted]',strval($message))."\n");exit(1);}

try{
	$plugin=new aiDrivePlugin();
	KodUser::init(1); // act as admin; IO and models need an identity
	include_once __DIR__.'/../lib/SharedAccountMigration.class.php';
	$migration=new AiDriveSharedAccountMigration($plugin);
	$command=strval(isset($argv[1])?$argv[1]:'');
	switch($command){
		case 'status': $result=$migration->status();break;
		case 'prepare':
			$result=$migration->prepare(intval(cliArg($argv,'source-user-id',0)),cliArg($argv,'name',''));
			break;
		case 'plan': $result=$migration->plan(cliArg($argv,'agent',''));break;
		case 'migrate':
			if(cliArg($argv,'yes','')==='') cliFail('refusing to migrate without --yes (data copy is irreversible only in the sense of disk usage; rerun with --yes)');
			$result=$migration->migrate(cliArg($argv,'agent',''),intval(cliArg($argv,'idle-minutes',10)));
			break;
		default:
			fwrite(STDERR,"usage: php migrate-shared-account.php status|prepare|plan|migrate\n".
				"  prepare --source-user-id <id> --name <sharedName>\n".
				"  plan --agent \"<agentName>\"\n".
				"  migrate --agent \"<agentName>\" --yes [--idle-minutes 10]\n");exit(1);
	}
	echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $error){cliFail($error->getMessage());}
