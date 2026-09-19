<?php

/** AI Drive: real KodBox accounts and unrestricted personal/shared file APIs for Agents. */
class aiDrivePlugin extends PluginBase {
	private $store;
	public function __construct(){parent::__construct();}
	public function regist(){$this->hookRegist(array('globalRequest'=>'aiDrivePlugin.route','user.commonJs.insert'=>'aiDrivePlugin.echoJs'));}
	public function echoJs(){
		if(_get($GLOBALS,'isRoot')!=1)return;
		$this->echoFile('static/main.js',array(
			'{{agentsApi}}'=>$this->pluginApi.'agents',
			'{{updateCheckApi}}'=>$this->pluginApi.'updateCheck',
			'{{updateInstallApi}}'=>$this->pluginApi.'updateInstall'
		));
	}
	public function onChangeStatus($status){if($status){$this->store()->initTable();$this->store()->ensureAgentDepartment();$this->store()->enableWebdav();}}
	public function onSetConfig($config){$this->store()->initTable();$this->store()->ensureAgentDepartment();$this->store()->enableWebdav();return $config;}
	public function route(){if(strtolower(MOD.'.'.ST)==='plugin.aidrive' && strtolower(ACT)==='api') $this->api();}

	public function health(){show_json(array('service'=>'AI Drive Agent API','version'=>'0.4.8','status'=>'ok','kodbox'=>defined('KOD_VERSION')?KOD_VERSION:null));}
	public function department(){KodUser::checkRoot();show_json($this->store()->ensureAgentDepartment());}
	public function webdav(){KodUser::checkRoot();show_json($this->store()->enableWebdav());}
	public function updateCheck(){KodUser::checkRoot();try{show_json($this->updater()->check());}catch(Exception $error){show_json($error->getMessage(),false);}}
	public function updateInstall(){KodUser::checkRoot();try{show_json($this->updater()->install());}catch(Exception $error){show_json($error->getMessage(),false);}}

	/** GET lists Agents; POST creates a real account or attaches an existing user; DELETE revokes the token. */
	public function agents(){
		KodUser::checkRoot();$method=strtoupper(_get($_SERVER,'REQUEST_METHOD','GET'));$body=$this->jsonBody();
		if($method==='GET') show_json($this->store()->listAgents());
		if($method==='POST'){
			$name=trim(_get($body,'name',''));if(!$name) show_json('name is required',false);
			$userID=intval(_get($body,'userID',0));
			if($userID) show_json($this->store()->createAgent($name,$userID));
			show_json($this->store()->createAgentAccount(array(
				'name'=>$name,'username'=>trim(_get($body,'username','')),'password'=>_get($body,'password',''),
				'sizeMax'=>intval(_get($body,'sizeMax',0))
			)));
		}
		if($method==='DELETE'){
			$id=trim(_get($body,'agentID',''));if(!$id) show_json('agentID is required',false);
			show_json(array('revoked'=>$this->store()->revokeAgent($id)));
		}
		show_json('method not allowed',false);
	}

	/** Bearer API. Paths are relative to the selected personal or department space. */
	public function api(){
		$config=$this->getConfig();if(strval(_get($config,'isOpen','1'))==='0') show_json('AI Drive Agent API is disabled',false);
		$agent=$this->store()->authenticate($this->bearerToken());
		if(!$agent){header('HTTP/1.1 401 Unauthorized');show_json('invalid or revoked Agent token',false);}
		$user=Model('User')->getInfoFull(intval($agent['userID']));
		if(!$user || intval($user['status'])!==1){header('HTTP/1.1 403 Forbidden');show_json('Agent KodBox account is disabled',false);}
		Session::set('kodUser',$user);KodUser::init($user['userID']);
		// KodBox only loads optional storage drivers when their plugin route runs.
		// Agent API requests bypass that route, so load the WebDAV/NFS/Samba drivers
		// explicitly before IO resolves a user's configured storage backend.
		$this->loadOptionalStorageDrivers();
		$webdav=$this->store()->enableWebdav();
		$body=$this->jsonBody();$action=strtolower(_get($body,'action',_get($this->in,'action','capabilities')));
		$space=strtolower(_get($body,'space',_get($this->in,'space','personal')));$rootSourceID=$user['sourceInfo']['sourceID'];
		if($space==='department' || $space==='智能体'){
			$department=$this->store()->ensureAgentDepartment();
			$member=Model('user_group')->where(array('userID'=>$user['userID'],'groupID'=>$department['groupID']))->find();
			if(!$member) show_json('Agent is not a member of 智能体 department',false);
			$rootSourceID=$department['sourceID'];$space='department';
		}else{$space='personal';}
		$root=rtrim(KodIO::make($rootSourceID),'/').'/';

		if($action==='whoami') return $this->success($agent,$action,array(
			'agentID'=>$agent['agentID'],'name'=>$agent['name'],'userID'=>intval($agent['userID']),
				'username'=>$user['name'],'nickName'=>$user['nickName'],'home'=>'/','space'=>$space,
			'quota'=>array('used'=>intval($user['sizeUse']),'max'=>intval($user['sizeMax'])),'permissions'=>array('*')
		));
		if($action==='capabilities'){return $this->success($agent,$action,array(
				'protocol'=>'ai-drive-agent-v1','authentication'=>'Bearer','agentAccounts'=>true,'fileBackend'=>'KodBox','webdav'=>$webdav,'spaces'=>array('personal','department'),
			'restActions'=>array('capabilities','whoami','list','stat','read','download','write','upload','mkdir','rename','move','copy','delete','share'),
			'parameters'=>array(
				'write'=>array('path'=>'target file path; the file is created when absent','content'=>'text or binary string','encoding'=>'optional: base64'),
				'upload'=>array('contentType'=>'multipart/form-data','file'=>'required file field','path'=>'existing destination folder','name'=>'optional target filename'),
				'rename'=>array('path'=>'existing source path','name'=>'new filename; newName is equivalent; to/dest/destination also accept a complete target path'),
				'move'=>array('path'=>'existing source path','to'=>'existing destination folder or complete target path; aliases: dest, destination'),
				'copy'=>array('path'=>'existing source path','to'=>'existing destination folder or complete target path; aliases: dest, destination'),
				'webdav'=>array('personal'=>'/personal/','department'=>'/department/','method'=>'use PROPFIND for folders; collection GET is not a directory listing')
			)
		));}

		$relativeInput=_get($body,'path',_get($this->in,'path',''));
		if($action==='write'){
			$parent=_get($body,'parentPath',_get($this->in,'parentPath',''));
			$name=$this->safeName(_get($body,'name',_get($this->in,'name','')));
			if($name && (!$relativeInput || substr($relativeInput,-1)==='/'))$relativeInput=rtrim($relativeInput?$relativeInput:$parent,'/').'/'.$name;
		}
		$path=$this->agentPath($root,$relativeInput);
		if($action==='list'){
			$info=IO::info($path);if(!$info || $info['type']!=='folder') return $this->failure($agent,$action,'folder not found',$path);
			return $this->success($agent,$action,$this->listResult(IO::listPath($path),$root),$path);
		}
		if($action==='stat'){
			$info=IO::info($path);if(!$info) return $this->failure($agent,$action,'path not found',$path);
			return $this->success($agent,$action,$this->fileInfo($info,$root),$path);
		}
		if($action==='read'){
			$info=IO::info($path);if(!$info || $info['type']!=='file') return $this->failure($agent,$action,'file not found',$path);
			$max=min(max(intval(_get($body,'maxBytes',2*1024*1024)),1),20*1024*1024);
			if(intval($info['size'])>$max) return $this->failure($agent,$action,'file exceeds maxBytes',$path);
			$content=IO::fileSubstr($path,0,intval($info['size']));$base64=!!_get($body,'base64',false);
			return $this->success($agent,$action,array('path'=>$this->relativePath($path,$root),'encoding'=>$base64?'base64':'utf-8','content'=>$base64?base64_encode($content):$content),$path);
		}
		if($action==='download'){
			$info=IO::info($path);if(!$info || $info['type']!=='file') return $this->failure($agent,$action,'file not found',$path);
			$this->store()->audit($agent,$action,'success',$this->relativePath($path,$root));IO::fileOut($path,true,$info['name']);exit;
		}
		if($action==='mkdir'){
			$result=IO::mkdir($path,REPEAT_SKIP);if(!$result) return $this->failure($agent,$action,IO::getLastError('mkdir failed'),$path);
			return $this->success($agent,$action,$this->relativePath($result,$root),$path);
		}
		if($action==='write'){
			$content=$this->writeContent($body);
			$encoding=strtolower(strval(_get($body,'encoding',_get($this->in,'encoding',''))));
			if($encoding==='base64' || _get($body,'base64',false)) $content=base64_decode($content,true);
			if($content===false) return $this->failure($agent,$action,'invalid base64 content',$path);
			// IO::info() intentionally falls back to the nearest existing parent
			// for a virtual child path. Use infoFull() here so a missing file is
			// not mistaken for its parent folder.
			$before=IO::infoFull($path);if($before && $before['type']!=='file') return $this->failure($agent,$action,'path is not a file',$path);
			$result=$before?IO::setContent($path,$content):IO::mkfile($path,$content,REPEAT_REPLACE);
			if(!$result) return $this->failure($agent,$action,IO::getLastError('write failed'),$path);
			return $this->success($agent,$action,$this->fileInfo(IO::info($path),$root),$path);
		}
		if($action==='upload'){
			$file=_get($_FILES,'file',array());if(!$file || !_get($file,'tmp_name')) return $this->failure($agent,$action,'multipart field "file" is required',$path);
			if(intval(_get($file,'error',UPLOAD_ERR_OK))!==UPLOAD_ERR_OK) return $this->failure($agent,$action,'PHP upload error: '.intval($file['error']),$path);
			$name=$this->safeName(_get($this->in,'name',_get($file,'name','upload.bin')));$folder=IO::info($path);
			if(!$folder || $folder['type']!=='folder') return $this->failure($agent,$action,'destination folder not found',$path);
			$target=rtrim($path,'/').'/'.$name;$result=IO::upload($target,$file['tmp_name'],true,REPEAT_REPLACE);
			if(!$result) return $this->failure($agent,$action,IO::getLastError('upload failed'),$target);
			return $this->success($agent,$action,$this->fileInfo(IO::info($result),$root),$result);
		}
		if($action==='rename'){
			$target=$this->renameTarget($body);if(!$target['value']) return $this->failure($agent,$action,'name is required (accepted aliases: newName, to, dest, destination)',$path);
			$result=$target['isPath']?$this->moveToTarget($path,$root,$target['value']):IO::rename($path,$this->safeName($target['value']));
			if(!$result) return $this->failure($agent,$action,IO::getLastError('rename failed'),$path);
			return $this->success($agent,$action,$this->relativePath($result,$root),$path);
		}
		if($action==='move' || $action==='copy'){
			$target=$this->targetValue($body);if(!$target) return $this->failure($agent,$action,'destination is required',$path);
			$result=$action==='move'?$this->moveToTarget($path,$root,$target):$this->copyToTarget($path,$root,$target);
			if(!$result) return $this->failure($agent,$action,IO::getLastError($action.' failed'),$path.' -> '.$target);
			return $this->success($agent,$action,$this->relativePath($result,$root),$path.' -> '.$target);
		}
		if($action==='delete'){
			if($path===$root) return $this->failure($agent,$action,'cannot delete Agent home','/');
			$result=IO::remove($path,false);
			// Some source drivers return false after successfully removing an empty
			// folder. The observable postcondition is authoritative.
			if(!$result && $this->pathStillExists($path)) return $this->failure($agent,$action,IO::getLastError('delete failed'),$path);
			return $this->success($agent,$action,array('deleted'=>true),$path);
		}
		if($action==='share'){
			$info=IO::info($path);if(!$info) return $this->failure($agent,$action,'path not found',$path);
			$data=array('isLink'=>1,'isShareTo'=>0,'title'=>_get($body,'title',$info['name']),'password'=>_get($body,'password',''),
				'timeTo'=>intval(_get($body,'timeTo',0)),'options'=>_get($body,'options',array()),'authTo'=>array(),'sourcePath'=>KodIO::clear($path));
			$sourceID=KodIO::sourceID($path);$shareID=Model('Share')->shareAdd($sourceID?$sourceID:'0',$data);
			if(!$shareID) return $this->failure($agent,$action,'share failed',$path);
			$share=Model('Share')->getInfo($shareID);
			return $this->success($agent,$action,array('shareID'=>intval($shareID),'shareHash'=>$share['shareHash'],'url'=>APP_HOST.'#s/'.$share['shareHash']),$path);
		}
		return $this->failure($agent,$action,'unsupported action');
	}

	private function success($agent,$action,$data,$detail=''){$this->store()->audit($agent,$action,'success',$this->auditDetail($detail));show_json($data);}
	private function failure($agent,$action,$message,$detail=''){$this->store()->audit($agent,$action,'failed',$this->auditDetail($detail.' '.$message));show_json($message,false);}
	private function auditDetail($detail){return preg_replace('/\{source:\d+\}/','/',strval($detail));}
	private function listResult($data,$root){
		$result=array('folders'=>array(),'files'=>array());
		foreach(_get($data,'folderList',array()) as $item) $result['folders'][]=$this->fileInfo($item,$root);
		foreach(_get($data,'fileList',array()) as $item) $result['files'][]=$this->fileInfo($item,$root);
		return $result;
	}
	private function fileInfo($info,$root){return array(
		'path'=>$this->relativePath(_get($info,'path',''),$root),'name'=>_get($info,'name',''),'type'=>_get($info,'type',''),'ext'=>_get($info,'ext',''),
		'size'=>intval(_get($info,'size',0)),'createTime'=>intval(_get($info,'createTime',0)),'modifyTime'=>intval(_get($info,'modifyTime',0))
	);}
	private function agentPath($root,$relative){
		$relative=rawurldecode(strval($relative));if(strpos($relative,"\0")!==false) show_json('invalid path',false);$relative=str_replace('\\','/',$relative);$parts=array();
		foreach(explode('/',trim($relative,'/')) as $part){if($part===''||$part==='.') continue;if($part==='..') show_json('path traversal is not allowed',false);$parts[]=$part;}
		if(!$parts)return rtrim($root,'/').'/';
		$current=rtrim($root,'/').'/';$last=count($parts)-1;
		foreach($parts as $index=>$part){
			$item=$this->childInfo($current,$part);
			if($item){$current=$item['path'];continue;}
			if($index!==$last) show_json('parent folder not found: '.$part,false);
			return rtrim($current,'/').'/'.$part;
		}
		return $current;
	}
	private function childInfo($parent,$name){
		$list=IO::listPath($parent);if(!is_array($list))return false;
		foreach(array_merge(_get($list,'folderList',array()),_get($list,'fileList',array())) as $item){
			if(strval(_get($item,'name',''))===strval($name))return $item;
		}
		return false;
	}
	private function pathStillExists($path){
		$sourceID=KodIO::sourceID($path);
		if($sourceID)return !!Model('Source')->where(array('sourceID'=>$sourceID,'isDelete'=>0))->find();
		return !!IO::infoFull($path);
	}
	private function relativePath($path,$root){
		$rootID=KodIO::sourceID($root);$sourceID=KodIO::sourceID($path);if(!$sourceID)return '/';
		$names=array();$guard=0;
		while($sourceID && $sourceID!=$rootID && $guard++<256){
			$item=Model('Source')->where(array('sourceID'=>$sourceID))->field('name,parentID')->find();if(!$item)break;
			array_unshift($names,$item['name']);$sourceID=intval($item['parentID']);
		}
		return '/'.implode('/',$names);
	}
	private function safeName($name){$name=trim(str_replace(array('\\','/',':','*','?','"','<','>','|',"\r","\n"),'_',strval($name)));return in_array($name,array('','.','..'))?'':$name;}
	private function renameTarget($body){
		foreach(array('name','newName','to','dest','destination') as $key){
			$value=_get($body,$key,_get($this->in,$key,''));if($value==='')continue;
			$value=str_replace('\\','/',strval($value));
			return array('value'=>$value,'isPath'=>in_array($key,array('to','dest','destination')) && strpos(trim($value,'/'),'/')!==false);
		}
		return array('value'=>'','isPath'=>false);
	}
	private function targetValue($body){
		foreach(array('to','dest','destination') as $key){$value=_get($body,$key,_get($this->in,$key,''));if($value!=='')return str_replace('\\','/',strval($value));}
		return '';
	}
	private function targetParts($root,$target){
		$target=rtrim(str_replace('\\','/',strval($target)),'/');$resolved=$this->agentPath($root,$target);$info=IO::infoFull($resolved);
		if($info && $info['type']==='folder')return array('folder'=>$resolved,'name'=>'');
		$clean=trim($target,'/');$pos=strrpos($clean,'/');$folderRelative=$pos===false?'':substr($clean,0,$pos);$name=$this->safeName($pos===false?$clean:substr($clean,$pos+1));
		$folder=$this->agentPath($root,$folderRelative);$folderInfo=IO::infoFull($folder);
		if(!$folderInfo || $folderInfo['type']!=='folder')return false;
		return array('folder'=>$folder,'name'=>$name);
	}
	private function moveToTarget($path,$root,$target){
		$parts=$this->targetParts($root,$target);if(!$parts)return false;$source=IO::infoFull($path);if(!$source)return false;
		$current=$path;
		if($parts['name'] && $parts['name']!==$source['name']){$current=IO::rename($current,$parts['name']);if(!$current)return false;}
		$currentInfo=IO::infoFull($current);$folderID=KodIO::sourceID($parts['folder']);
		if(($folderID && intval(_get($currentInfo,'parentID',0))===intval($folderID)) || KodIO::clear(IO::pathFather($current))===KodIO::clear($parts['folder']))return $current;
		$result=IO::move($current,$parts['folder'],REPEAT_REPLACE);if($result)return $result;
		// Source-backed folders occasionally reject move while copy works. Use a
		// verified copy/remove fallback and roll the copy back if removal fails.
		$copy=IO::copy($current,$parts['folder'],REPEAT_REPLACE);if(!$copy)return false;
		$removed=IO::remove($current,false);if(!$removed && $this->pathStillExists($current)){IO::remove($copy,false);return false;}
		return $copy;
	}
	private function copyToTarget($path,$root,$target){
		$parts=$this->targetParts($root,$target);if(!$parts)return false;$source=IO::infoFull($path);if(!$source)return false;
		$result=IO::copy($path,$parts['folder'],REPEAT_REPLACE);if(!$result)return false;
		if($parts['name'] && $parts['name']!==$source['name']){$renamed=IO::rename($result,$parts['name']);if(!$renamed){IO::remove($result,false);return false;}return $renamed;}
		return $result;
	}
	private function writeContent($body){
		foreach(array('content','text','fileContent','data','body') as $key){if(array_key_exists($key,$body))return strval($body[$key]);}
		if(isset($body['base64']) && is_string($body['base64']))return $body['base64'];
		foreach(array('content','text','fileContent','data','body') as $key){if(isset($this->in[$key]))return strval($this->in[$key]);}
		return '';
	}
	private function loadOptionalStorageDrivers(){
		$base=PLUGIN_DIR.'webdav/php/';
		foreach(array('webdavClient.class.php','pathDriverWebdav.class.php','pathDriverNFS.class.php','pathDriverSamba.class.php') as $file){
			if(is_file($base.$file))include_once($base.$file);
		}
	}
	private function store(){if($this->store)return $this->store;include_once($this->pluginPath.'lib/AgentStore.class.php');return $this->store=new AiDriveAgentStore($this);}
	private function updater(){include_once($this->pluginPath.'lib/Updater.class.php');return new AiDriveUpdater($this);}
	private function bearerToken(){$header=_get($_SERVER,'HTTP_AUTHORIZATION','');if(!$header&&function_exists('getallheaders')){$headers=getallheaders();$header=_get($headers,'Authorization','');}return preg_match('/^Bearer\s+(.+)$/i',$header,$m)?trim($m[1]):'';}
	private function jsonBody(){$raw=file_get_contents('php://input');$data=$raw?json_decode($raw,true):array();return is_array($data)?$data:array();}
}
