<?php

/** AI Drive: real KodBox accounts and unrestricted personal/shared file APIs for Agents. */
class aiDrivePlugin extends PluginBase {
	private $store;
	private $requestID='';
	public function __construct(){parent::__construct();}
	public function regist(){$this->hookRegist(array('globalRequest'=>'aiDrivePlugin.route','user.commonJs.insert'=>'aiDrivePlugin.echoJs'));}
	public function echoJs(){
		if(_get($GLOBALS,'isRoot')!=1)return;
		$this->echoFile('static/main.js',array(
			'{{agentsApi}}'=>$this->pluginApi.'agents',
			'{{dashboardApi}}'=>$this->pluginApi.'dashboard',
			'{{auditApi}}'=>$this->pluginApi.'auditLog',
			'{{versionsApi}}'=>$this->pluginApi.'versions',
			'{{maintenanceApi}}'=>$this->pluginApi.'maintenance',
			'{{updateCheckApi}}'=>$this->pluginApi.'updateCheck',
			'{{updateInstallApi}}'=>$this->pluginApi.'updateInstall',
			'{{updateHistoryApi}}'=>$this->pluginApi.'updateHistory',
			'{{updateRollbackApi}}'=>$this->pluginApi.'updateRollback'
		));
	}
	public function onChangeStatus($status){if($status){$this->store()->initTable();$this->store()->ensureAgentDepartment();$this->store()->enableWebdav();}}
	public function onSetConfig($config){$this->store()->initTable();$this->store()->ensureAgentDepartment();$this->store()->enableWebdav();return $config;}
	public function route(){if(strtolower(MOD.'.'.ST)==='plugin.aidrive' && strtolower(ACT)==='api') $this->api();}

	public function health(){show_json(array('service'=>'AI Drive Agent API','version'=>'0.6.0','status'=>'ok','kodbox'=>defined('KOD_VERSION')?KOD_VERSION:null,'features'=>array('agent-dashboard','audit','overlapping-tokens','storage-recycle-bin','storage-file-versions','update-rollback')));}
	public function department(){KodUser::checkRoot();show_json($this->store()->ensureAgentDepartment());}
	public function webdav(){KodUser::checkRoot();show_json($this->store()->enableWebdav());}
	public function updateCheck(){KodUser::checkRoot();$this->store()->initTable();try{show_json($this->updater()->check());}catch(Exception $error){show_json($error->getMessage(),false);}}
	public function updateInstall(){KodUser::checkRoot();$this->store()->initTable();try{show_json($this->updater()->install());}catch(Exception $error){show_json($error->getMessage(),false);}}
	public function updateHistory(){KodUser::checkRoot();$this->store()->initTable();show_json($this->updater()->history(intval(_get($this->in,'limit',30))));}
	public function updateRollback(){KodUser::checkRoot();$this->store()->initTable();try{show_json($this->updater()->rollback(intval(_get($this->jsonBody(),'id',0))));}catch(Exception $error){show_json($error->getMessage(),false);}}
	public function dashboard(){KodUser::checkRoot();show_json($this->store()->dashboard());}
	public function auditLog(){KodUser::checkRoot();show_json($this->store()->listAudit($this->in));}
	public function versions(){KodUser::checkRoot();show_json($this->protection()->listVersions(trim(_get($this->in,'agentID','')),intval(_get($this->in,'limit',100))));}
	public function maintenance(){KodUser::checkRoot();$method=strtoupper(_get($_SERVER,'REQUEST_METHOD','GET'));try{if($method==='POST')show_json($this->maintenanceService()->backup());show_json(array('health'=>$this->maintenanceService()->health(),'backups'=>$this->maintenanceService()->listBackups()));}catch(Exception $error){show_json($error->getMessage(),false);}}

	/** GET lists Agents; POST creates; PATCH rotates a token; DELETE revokes it. */
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
		if($method==='PATCH'){
			$id=trim(_get($body,'agentID',''));if(!$id) show_json('agentID is required',false);
			if(array_key_exists('enabled',$body))show_json($this->store()->setAgentStatus($id,!!$body['enabled']));
			show_json($this->store()->rotateAgent($id));
		}
		show_json('method not allowed',false);
	}

	/** Bearer API. Paths are relative to the selected personal or department space. */
	public function api(){
		$config=$this->getConfig();if(strval(_get($config,'isOpen','1'))==='0') show_json('AI Drive Agent API is disabled',false);
		$agent=$this->store()->authenticate($this->bearerToken());
		if(!$agent){header('HTTP/1.1 401 Unauthorized');show_json('invalid or revoked Agent token',false);}
		$this->maintenanceService()->maybeDaily();
		$user=Model('User')->getInfoFull(intval($agent['userID']));
		if(!$user || intval($user['status'])!==1){header('HTTP/1.1 403 Forbidden');show_json('Agent KodBox account is disabled',false);}
		Session::set('kodUser',$user);KodUser::init($user['userID']);
		// KodBox only loads optional storage drivers when their plugin route runs.
		// Agent API requests bypass that route, so load the WebDAV/NFS/Samba drivers
		// explicitly before IO resolves a user's configured storage backend.
		$this->loadOptionalStorageDrivers();
		$webdav=$this->store()->enableWebdav();
		$body=$this->jsonBody();$action=strtolower(_get($body,'action',_get($this->in,'action','capabilities')));
		$this->requestID=trim(strval(_get($body,'requestID',_get($_SERVER,'HTTP_IDEMPOTENCY_KEY',''))));
		if($this->requestID){$cached=$this->store()->idempotentGet($agent['agentID'],$this->requestID);if($cached){$payload=json_decode($cached['response'],true);show_json($payload,intval($cached['success'])===1);}}
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
			'restActions'=>array('capabilities','whoami','list','stat','read','download','write','upload','uploadChunk','mkdir','rename','move','copy','delete','trash','restoreTrash','share','versions','restore'),
			'parameters'=>array(
				'write'=>array('path'=>'target file path; the file is created when absent','content'=>'text or binary string','encoding'=>'optional: base64'),
				'upload'=>array('contentType'=>'multipart/form-data','file'=>'required file field','path'=>'existing destination folder','name'=>'optional target filename'),
				'uploadChunk'=>array('path'=>'destination folder','name'=>'target filename','uploadID'=>'client-generated identifier','index'=>'zero-based ordered chunk index','total'=>'chunk count','content'=>'base64 chunk','requestID'=>'recommended for safe retries'),
				'mkdir'=>array('path'=>'folder path; every missing intermediate folder is created'),
				'rename'=>array('path'=>'existing source path','newName'=>'new filename; name is equivalent; no other aliases are accepted'),
				'move'=>array('path'=>'existing source path','to'=>'destination folder or complete target path; missing destination parents are created; aliases: dest, destination'),
				'copy'=>array('path'=>'existing source path','to'=>'destination folder or complete target path; missing destination parents are created; aliases: dest, destination'),
				'delete'=>array('path'=>'file or folder to move into AI Drive回收站(勿删); content is never permanently deleted'),
				'trash'=>array('limit'=>'optional, 1-500; lists this Agent soft-deleted items'),
				'restoreTrash'=>array('trashID'=>'required recycle-bin record id','overwrite'=>'optional boolean; conflicting current content is also moved to recycle bin'),
				'versions'=>array('path'=>'optional exact file path','limit'=>'optional, 1-500; old content is stored under AI Drive历史版本(勿删)'),
				'restore'=>array('versionID'=>'required version record id'),
				'webdav'=>array('personal'=>'/personal/','department'=>'/department/','method'=>'use PROPFIND for folders; collection GET is not a directory listing')
			)
		));}
		if($action==='trash')return $this->success($agent,$action,$this->protection()->listTrash($agent['agentID'],intval(_get($body,'limit',100))));
		if($action==='restoretrash'){
			$id=intval(_get($body,'trashID',0));if(!$id)return $this->failure($agent,$action,'trashID is required');
			$item=$this->protection()->trashItem($id,$agent['agentID']);if(!$item)return $this->notFound($agent,$action,'recycle-bin item not found');
			if($item['space']!==$space)return $this->failure($agent,$action,'select the original space: '.$item['space']);
			$parts=$this->targetParts($root,$item['originalPath'],true);if(!$parts || !$parts['name'])return $this->failure($agent,$action,'restore target is invalid',$item['originalPath']);
			$target=$this->agentPath($root,$item['originalPath']);$existing=$target?IO::infoFull($target):false;
			if($existing && !_get($body,'overwrite',false))return $this->failure($agent,$action,'target already exists; set overwrite=true to recycle it first',$item['originalPath']);
			if($existing && !$this->recyclePath($agent,$space,$root,$target,$existing))return $this->failure($agent,$action,'cannot recycle conflicting target',$item['originalPath']);
			$restored=$this->moveArchivedPath($item['storagePath'],$parts['folder'],$parts['name']);if(!$restored)return $this->failure($agent,$action,IO::getLastError('restore from recycle bin failed'),$item['originalPath']);
			$this->protection()->forgetTrash($id,$agent['agentID']);return $this->success($agent,$action,array('restored'=>true,'trashID'=>$id,'path'=>$item['originalPath']),$item['originalPath']);
		}

		$relativeInput=_get($body,'path',_get($this->in,'path',''));
		$this->assertPublicPath($relativeInput);
		if($action==='write'){
			$parent=_get($body,'parentPath',_get($this->in,'parentPath',''));
			$name=$this->safeName(_get($body,'name',_get($this->in,'name','')));
			if($name && (!$relativeInput || substr($relativeInput,-1)==='/'))$relativeInput=rtrim($relativeInput?$relativeInput:$parent,'/').'/'.$name;
		}
		if($action==='mkdir'){
			$path=$this->ensureFolderPath($root,$relativeInput);
			if(!$path) return $this->failure($agent,$action,IO::getLastError('mkdir failed'),$relativeInput);
			return $this->success($agent,$action,$this->relativePath($path,$root),$path);
		}
		$path=$this->agentPath($root,$relativeInput);
		if(!$path) return $this->notFound($agent,$action,'parent folder not found',$relativeInput);
		if($action==='list'){
			$info=IO::infoFull($path);if(!$info || $info['type']!=='folder') return $this->notFound($agent,$action,'folder not found',$relativeInput);
			return $this->success($agent,$action,$this->listResult(IO::listPath($path),$root),$path);
		}
		if($action==='stat'){
			$info=IO::infoFull($path);if(!$info) return $this->notFound($agent,$action,'path not found',$relativeInput);
			return $this->success($agent,$action,$this->fileInfo($info,$root),$path);
		}
		if($action==='read'){
			$info=IO::infoFull($path);if(!$info || $info['type']!=='file') return $this->notFound($agent,$action,'file not found',$relativeInput);
			$max=min(max(intval(_get($body,'maxBytes',2*1024*1024)),1),20*1024*1024);
			if(intval($info['size'])>$max) return $this->failure($agent,$action,'file exceeds maxBytes',$path);
			$content=IO::fileSubstr($path,0,intval($info['size']));$base64=!!_get($body,'base64',false);
			return $this->success($agent,$action,array('path'=>$this->relativePath($path,$root),'encoding'=>$base64?'base64':'utf-8','content'=>$base64?base64_encode($content):$content),$path);
		}
		if($action==='download'){
			$info=IO::infoFull($path);if(!$info || $info['type']!=='file') return $this->notFound($agent,$action,'file not found',$relativeInput);
			$this->store()->audit($agent,$action,'success',$this->relativePath($path,$root));IO::fileOut($path,true,$info['name']);exit;
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
			if($before && !$this->snapshotVersion($agent,$space,$root,$path,'overwrite'))return $this->failure($agent,$action,'cannot preserve previous version',$path);
			$result=$before?IO::setContent($path,$content):IO::mkfile($path,$content,REPEAT_REPLACE);
			if(!$result) return $this->failure($agent,$action,IO::getLastError('write failed'),$path);
			$written=IO::infoFull($path);if(!$written || $written['type']!=='file') return $this->failure($agent,$action,'write verification failed',$path);
			return $this->success($agent,$action,$this->fileInfo($written,$root),$path);
		}
		if($action==='upload'){
			$file=_get($_FILES,'file',array());if(!$file || !_get($file,'tmp_name')) return $this->failure($agent,$action,'multipart field "file" is required',$path);
			if(intval(_get($file,'error',UPLOAD_ERR_OK))!==UPLOAD_ERR_OK) return $this->failure($agent,$action,'PHP upload error: '.intval($file['error']),$path);
			$name=$this->safeName(_get($this->in,'name',_get($file,'name','upload.bin')));$folder=IO::infoFull($path);
			if(!$folder || $folder['type']!=='folder') return $this->notFound($agent,$action,'destination folder not found',$relativeInput);
			$target=rtrim($path,'/').'/'.$name;if(IO::infoFull($target) && !$this->snapshotVersion($agent,$space,$root,$target,'overwrite'))return $this->failure($agent,$action,'cannot preserve previous version',$target);$result=IO::upload($target,$file['tmp_name'],true,REPEAT_REPLACE);
			if(!$result) return $this->failure($agent,$action,IO::getLastError('upload failed'),$target);
			$uploaded=IO::infoFull($target);if(!$uploaded || $uploaded['type']!=='file') return $this->failure($agent,$action,'upload verification failed',$target);
			return $this->success($agent,$action,$this->fileInfo($uploaded,$root),$target);
		}
		if($action==='uploadchunk'){
			$folder=IO::infoFull($path);if(!$folder || $folder['type']!=='folder')return $this->notFound($agent,$action,'destination folder not found',$relativeInput);
			$uploadID=preg_replace('/[^a-zA-Z0-9_.-]/','',strval(_get($body,'uploadID','')));$name=$this->safeName(_get($body,'name',''));$index=intval(_get($body,'index',-1));$total=intval(_get($body,'total',0));
			if(!$uploadID||!$name||$index<0||$total<1||$index>=$total)return $this->failure($agent,$action,'uploadID, name, index and total are required');$chunk=base64_decode(strval(_get($body,'content','')),true);if($chunk===false||strlen($chunk)>10*1024*1024)return $this->failure($agent,$action,'invalid or oversized chunk');
			$chunkDir=TEMP_FILES.'aidrive-chunks/';mk_dir($chunkDir);$part=$chunkDir.hash('sha256',$agent['agentID'].'|'.$uploadID).'.part';$meta=$part.'.json';$state=is_file($meta)?json_decode(file_get_contents($meta),true):array('next'=>0,'total'=>$total,'name'=>$name);
			if(intval($state['next'])!==$index||intval($state['total'])!==$total||$state['name']!==$name)return $this->failure($agent,$action,'chunk order or upload metadata mismatch');
			if(file_put_contents($part,$chunk,$index===0?LOCK_EX:FILE_APPEND|LOCK_EX)===false)return $this->failure($agent,$action,'cannot persist upload chunk');$state['next']=$index+1;file_put_contents($meta,json_encode($state),LOCK_EX);
			if($state['next']<$total)return $this->success($agent,$action,array('uploadID'=>$uploadID,'received'=>$state['next'],'total'=>$total,'complete'=>false));
			$target=rtrim($path,'/').'/'.$name;if(IO::infoFull($target) && !$this->snapshotVersion($agent,$space,$root,$target,'overwrite')){@unlink($part);@unlink($meta);return $this->failure($agent,$action,'cannot preserve previous version',$target);}$result=IO::upload($target,$part,true,REPEAT_REPLACE);@unlink($part);@unlink($meta);
			if(!$result)return $this->failure($agent,$action,IO::getLastError('chunk upload failed'),$target);$uploaded=IO::infoFull($target);if(!$uploaded)return $this->failure($agent,$action,'chunk upload verification failed',$target);return $this->success($agent,$action,array('uploadID'=>$uploadID,'complete'=>true,'file'=>$this->fileInfo($uploaded,$root)),$target);
		}
		if($action==='rename'){
			$target=$this->renameTarget($body);if(!$target) return $this->failure($agent,$action,'newName is required (name is the only alias)',$path);
			$newName=$this->safeName($target);if(!$newName) return $this->failure($agent,$action,'newName is invalid',$path);
			$result=IO::rename($path,$newName);
			if(!$result) return $this->failure($agent,$action,IO::getLastError('rename failed'),$path);
			return $this->success($agent,$action,$this->relativePath($result,$root),$path);
		}
		if($action==='move' || $action==='copy'){
			$target=$this->targetValue($body);if(!$target) return $this->failure($agent,$action,'destination is required',$path);
			$this->assertPublicPath($target);
			$result=$action==='move'?$this->moveToTarget($path,$root,$target):$this->copyToTarget($path,$root,$target);
			if(!$result) return $this->failure($agent,$action,IO::getLastError($action.' failed'),$path.' -> '.$target);
			return $this->success($agent,$action,$this->relativePath($result,$root),$path.' -> '.$target);
		}
		if($action==='delete'){
			if($path===$root) return $this->failure($agent,$action,'cannot delete Agent home','/');
			$info=IO::infoFull($path);if(!$info)return $this->notFound($agent,$action,'path not found',$relativeInput);
			$trash=$this->recyclePath($agent,$space,$root,$path,$info);if(!$trash)return $this->failure($agent,$action,IO::getLastError('move to recycle bin failed'),$path);
			return $this->success($agent,$action,array('deleted'=>true,'recycled'=>true,'trashID'=>intval($trash['id']),'originalPath'=>$trash['originalPath']),$path);
		}
		if($action==='share'){
			$info=IO::infoFull($path);if(!$info) return $this->notFound($agent,$action,'path not found',$relativeInput);
			$sourceID=KodIO::sourceID($path);if(!$sourceID) return $this->failure($agent,$action,'share requires a KodBox source path',$relativeInput);
			$data=array('isLink'=>1,'isShareTo'=>0,'title'=>_get($body,'title',$info['name']),'password'=>_get($body,'password',''),
				'timeTo'=>intval(_get($body,'timeTo',0)),'options'=>_get($body,'options',array()),'authTo'=>array(),'sourcePath'=>KodIO::clear(KodIO::make($sourceID)));
			$shareID=Model('Share')->shareAdd($sourceID,$data);
			if(!$shareID) return $this->failure($agent,$action,'share failed',$path);
			$share=Model('Share')->getInfo($shareID);
			return $this->success($agent,$action,array('shareID'=>intval($shareID),'shareHash'=>$share['shareHash'],'url'=>APP_HOST.'#s/'.$share['shareHash']),$path);
		}
		if($action==='versions')return $this->success($agent,$action,$this->protection()->listVersions($agent['agentID'],intval(_get($body,'limit',100)),strval(_get($body,'path',''))));
		if($action==='restore'){
			$id=intval(_get($body,'versionID',0));if(!$id)return $this->failure($agent,$action,'versionID is required');
			$items=$this->protection()->listVersions($agent['agentID'],500);$version=false;foreach($items as $item){if(intval($item['id'])===$id){$version=$item;break;}}
			if(!$version)return $this->notFound($agent,$action,'version not found');$target=$this->agentPath($root,$version['path']);if(!$target)return $this->failure($agent,$action,'version target parent not found',$version['path']);
			if(IO::infoFull($target) && !$this->snapshotVersion($agent,$space,$root,$target,'before-restore'))return $this->failure($agent,$action,'cannot preserve current version before restore',$version['path']);$restored=$this->protection()->restore($id,$target);
			if(!$restored)return $this->failure($agent,$action,'version restore failed',$version['path']);return $this->success($agent,$action,array('restored'=>true,'versionID'=>$id,'path'=>$version['path']),$version['path']);
		}
		return $this->failure($agent,$action,'unsupported action');
	}

	private function success($agent,$action,$data,$detail=''){$this->store()->audit($agent,$action,'success',$this->auditDetail($detail));$this->store()->idempotentPut($agent['agentID'],$this->requestID,$action,true,$data);show_json($data);}
	private function failure($agent,$action,$message,$detail=''){$this->store()->audit($agent,$action,'failed',$this->auditDetail($detail.' '.$message));$this->store()->idempotentPut($agent['agentID'],$this->requestID,$action,false,$message);show_json($message,false);}
	private function notFound($agent,$action,$message,$detail=''){header('HTTP/1.1 404 Not Found');return $this->failure($agent,$action,$message,$detail);}
	private function auditDetail($detail){return preg_replace('/\{source:\d+\}/','/',strval($detail));}
	private function listResult($data,$root){
		$result=array('folders'=>array(),'files'=>array());
		foreach(_get($data,'folderList',array()) as $item){if($this->isProtectionFolder(_get($item,'name','')))continue;$result['folders'][]=$this->fileInfo($item,$root);}
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
			if($index!==$last)return false;
			return rtrim($current,'/').'/'.$part;
		}
		return $current;
	}
	private function childInfo($parent,$name){
		$parentID=KodIO::sourceID($parent);if(!$parentID)return false;
		$item=Model('Source')->where(array('parentID'=>$parentID,'name'=>strval($name),'isDelete'=>0))->field('sourceID,name,isFolder')->find();
		if(!$item)return false;
		$path=KodIO::make($item['sourceID']);$info=IO::infoFull($path);
		if($info){$info['path']=$path;return $info;}
		return array('sourceID'=>intval($item['sourceID']),'name'=>$item['name'],'type'=>intval($item['isFolder'])?'folder':'file','path'=>$path);
	}
	private function ensureFolderPath($root,$relative,$allowProtection=false){
		$relative=rawurldecode(strval($relative));if(strpos($relative,"\0")!==false)show_json('invalid path',false);$relative=str_replace('\\','/',$relative);
		$current=rtrim($root,'/').'/';
		$position=0;foreach(explode('/',trim($relative,'/')) as $part){
			if($part===''||$part==='.')continue;if($part==='..')show_json('path traversal is not allowed',false);
			if($position++===0 && !$allowProtection && $this->isProtectionFolder($part))show_json('AI Drive protection folders are read-only through the Agent API',false);
			$item=$this->childInfo($current,$part);
			if($item){if($item['type']!=='folder')return false;$current=$item['path'];continue;}
			$created=IO::mkdir(rtrim($current,'/').'/'.$part,REPEAT_SKIP);if(!$created)return false;
			$item=$this->childInfo($current,$part);if(!$item || $item['type']!=='folder')return false;$current=$item['path'];
		}
		return $current;
	}
	private function pathStillExists($path){
		$sourceID=KodIO::sourceID($path);
		if($sourceID)return !!Model('Source')->where(array('sourceID'=>$sourceID,'isDelete'=>0))->find();
		return !!IO::infoFull($path);
	}
	private function folderIsEmpty($path){
		$info=IO::infoFull($path);if(!$info || $info['type']!=='folder')return false;
		$list=IO::listPath($path);if(!is_array($list))return false;
		return !count(_get($list,'folderList',array())) && !count(_get($list,'fileList',array()));
	}
	private function deletePath($path){
		$wasEmptyFolder=$this->folderIsEmpty($path);IO::remove($path,false);
		// Some source drivers refuse or falsely report removal of an empty
		// virtual folder. Once emptiness is verified, remove its exact Source
		// record and use the observable postcondition as the authority.
		if($this->pathStillExists($path) && $wasEmptyFolder){
			$sourceID=KodIO::sourceID($path);if($sourceID)Model('Source')->remove($sourceID,false);
		}
		return !$this->pathStillExists($path);
	}
	private function protectionFolderName($type){return $type==='trash'?'AI Drive回收站(勿删)':'AI Drive历史版本(勿删)';}
	private function isProtectionFolder($name){return in_array(strval($name),array($this->protectionFolderName('trash'),$this->protectionFolderName('versions')),true);}
	private function assertPublicPath($relative){
		$relative=rawurldecode(str_replace('\\','/',strval($relative)));$first='';
		foreach(explode('/',trim($relative,'/')) as $part){if($part!==''&&$part!=='.'){$first=$part;break;}}
		if($this->isProtectionFolder($first))show_json('AI Drive protection folders are read-only through the Agent API',false);
	}
	private function protectionFolder($root,$type,$agent){
		$relative=$this->protectionFolderName($type).'/'.date('YmdH').'/'.$agent['agentID'];
		if($type==='trash')$relative.='/'.date('YmdHis').'-'.bin2hex(random_bytes(4));
		return $this->ensureFolderPath($root,$relative,true);
	}
	private function snapshotVersion($agent,$space,$root,$path,$operation){
		$folder=$this->protectionFolder($root,'versions',$agent);if(!$folder)return false;
		return $this->protection()->snapshot($agent,$space,$this->relativePath($path,$root),$path,$operation,$folder);
	}
	private function recyclePath($agent,$space,$root,$path,$info){
		$relative=$this->relativePath($path,$root);$folder=$this->protectionFolder($root,'trash',$agent);if(!$folder)return false;
		$archived=$this->moveArchivedPath($path,$folder,_get($info,'name',''));if(!$archived)return false;
		$record=$this->protection()->recordTrash($agent,$space,$relative,$archived,$info);if($record)return $record;
		$parts=$this->targetParts($root,$relative,true);if($parts)$this->moveArchivedPath($archived,$parts['folder'],$parts['name']);return false;
	}
	private function moveArchivedPath($path,$folder,$name){
		$info=IO::infoFull($path);if(!$info)return false;$current=$path;
		if($name && _get($info,'name','')!==$name){$current=IO::rename($current,$name);if(!$current)return false;}
		$result=IO::move($current,$folder,REPEAT_REPLACE);if($result)return $result;
		$copy=IO::copy($current,$folder,REPEAT_REPLACE);if(!$copy)return false;
		if(!$this->deletePath($current)){IO::remove($copy,false);return false;}return $copy;
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
		foreach(array('newName','name') as $key){
			$value=_get($body,$key,_get($this->in,$key,''));if($value==='')continue;
			return strval($value);
		}
		return '';
	}
	private function targetValue($body){
		foreach(array('to','dest','destination') as $key){$value=_get($body,$key,_get($this->in,$key,''));if($value!=='')return str_replace('\\','/',strval($value));}
		return '';
	}
	private function targetParts($root,$target,$createParents=false){
		$target=rtrim(str_replace('\\','/',strval($target)),'/');$resolved=$this->agentPath($root,$target);$info=$resolved?IO::infoFull($resolved):false;
		if($info && $info['type']==='folder')return array('folder'=>$resolved,'name'=>'');
		$clean=trim($target,'/');$pos=strrpos($clean,'/');$folderRelative=$pos===false?'':substr($clean,0,$pos);$name=$this->safeName($pos===false?$clean:substr($clean,$pos+1));
		$folder=$createParents?$this->ensureFolderPath($root,$folderRelative):$this->agentPath($root,$folderRelative);$folderInfo=$folder?IO::infoFull($folder):false;
		if(!$folderInfo || $folderInfo['type']!=='folder')return false;
		return array('folder'=>$folder,'name'=>$name);
	}
	private function moveToTarget($path,$root,$target){
		$parts=$this->targetParts($root,$target,true);if(!$parts)return false;$source=IO::infoFull($path);if(!$source)return false;
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
		$parts=$this->targetParts($root,$target,true);if(!$parts)return false;$source=IO::infoFull($path);if(!$source)return false;
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
	private function protection(){include_once($this->pluginPath.'lib/DataProtection.class.php');return new AiDriveDataProtection($this->store());}
	private function maintenanceService(){include_once($this->pluginPath.'lib/Maintenance.class.php');return new AiDriveMaintenance($this->store());}
	private function bearerToken(){$header=_get($_SERVER,'HTTP_AUTHORIZATION','');if(!$header&&function_exists('getallheaders')){$headers=getallheaders();$header=_get($headers,'Authorization','');}return preg_match('/^Bearer\s+(.+)$/i',$header,$m)?trim($m[1]):'';}
	private function jsonBody(){$raw=file_get_contents('php://input');$data=$raw?json_decode($raw,true):array();return is_array($data)?$data:array();}
}
