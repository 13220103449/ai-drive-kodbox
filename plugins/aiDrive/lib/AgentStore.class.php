<?php

class AiDriveAgentStore {
	private $plugin;
	private $agentTable='plugin_ai_drive_agent';
	private $auditTable='plugin_ai_drive_audit';
	public function __construct($plugin){$this->plugin=$plugin;}

	public function initTable(){
		$tables=Model()->db()->getTables();
		if(in_array($this->agentTable,$tables)&&in_array($this->auditTable,$tables)) return;
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
		$config=(array)Model('Plugin')->getConfig('webdav');
		$changed=_get($config,'isOpen','0')!=='1' || _get($config,'pathAllow','')!=='all' || _get($config,'webdavName','')!=='aidrive';
		$config['isOpen']='1';$config['pathAllow']='all';$config['webdavName']='aidrive';
		if($changed) Model('Plugin')->setConfig('webdav',$config);
		return array('enabled'=>true,'url'=>APP_HOST.'index.php/plugin/webdav/aidrive/','authentication'=>'Basic (KodBox Agent username and password)');
	}

	public function listAgents(){
		$this->initTable();$list=Model($this->agentTable)->field('agentID,name,userID,status,lastUsedAt,createdAt,updatedAt')->order('id desc')->select();
		if(!$list)return array();
		foreach($list as &$item){$user=Model('User')->getInfoSimple($item['userID']);$item['username']=_get($user,'name','');$item['nickName']=_get($user,'nickName','');}
		return $list;
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
			'apiUrl'=>APP_HOST.'index.php?plugin/aiDrive/api','username'=>$username,'password'=>$password,'token'=>$credential['token']
		);
		$credential['copyPrompt']=$this->bindingPrompt($name,$credential['binding']);return $credential;
	}

	private function bindingPrompt($name,$binding){
		return "请为你自己绑定 AI Drive 网盘。\n\n".
			"你的身份：{$name}\n默认工作空间：个人空间（仅你自己使用）\n共享工作空间：我在的部门/智能体（与其他 Agent 协作）\n\n".
			"WebDAV 地址：{$binding['webdavUrl']}\n账号：{$binding['username']}\n密码：{$binding['password']}\n\n".
			"Agent API 地址：{$binding['apiUrl']}\nBearer Token：{$binding['token']}\n\n".
			"请保存这些连接信息并立即测试：先列出个人空间根目录，再列出智能体部门共享空间。以后生成的私有文件默认存入个人空间；需要交付给人类或其他 Agent 的文件存入智能体部门。你可以自行上传、下载、覆盖、移动、复制、重命名、删除和分享文件，无需再次申请人工审批。不要把账号、密码或 Token 写进网盘文件或对外分享。";
	}

	public function createAgent($name,$userID){
		$this->initTable();$user=Model('User')->getInfoSimple($userID);if(!$user) show_json('KodBox user does not exist',false);
		$token='aidv_'.bin2hex(random_bytes(32));$now=time();$data=array('agentID'=>'agent_'.bin2hex(random_bytes(12)),'name'=>mb_substr($name,0,120),
			'userID'=>$userID,'tokenHash'=>hash('sha256',$token),'status'=>1,'lastUsedAt'=>0,'createdAt'=>$now,'updatedAt'=>$now);
		Model($this->agentTable)->setDataAuto(false);Model($this->agentTable)->add($data);unset($data['tokenHash']);$data['token']=$token;$data['tokenShownOnce']=true;return $data;
	}
	public function revokeAgent($agentID){$this->initTable();$model=Model($this->agentTable);$model->setDataAuto(false);return !!$model->where(array('agentID'=>$agentID))->save(array('status'=>0,'updatedAt'=>time()));}
	public function authenticate($token){
		if(!$token||strlen($token)<32)return false;$this->initTable();$agent=Model($this->agentTable)->where(array('tokenHash'=>hash('sha256',$token),'status'=>1))->find();
		if(!$agent)return false;$model=Model($this->agentTable);$model->setDataAuto(false);$model->where(array('id'=>$agent['id']))->save(array('lastUsedAt'=>time()));return $agent;
	}
	public function audit($agent,$action,$result,$detail=''){
		$this->initTable();$data=array('agentID'=>$agent['agentID'],'userID'=>intval($agent['userID']),'action'=>mb_substr($action,0,80),'result'=>mb_substr($result,0,32),
			'detail'=>mb_substr($detail,0,1000),'ip'=>mb_substr(_get($_SERVER,'REMOTE_ADDR',''),0,64),'createTime'=>time());
		Model($this->auditTable)->setDataAuto(false);return Model($this->auditTable)->add($data);
	}
}
