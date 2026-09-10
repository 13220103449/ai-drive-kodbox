<?php 

/**
 * 通知中心
 */
class msgWarningPlugin extends PluginBase{
	function __construct(){
		parent::__construct();
	}
	public function regist(){
		$this->hookRegist(array(
            'globalRequest'         => 'msgWarningPlugin.autoRun',
			'user.commonJs.insert'  => 'msgWarningPlugin.echoJs',
		));
	}
	public function autoRun(){
		// 存储列表的存储状态相关
		Hook::bind('admin.storage.edit.after', $this->pluginName.'Plugin.sys.storage.editAfter');
		Hook::bind('admin.storage.remove.after', $this->pluginName.'Plugin.sys.storage.editAfter');
		Hook::bind('admin.storage.get.parse', $this->pluginName.'Plugin.sys.storage.listAfter');

		// 通知绑定事件
		// 文件下载限制
        Hook::bind('explorer.fileDownload', $this->pluginName.'Plugin.act.index.fileDownload');

		// Hook::bind('show_json',array($this,'showErrJson'));
		// Hook::bind('show_tips',array($this,'showErrTips'));
	}
	public function echoJs(){
		// 初始化计划任务——随系统安装时无法执行到切换状态、保存配置等
		$this->initUpgrade();
		$config = $this->getConfig();
		if (!_get($config, 'initTask', 0)) {
			$this->apiAct()->ensureTask(1);
			$this->setConfig(array('initTask' => 1));
		}
		$this->echoFile('static/main.js');
	}

	// 升级补数据：新增默认事件、通知日志表等（幂等）
	private function initUpgrade(){
		$config = $this->getConfig();
		$version = _get($config, 'initVersion', '');
		$versionNow = $this->packageVersion();
		if (!$versionNow) {$versionNow = '1.29';}
		if ($version == $versionNow) return;
		$this->loadLib('evnt')->initData();
		$this->loadLib('logs')->initTable();
		$this->apiAct()->ensureTask(1);
		$this->setConfig(array('initVersion' => $versionNow));
	}

    // 切换状态——更新计划任务
	public function onChangeStatus($status){
		if ($status) {
			$this->initUpgrade();
			$this->setConfig(array('pluginAuth' => json_encode(array('all'=>1))));
		}
		$this->apiAct()->updateTask($status);
	}
	// 保存配置——更新计划任务
	public function onSetConfig($config){
		$status = 1;
		$this->loadLib('evnt')->initData();
		$this->loadLib('logs')->initTable();
		$config['pluginAuth'] = json_encode(array('all'=>1));	// 插件权限
		$versionNow = $this->packageVersion();
		$config['initVersion'] = $versionNow ? $versionNow : '1.29';
		$this->apiAct()->ensureTask($status);
        return $config;
	}
	// 卸载插件——删除计划任务
	public function onUninstall(){
		KodUser::checkRoot();
		$this->apiAct()->delTask();
		Cache::remove($this->pluginName.'.webNtcList.'.date('Ymd'));
		Cache::remove($this->pluginName.'.msgQueue');
		Cache::remove($this->pluginName.'.dataFileDownErr.'.date('Ymd'));
		Cache::remove('io.error.list.get');
	}

	public function apiAct($st='sys',$act='task'){
		return Action($this->pluginName . "Plugin.{$st}.{$act}");
	}
	public function loadLib($tab){
		static $apiList = array();
		$class = $tab . 'Api';
		if (!isset($apiList[$class])) {
			include_once($this->pluginPath.'lib/'.$class.'.class.php');
			$apiList[$class] = new $class($this);
		}
		return $apiList[$class];
	}

	/**
	 * 通知：计划任务
	 * @return void
	 */
	public function autoTask(){
		if (!KodUser::isRoot()) return;
		$this->initUpgrade();
		$this->notice(true);
	}

	/**
	 * 通知：分为计划任务和前端调用
	 * @return void
	 */
	public function notice($runTask=false) {
		return $this->apiAct()->notice($runTask);
	}

	/**
	 * 后台管理列表
	 * @return void
	 */
    public function table(){
		KodUser::checkRoot();
		$tab = Input::get('tab', 'in', null, array('evnt','type','logs'));
		$api = $this->loadLib($tab);
		if (!$api) {show_json(LNG('common.illegalRequest'), false);}
		$api->get($this->in);
    }

	/**
	 * 后台管理操作
	 * @return void
	 */
    public function action(){
		KodUser::checkRoot();
		$tab = Input::get('tab', 'in', null, array('evnt','type','logs'));
		$api = $this->loadLib($tab);
		if (!$api) {show_json(LNG('common.illegalRequest'), false);}
		$api->action($this->in);
    }

	/**
	 * 获取计划任务状态
	 * @return void
	 */
	public function taskInfo(){
		KodUser::checkRoot();
		$info = Model('SystemTask')->findByKey('event', $this->pluginName.'Plugin.autoTask');
		show_json($info);
	}
}