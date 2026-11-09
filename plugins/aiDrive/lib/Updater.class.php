<?php

/** Checksum-verified updater; a checksum is not a publisher signature. */
class AiDriveUpdater {
	private $plugin;
	private $repo;
	private $channel;
	public function __construct($plugin){
		$this->plugin=$plugin;$config=$plugin->getConfig();$this->repo=trim(_get($config,'githubRepo','13220103449/ai-drive-kodbox'));$this->channel=_get($config,'updateChannel','stable')==='beta'?'beta':'stable';
		if(!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#',$this->repo)) show_json('GitHub repository format is invalid',false);
	}

	public function check(){
		$current=$this->currentVersion();$release=$this->release();$latest=ltrim(strval(_get($release,'tag_name','')),'vV');
		if(!$latest || !preg_match('/^\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.-]+)?$/',$latest)) throw new Exception('GitHub Release version is invalid');
		$assets=$this->releaseAssets($release,$latest);
		return array('repo'=>$this->repo,'channel'=>$this->channel,'currentVersion'=>$current,'latestVersion'=>$latest,'hasUpdate'=>version_compare($latest,$current,'>'),
			'notes'=>strval(_get($release,'body','')),'publishedAt'=>strval(_get($release,'published_at','')),'zipSize'=>intval(_get($assets,'zip.size',0)));
	}

	public function install(){
		if(!class_exists('ZipArchive')) throw new Exception('PHP zip extension is required');
		mk_dir(TEMP_FILES);$lockPath=TEMP_FILES.'aidrive-update.lock';$lock=fopen($lockPath,'c+');
		if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new Exception('Another AI Drive update is running');
		$work=TEMP_FILES.'aidrive-update-'.date('YmdHis').'-'.rand_string(6).'/';mk_dir($work);$current=$this->currentVersion();$latest='';$backup='';$transaction=null;$maintenanceMarker='';
		try{
			$release=$this->release();$latest=ltrim(strval(_get($release,'tag_name','')),'vV');
			if(!version_compare($latest,$current,'>')) return array('updated'=>false,'version'=>$current,'message'=>'already latest');
			$assets=$this->releaseAssets($release,$latest);$zipFile=$work.'update.zip';
			$this->download($assets['zip']['browser_download_url'],$zipFile);$checksum=trim($this->request($assets['checksum']['browser_download_url']));
			if(!preg_match('/\b([a-f0-9]{64})\b/i',$checksum,$match)) throw new Exception('Release checksum file is invalid');
			$actual=hash_file('sha256',$zipFile);if(!hash_equals(strtolower($match[1]),strtolower($actual))) throw new Exception('Update package SHA-256 verification failed');
			$stage=$work.'stage/';mk_dir($stage);$this->extractPackage($zipFile,$stage);
			$package=$stage.'plugins/aiDrive/package.json';if(!is_file($package)) throw new Exception('Update package does not contain AI Drive plugin');
			$meta=json_decode(file_get_contents($package),true);if(strval(_get($meta,'version',''))!==$latest) throw new Exception('Release tag and package version do not match');
			$target=PLUGIN_DIR.'aiDrive/';if(!path_writeable($target)) throw new Exception('AI Drive plugin directory is not writable');
			$this->validatePhpTree($stage.'plugins/aiDrive/');
			$webdavPatch=$stage.'plugins/webdav/php/webdavServerKod.class.php';if(is_file($webdavPatch))$this->validatePhpTree($stage.'plugins/webdav/php/');
			$privateDirectory=trim(strval(_get($this->plugin->getConfig(),'privateBackupDirectory','')));if(!$privateDirectory)throw new RuntimeException('Configure private application backups before upgrading');
			$marker=DATA_PATH.'ai-drive/maintenance.flag';$handle=@fopen($marker,'x');if(!$handle)throw new RuntimeException('Application maintenance is already active');fwrite($handle,strval(time()));fclose($handle);$maintenanceMarker=$marker;
			include_once(__DIR__.'/ApplicationBackup.class.php');$applicationBackup=new AiDriveApplicationBackup(BASIC_PATH,$privateDirectory);$applicationSnapshot=$applicationBackup->create();
			$backup=DATA_PATH.'update-backup/aiDrive-'.$current.'-'.date('YmdHis').'/';mk_dir($backup);
			include_once(__DIR__.'/UpdateTransaction.class.php');$transaction=new AiDriveUpdateTransaction($backup);
			$transaction->replaceTree($stage.'plugins/aiDrive/',$target,'aiDrive');
			if(is_file($webdavPatch)){
				$transaction->replaceTree($stage.'plugins/webdav/php/',PLUGIN_DIR.'webdav/php/','webdav');
			}
			$installed=$this->currentVersion();if($installed!==$latest || !$this->healthCheck())throw new Exception('Installed version structural health check failed');
			$this->runAcceptance();
			$this->record($current,$installed,'success',$backup,'SHA-256, PHP syntax and storage lifecycle acceptance passed');
			return array('updated'=>true,'version'=>$installed,'previousVersion'=>$current,'backup'=>str_replace(BASIC_PATH,'',$backup),'applicationBackup'=>$applicationSnapshot,'acceptance'=>'internal storage lifecycle passed; external client verification also recommended');
		}catch(Throwable $error){if($transaction){try{$transaction->rollback();}catch(Throwable $rollbackError){$this->record($current,$latest?$latest:$current,'rollback_failed',$backup,'Manual code recovery required');throw new RuntimeException('Update failed and automatic code recovery failed; preserve backup and contact administrator');}}$this->record($current,$latest?$latest:$current,'failed',$backup,$error->getMessage());throw $error;
		}finally{if($maintenanceMarker && is_file($maintenanceMarker))unlink($maintenanceMarker);if(is_dir($work)) del_dir(rtrim($work,'/'));flock($lock,LOCK_UN);fclose($lock);}
	}
	public function history($limit=30){$list=Model('plugin_ai_drive_update')->order('id desc')->limit(min(max(intval($limit),1),100))->select();return $list?$list:array();}
	public function rollback($id){
		$item=Model('plugin_ai_drive_update')->where(array('id'=>intval($id),'status'=>'success'))->find();if(!$item)throw new Exception('Update history entry was not found');
		$backup=strval($item['backupPath']);if(!$backup || !is_dir($backup))throw new Exception('Update backup is unavailable');$from=$this->currentVersion();$target=PLUGIN_DIR.'aiDrive/';
		$currentBackup=DATA_PATH.'update-backup/aiDrive-'.$from.'-before-rollback-'.date('YmdHis').'/';include_once(__DIR__.'/UpdateTransaction.class.php');$transaction=new AiDriveUpdateTransaction($currentBackup);
		$source=is_file($backup.'/transaction.json')?$backup.'/aiDrive/':$backup;if(!is_file($source.'app.php'))throw new RuntimeException('Selected code backup is incomplete');
		try{$transaction->replaceTree($source,$target,'aiDrive');if(is_dir($backup.'/webdav/'))$transaction->replaceTree($backup.'/webdav/',PLUGIN_DIR.'webdav/php/','webdav');if(!$this->healthCheck())throw new Exception('Rollback health check failed');$this->runAcceptance();}catch(Throwable $error){$transaction->rollback();throw $error;}
		$to=$this->currentVersion();$this->record($from,$to,'rollback',$currentBackup,'Rollback completed and health check passed');return array('rolledBack'=>true,'fromVersion'=>$from,'version'=>$to,'safetyBackup'=>str_replace(BASIC_PATH,'',$currentBackup));
	}
	private function healthCheck(){
		$package=$this->plugin->pluginPath.'package.json';$app=$this->plugin->pluginPath.'app.php';$store=$this->plugin->pluginPath.'lib/AgentStore.class.php';if(!is_file($package)||!is_file($app)||!is_file($store))return false;
		$meta=json_decode(file_get_contents($package),true);return is_array($meta)&&preg_match('/^\d+\.\d+\.\d+/',strval(_get($meta,'version','')));
	}
	private function validatePhpTree($directory){$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS));foreach($iterator as $entry){if($entry->isFile()&&strtolower($entry->getExtension())==='php'){try{token_get_all(file_get_contents($entry->getPathname()),TOKEN_PARSE);}catch(ParseError $e){throw new RuntimeException('Update PHP syntax check failed: '.$entry->getFilename());}}}}
	private function runAcceptance(){
		if(!function_exists('proc_open'))throw new RuntimeException('CLI acceptance is unavailable; refusing an unverified upgrade');
		$cli=PHP_SAPI==='cli'?PHP_BINARY:dirname(dirname(PHP_BINARY)).'/bin/php';if(!is_executable($cli))throw new RuntimeException('PHP CLI is unavailable; refusing an unverified upgrade');
		$script=PLUGIN_DIR.'aiDrive/bin/upgrade-check.php';if(!is_file($script))throw new RuntimeException('Update lacks storage acceptance checker');
		$descriptors=array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w'));
		$process=proc_open(array($cli,$script),$descriptors,$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch storage acceptance');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);$result=json_decode(trim($output),true);
		if($status!==0||!is_array($result)||empty($result['ok']))throw new RuntimeException('Post-upgrade storage acceptance failed; code will be rolled back');
	}
	private function record($from,$to,$status,$backup,$detail){Model('plugin_ai_drive_update')->setDataAuto(false);Model('plugin_ai_drive_update')->add(array('fromVersion'=>$from,'toVersion'=>$to,'status'=>$status,'backupPath'=>$backup,'detail'=>mb_substr($detail,0,1000),'createTime'=>time()));}

	private function currentVersion(){
		$meta=json_decode(file_get_contents($this->plugin->pluginPath.'package.json'),true);return strval(_get($meta,'version','0.0.0'));
	}
	private function release(){
		if($this->channel==='stable'){$data=json_decode($this->request('https://api.github.com/repos/'.$this->repo.'/releases/latest'),true);if(!is_array($data)||_get($data,'draft',false)||_get($data,'prerelease',false))throw new Exception('No stable GitHub Release is available');return $data;}
		$list=json_decode($this->request('https://api.github.com/repos/'.$this->repo.'/releases?per_page=10'),true);foreach(is_array($list)?$list:array() as $item){if(!_get($item,'draft',false))return $item;}throw new Exception('No beta GitHub Release is available');
	}
	private function releaseAssets($release,$version){
		$zipName='ai-drive-update-v'.$version.'.zip';$checksumName=$zipName.'.sha256';$result=array();
		foreach(_get($release,'assets',array()) as $asset){if(_get($asset,'name')===$zipName)$result['zip']=$asset;if(_get($asset,'name')===$checksumName)$result['checksum']=$asset;}
		if(empty($result['zip']) || empty($result['checksum'])) throw new Exception('Release update assets are incomplete');return $result;
	}
	private function request($url){
		$this->assertGithubUrl($url);$ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>$this->caBundle(),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_USERAGENT=>'AI-Drive-Updater/1.0',CURLOPT_HTTPHEADER=>array('Accept: application/vnd.github+json','X-GitHub-Api-Version: 2022-11-28')));
		$data=curl_exec($ch);$code=intval(curl_getinfo($ch,CURLINFO_HTTP_CODE));$error=curl_error($ch);curl_close($ch);
		if($data===false || $code<200 || $code>=300) throw new Exception('GitHub request failed'.($error?': '.$error:' (HTTP '.$code.')'));return $data;
	}
	private function download($url,$file){
		$this->assertGithubUrl($url);$handle=fopen($file,'wb');if(!$handle) throw new Exception('Cannot create temporary update file');
		$ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_FILE=>$handle,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CAINFO=>$this->caBundle(),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>300,CURLOPT_USERAGENT=>'AI-Drive-Updater/1.0'));$ok=curl_exec($ch);$code=intval(curl_getinfo($ch,CURLINFO_HTTP_CODE));$error=curl_error($ch);curl_close($ch);fclose($handle);
		if(!$ok || $code<200 || $code>=300) throw new Exception('Update download failed'.($error?': '.$error:' (HTTP '.$code.')'));
	}
	private function assertGithubUrl($url){$host=strtolower(parse_url($url,PHP_URL_HOST));if(!in_array($host,array('api.github.com','github.com','objects.githubusercontent.com','release-assets.githubusercontent.com'))) throw new Exception('Untrusted update host');}
	private function caBundle(){
		$bundle=$this->plugin->pluginPath.'lib/data/cacert.pem';if(is_file($bundle))return $bundle;
		$configured=ini_get('curl.cainfo');if($configured && is_file($configured))return $configured;
		throw new Exception('No trusted CA certificate bundle is available');
	}
	private function extractPackage($file,$stage){
		$zip=new ZipArchive();if($zip->open($file)!==true) throw new Exception('Cannot open update package');
		for($i=0;$i<$zip->numFiles;$i++){
			$rawName=$zip->getNameIndex($i);$name=str_replace('\\','/',$rawName);if($name===''||substr($name,-1)==='/')continue;
			$allowed=strpos($name,'plugins/aiDrive/')===0 || $name==='plugins/webdav/php/webdavServerKod.class.php';
			if(strpos($name,"\0")!==false || substr($name,0,1)==='/' || preg_match('#(^|/)\.\.(/|$)#',$name) || !$allowed){$zip->close();throw new Exception('Update package contains an unsafe path');}
			$dest=$stage.$name;mk_dir(dirname($dest));$in=$zip->getStream($rawName);$out=fopen($dest,'wb');if(!$in||!$out){$zip->close();throw new Exception('Cannot extract update package');}stream_copy_to_stream($in,$out);fclose($in);fclose($out);
		}$zip->close();
	}
	private function copyTree($source,$dest,$throw=false){
		$source=rtrim($source,'/\\').'/';$dest=rtrim($dest,'/\\').'/';if(!is_dir($source)||!mk_dir($dest)){if($throw)throw new Exception('Cannot prepare update directory');return false;}
		$items=scandir($source);foreach($items as $name){if($name==='.'||$name==='..')continue;$from=$source.$name;$to=$dest.$name;if(is_dir($from)){if(!$this->copyTree($from,$to,$throw))return false;}else{mk_dir(dirname($to));if(!@copy($from,$to)){if($throw)throw new Exception('Cannot write update file: '.$name);return false;}}}return true;
	}
}
