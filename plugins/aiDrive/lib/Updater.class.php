<?php

/** Signed-by-checksum updater for AI Drive GitHub releases. */
class AiDriveUpdater {
	private $plugin;
	private $repo;
	public function __construct($plugin){
		$this->plugin=$plugin;$config=$plugin->getConfig();$this->repo=trim(_get($config,'githubRepo','13220103449/ai-drive-kodbox'));
		if(!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#',$this->repo)) show_json('GitHub repository format is invalid',false);
	}

	public function check(){
		$current=$this->currentVersion();$release=$this->release();$latest=ltrim(strval(_get($release,'tag_name','')),'vV');
		if(!$latest || !preg_match('/^\d+\.\d+\.\d+(?:[-.][0-9A-Za-z.-]+)?$/',$latest)) throw new Exception('GitHub Release version is invalid');
		$assets=$this->releaseAssets($release,$latest);
		return array('repo'=>$this->repo,'currentVersion'=>$current,'latestVersion'=>$latest,'hasUpdate'=>version_compare($latest,$current,'>'),
			'notes'=>strval(_get($release,'body','')),'publishedAt'=>strval(_get($release,'published_at','')),'zipSize'=>intval(_get($assets,'zip.size',0)));
	}

	public function install(){
		if(!class_exists('ZipArchive')) throw new Exception('PHP zip extension is required');
		mk_dir(TEMP_FILES);$lockPath=TEMP_FILES.'aidrive-update.lock';$lock=fopen($lockPath,'c+');
		if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)) throw new Exception('Another AI Drive update is running');
		$work=TEMP_FILES.'aidrive-update-'.date('YmdHis').'-'.rand_string(6).'/';mk_dir($work);
		try{
			$current=$this->currentVersion();$release=$this->release();$latest=ltrim(strval(_get($release,'tag_name','')),'vV');
			if(!version_compare($latest,$current,'>')) return array('updated'=>false,'version'=>$current,'message'=>'already latest');
			$assets=$this->releaseAssets($release,$latest);$zipFile=$work.'update.zip';
			$this->download($assets['zip']['browser_download_url'],$zipFile);$checksum=trim($this->request($assets['checksum']['browser_download_url']));
			if(!preg_match('/\b([a-f0-9]{64})\b/i',$checksum,$match)) throw new Exception('Release checksum file is invalid');
			$actual=hash_file('sha256',$zipFile);if(!hash_equals(strtolower($match[1]),strtolower($actual))) throw new Exception('Update package SHA-256 verification failed');
			$stage=$work.'stage/';mk_dir($stage);$this->extractPackage($zipFile,$stage);
			$package=$stage.'plugins/aiDrive/package.json';if(!is_file($package)) throw new Exception('Update package does not contain AI Drive plugin');
			$meta=json_decode(file_get_contents($package),true);if(strval(_get($meta,'version',''))!==$latest) throw new Exception('Release tag and package version do not match');
			$target=PLUGIN_DIR.'aiDrive/';if(!path_writeable($target)) throw new Exception('AI Drive plugin directory is not writable');
			$backup=DATA_PATH.'update-backup/aiDrive-'.$current.'-'.date('YmdHis').'/';mk_dir($backup);
			if(!$this->copyTree($target,$backup)) throw new Exception('Cannot create update backup');
			try{$this->copyTree($stage.'plugins/aiDrive/',$target,true);}catch(Exception $error){$this->copyTree($backup,$target,true);throw $error;}
			$webdavPatch=$stage.'plugins/webdav/php/webdavServerKod.class.php';
			if(is_file($webdavPatch)){
				$webdavTarget=PLUGIN_DIR.'webdav/php/webdavServerKod.class.php';
				if(!is_file($webdavTarget) || !is_writable($webdavTarget) || !@copy($webdavPatch,$webdavTarget)) throw new Exception('Cannot install approved WebDAV compatibility patch');
			}
			$installed=$this->currentVersion();if($installed!==$latest){$this->copyTree($backup,$target,true);throw new Exception('Installed version verification failed; backup restored');}
			return array('updated'=>true,'version'=>$installed,'previousVersion'=>$current,'backup'=>str_replace(BASIC_PATH,'',$backup));
		}finally{if(is_dir($work)) del_dir(rtrim($work,'/'));flock($lock,LOCK_UN);fclose($lock);}
	}

	private function currentVersion(){
		$meta=json_decode(file_get_contents($this->plugin->pluginPath.'package.json'),true);return strval(_get($meta,'version','0.0.0'));
	}
	private function release(){
		$data=json_decode($this->request('https://api.github.com/repos/'.$this->repo.'/releases/latest'),true);
		if(!is_array($data) || _get($data,'draft',false) || _get($data,'prerelease',false)) throw new Exception('No stable GitHub Release is available');return $data;
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
