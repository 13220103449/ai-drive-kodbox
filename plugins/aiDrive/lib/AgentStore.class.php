<?php

class AiDriveAgentStore {
	private $plugin;
	private $agentTable='plugin_ai_drive_agent';
	private $auditTable='plugin_ai_drive_audit';
	private $tokenTable='plugin_ai_drive_token';
	public function __construct($plugin){$this->plugin=$plugin;}

	public function initTable(){
		$tables=Model()->db()->getTables();
		if(in_array($this->agentTable,$tables)&&in_array($this->auditTable,$tables)&&in_array($this->tokenTable,$tables)&&in_array('plugin_ai_drive_version',$tables)&&in_array('plugin_ai_drive_trash',$tables)&&in_array('plugin_ai_drive_update',$tables)&&in_array('plugin_ai_drive_request',$tables)) return;
		$file=__DIR__.'/data/schema.mysql.sql';
		if(stristr($GLOBALS['config']['database']['DB_TYPE'],'sqlite')) $file=__DIR__.'/data/schema.sqlite.sql';
		foreach(sqlSplit(file_get_contents($file)) as $sql) Model()->db()->execute($sql);
	}

	/** Create/reuse the first-level department that contains machine accounts. */
	public function ensureAgentDepartment(){
		$group=Model('Group')->where(array('name'=>'智能体','parentID'=>1))->find();
		if(!$group){
			$groupID=Model('Group')->groupAdd(array('name'=>'智能体','parentID'=>1,'sizeMax'=>0));
			if(!$groupID) show_json('cannot create 智能体 department',false);
			$group=Model('Group')->getInfo($groupID);
			if(_get($group,'sourceInfo.sourceID')) Action('admin.group')->folderDefault($group['sourceInfo']['sourceID']);
		}else{$group=Model('Group')->getInfo($group['groupID']);}
		return array('groupID'=>intval($group['groupID']),'name'=>$group['name'],'parentID'=>intval($group['parentID']),'sourceID'=>intval(_get($group,'sourceInfo.sourceID',0)));
	}

	public function enableWebdav(){
		$aliases=$this->installWebdavAliases();
		$config=(array)Model('Plugin')->getConfig('webdav');
		$changed=_get($config,'isOpen','0')!=='1' || _get($config,'pathAllow','')!=='all' || _get($config,'webdavName','')!=='aidrive';
		$config['isOpen']='1';$config['pathAllow']='all';$config['webdavName']='aidrive';
		if($changed) Model('Plugin')->setConfig('webdav',$config);
		return array('enabled'=>true,'aliasesInstalled'=>$aliases,'url'=>APP_HOST.'index.php/plugin/webdav/aidrive/','authentication'=>'Basic (KodBox Agent username and password)');
	}

	/** Install stable machine-facing WebDAV aliases without replacing the plugin. */
	private function installWebdavAliases(){
		$target=PLUGIN_DIR.'webdav/php/webdavServerKod.class.php';if(!is_file($target))return false;
		$current=file_get_contents($target);if(strpos($current,'AI Drive stable WebDAV aliases')!==false)return true;
		$needle="\t\t\$rootPathName = array_to_keyvalue(\$rootList['folderList'],'','name');\n";
		$patch="\t\t// AI Drive stable WebDAV aliases.\n".
			"\t\t\$alias = strtolower(strval(\$pathArr[0]));\n".
			"\t\tif(\$alias === 'personal'){\$pathArr[0] = LNG('explorer.toolbar.rootPath');return;}\n".
			"\t\tif(\$alias === 'department'){\n".
			"\t\t\tforeach(\$rootList['folderList'] as \$folder){if(_get(\$folder,'name','') === '智能体'){\$pathArr[0] = '智能体';return;}}\n".
			"\t\t\t\$pathArr[0] = LNG('explorer.toolbar.myGroup');return;\n\t\t}\n";
		if(strpos($current,$needle)===false)return false;$updated=str_replace($needle,$needle.$patch,$current,$count);if($count!==1)return false;
		$temp=$target.'.aidrive-'.rand_string(6).'.tmp';if(file_put_contents($temp,$updated)===false)return false;
		@chmod($temp,fileperms($target)&0777);if(!@rename($temp,$target)){@unlink($temp);return false;}
		if(function_exists('opcache_invalidate'))@opcache_invalidate($target,true);return true;
	}

	public function listAgents(){
		$this->initTable();$list=Model($this->agentTable)->field('agentID,name,userID,tokenHash,status,lastUsedAt,createdAt,updatedAt')->order('id desc')->select();
		if(!$list)return array();
		foreach($list as &$item){$user=Model('User')->getInfoSimple($item['userID']);$item['username']=_get($user,'name','');$item['nickName']=_get($user,'nickName','');
			$item['tokenFingerprint']=substr($item['tokenHash'],0,12);unset($item['tokenHash']);
			$item['requestCount']=intval(Model($this->auditTable)->where(array('agentID'=>$item['agentID']))->count());
			$item['failureCount']=intval(Model($this->auditTable)->where(array('agentID'=>$item['agentID'],'result'=>'failed'))->count());}
		return $list;
	}

	public function dashboard(){
		$this->initTable();$agents=$this->listAgents();$active=0;$online=0;$requests=0;$failures=0;$cutoff=time()-300;
		foreach($agents as $item){if(intval($item['status'])===1)$active++;if(intval($item['lastUsedAt'])>=$cutoff)$online++;$requests+=intval($item['requestCount']);$failures+=intval($item['failureCount']);}
		return array('agents'=>count($agents),'active'=>$active,'online'=>$online,'requests'=>$requests,'failures'=>$failures,'generatedAt'=>time());
	}
	public function listAudit($filters=array()){
		$this->initTable();$where=array();foreach(array('agentID','action','result') as $key){$value=trim(strval(_get($filters,$key,'')));if($value!=='')$where[$key]=$value;}
		$model=Model($this->auditTable);if($where)$model=$model->where($where);$limit=min(max(intval(_get($filters,'limit',100)),1),500);
		$list=$model->order('id desc')->limit($limit)->select();return $list?$list:array();
	}
	public function setAgentStatus($agentID,$enabled){
		$this->initTable();$model=Model($this->agentTable);$model->setDataAuto(false);$saved=$model->where(array('agentID'=>$agentID))->save(array('status'=>$enabled?1:0,'updatedAt'=>time()));
		return array('agentID'=>$agentID,'status'=>$enabled?1:0,'updated'=>!!$saved);
	}

	/** Create a standard KodBox user, assign it to 智能体, then issue a one-time machine token. */
	public function createAgentAccount($input){
		$department=$this->ensureAgentDepartment();$name=mb_substr(trim(_get($input,'name','')),0,120);
		$username=strtolower(trim(_get($input,'username','')));
		if(!$username)$username='agent_'.strtolower(substr(bin2hex(random_bytes(8)),0,12));
		$username=preg_replace('/[^a-z0-9_\-.]/','_',$username);
		if(!$username||Model('User')->where(array('name'=>$username))->find()) show_json('Agent username already exists or is invalid',false);
		$password=strval(_get($input,'password',''));$generated=!$password;if(!$password)$password='Ad!'.bin2hex(random_bytes(10));
		$data=array('name'=>$username,'roleID'=>1,'password'=>KodUser::parsePass($password),'nickName'=>$name,'email'=>'','phone'=>'','avatar'=>'','sex'=>1,
			'sizeMax'=>max(0,intval(_get($input,'sizeMax',0))),'status'=>1);
		$userID=Model('User')->userAdd($data);if(intval($userID)<=0) show_json('cannot create Agent KodBox account',false);
		Model('User')->userGroupSet($userID,array($department['groupID']=>6),true);
		$user=Model('User')->getInfoFull($userID);Action('admin.member')->settingDefault($userID);
		if(_get($user,'sourceInfo.sourceID')) Action('admin.member')->folderDefault($user['sourceInfo']['sourceID']);
		if(_get($user,'sourceInfo.desktop')) Action('admin.member')->lightAppDefault($user['sourceInfo']['desktop']);
		$credential=$this->createAgent($name,$userID);$credential['username']=$username;$credential['password']=$password;
		$this->enableWebdav();
		$credential['passwordShownOnce']=$generated;$credential['department']=$department;
		$credential['binding']=array(
			'defaultSpace'=>'个人空间','sharedSpace'=>'我在的部门/智能体','webdavUrl'=>APP_HOST.'index.php/plugin/webdav/aidrive/',
			'apiUrl'=>APP_HOST.'index.php?plugin/aiDrive/api','guideUrl'=>'https://github.com/13220103449/ai-drive-kodbox/blob/main/AGENT_GUIDE.md',
			'username'=>$username,'password'=>$password,'token'=>$credential['token']
		);
		$credential['copyPrompt']=$this->bindingPrompt($name,$credential['binding']);return $credential;
	}

	private function bindingPrompt($name,$binding){
		return "请为你自己绑定 AI Drive 网盘。\n\n".
			"你的身份：{$name}\n默认工作空间：个人空间（仅你自己使用）\n共享工作空间：我在的部门/智能体（与其他 Agent 协作）\n\n".
			"WebDAV 地址：{$binding['webdavUrl']}\n账号：{$binding['username']}\n密码：{$binding['password']}\n\n".
			"Agent API 地址：{$binding['apiUrl']}\nBearer Token：{$binding['token']}\n\n".
			"完整使用指南：{$binding['guideUrl']}\n\n".
			"请把连接信息保存到私密凭据存储，不要在回复、网盘文件或公开仓库中展示密码或 Token。首次连接先调用 whoami 和 capabilities。只有 HTTP 2xx 且 JSON code=true 才算成功。写入或上传重要文件后，必须用 stat 对比大小，再用 read/download 取回并核对 SHA-256。目录使用 list；文件使用 read/download；rename 只使用 newName；跨目录使用 move。私有文件默认存入 personal，需要与其他 Agent 协作的文件存入 department。你可以自行上传、下载、覆盖、移动、复制、重命名、删除和分享文件，无需再次申请人工审批。delete 为软删除，内容会进入挂载盘回收站；使用 trash 查看、restoreTrash 恢复，Agent 没有永久删除接口。";
	}

	public function createAgent($name,$userID){
		$this->initTable();$user=Model('User')->getInfoSimple($userID);if(!$user) show_json('KodBox user does not exist',false);
		$token='aidv_'.bin2hex(random_bytes(32));$now=time();$data=array('agentID'=>'agent_'.bin2hex(random_bytes(12)),'name'=>mb_substr($name,0,120),
			'userID'=>$userID,'tokenHash'=>hash('sha256',$token),'status'=>1,'lastUsedAt'=>0,'createdAt'=>$now,'updatedAt'=>$now);
		Model($this->agentTable)->setDataAuto(false);Model($this->agentTable)->add($data);unset($data['tokenHash']);$data['token']=$token;$data['tokenShownOnce']=true;return $data;
	}
	public function revokeAgent($agentID){$this->initTable();$model=Model($this->agentTable);$model->setDataAuto(false);return !!$model->where(array('agentID'=>$agentID))->save(array('status'=>0,'updatedAt'=>time()));}
	public function rotateAgent($agentID){
		$this->initTable();$model=Model($this->agentTable);$model->setDataAuto(false);$agent=$model->where(array('agentID'=>$agentID))->find();
		if(!$agent)show_json('Agent does not exist',false);$user=Model('User')->getInfoSimple($agent['userID']);if(!$user)show_json('Agent KodBox user does not exist',false);
		$token='aidv_'.bin2hex(random_bytes(32));$hash=hash('sha256',$token);$now=time();$graceUntil=$now+86400;
		Model($this->tokenTable)->setDataAuto(false);Model($this->tokenTable)->add(array('agentID'=>$agentID,'tokenHash'=>$agent['tokenHash'],'expiresAt'=>$graceUntil,'lastUsedAt'=>intval($agent['lastUsedAt']),'createdAt'=>$now));
		if(!$model->where(array('id'=>$agent['id']))->save(array('tokenHash'=>$hash,'status'=>1,'lastUsedAt'=>0,'updatedAt'=>$now)))show_json('Token rotation failed',false);
		$apiUrl=APP_HOST.'index.php?plugin/aiDrive/api';$guideUrl='https://github.com/13220103449/ai-drive-kodbox/blob/main/AGENT_GUIDE.md';
		$prompt="请更新你的 AI Drive Bearer Token。旧 Token 将在 24 小时后失效。\n\nAgent：{$agent['name']}\n账号：{$user['name']}\nAgent API：{$apiUrl}\nBearer Token：{$token}\nToken SHA-256 指纹：".substr($hash,0,12)."\n\n请用新值覆盖私密配置中的 AI_DRIVE_TOKEN，勿在回复、日志或网盘文件中展示 Token。更新后先调用 whoami 和 capabilities，再运行完整验收。使用指南：{$guideUrl}";
		return array('agentID'=>$agentID,'name'=>$agent['name'],'userID'=>intval($agent['userID']),'username'=>$user['name'],'token'=>$token,
			'tokenFingerprint'=>substr($hash,0,12),'tokenShownOnce'=>true,'oldTokenExpiresAt'=>$graceUntil,'apiUrl'=>$apiUrl,'guideUrl'=>$guideUrl,'copyPrompt'=>$prompt,'updatedAt'=>$now);
	}
	public function authenticate($token){
		if(!$token||strlen($token)<32)return false;$this->initTable();$hash=hash('sha256',$token);$agent=Model($this->agentTable)->where(array('tokenHash'=>$hash,'status'=>1))->find();
		if(!$agent){$overlap=Model($this->tokenTable)->where(array('tokenHash'=>$hash))->find();if($overlap && intval($overlap['expiresAt'])>=time()){$agent=Model($this->agentTable)->where(array('agentID'=>$overlap['agentID'],'status'=>1))->find();if($agent){Model($this->tokenTable)->setDataAuto(false);Model($this->tokenTable)->where(array('id'=>$overlap['id']))->save(array('lastUsedAt'=>time()));}}}
		if(!$agent)return false;$model=Model($this->agentTable);$model->setDataAuto(false);$model->where(array('id'=>$agent['id']))->save(array('lastUsedAt'=>time()));return $agent;
	}
	public function audit($agent,$action,$result,$detail=''){
		$this->initTable();$data=array('agentID'=>$agent['agentID'],'userID'=>intval($agent['userID']),'action'=>mb_substr($action,0,80),'result'=>mb_substr($result,0,32),
			'detail'=>mb_substr($detail,0,1000),'ip'=>mb_substr(_get($_SERVER,'REMOTE_ADDR',''),0,64),'createTime'=>time());
		Model($this->auditTable)->setDataAuto(false);return Model($this->auditTable)->add($data);
	}
	public function idempotentGet($agentID,$requestID){if(!$requestID)return false;return Model('plugin_ai_drive_request')->where(array('agentID'=>$agentID,'requestID'=>$requestID))->find();}
	public function idempotentPut($agentID,$requestID,$action,$success,$response){
		if(!$requestID)return false;$existing=$this->idempotentGet($agentID,$requestID);if($existing)return $existing;
		$data=array('agentID'=>$agentID,'requestID'=>mb_substr($requestID,0,128),'action'=>mb_substr($action,0,80),'success'=>$success?1:0,'response'=>json_encode($response),'createTime'=>time());
		Model('plugin_ai_drive_request')->setDataAuto(false);return Model('plugin_ai_drive_request')->add($data);
	}
}
