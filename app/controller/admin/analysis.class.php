<?php

class adminAnalysis extends Controller{
    public $model;
	function __construct() {
        parent::__construct();
        $this->model = Model('Analysis');
    }

    public function option(){
        $list   = array('user', 'file', 'access', 'server');
        $type   = Input::get('type','in',null,$list);
        $data = $this->applyCache($type);
        if (!$data) {
            $data = $this->model->option($type);
            $this->applyCache($type, $data);
        }
        show_json($data);
    }

    public function chart(){
        $param = Input::getArray(array(
            'userID'    => array("check"=>"int","default"=>null),
            'groupID'   => array("check"=>"int","default"=>null),
        ));
        $data = $this->applyCache($param);
        if (!$data) {
            $data = $this->model->fileChart($param);
            $this->applyCache($param, $data);
        }
        show_json($data);
    }

    // 列表：用户空间、部门空间
    public function table(){
		$type = Input::get('type','in',null,array('user', 'group'));
        $data = $this->applyCache($type);
        if (!$data) {
            $data = $this->model->listTable($type);
            $this->applyCache($type, $data);
        }
        show_json($data);
    }

    /**
     * 趋势：userTrend、storeTrend
     * userTrend: 每日增长（regist,写计划任务）、每日登录（log）
     * storeTrend: 使用空间、时间使用——计划任务
     * @return void
     */
    public function trend(){
        $param = Input::getArray(array(
            'type' => array('check' => 'require', 'default' => 'user'), // user/store
            'time' => array('check' => 'require', 'default' => 'day'),  // day/week/month/year
        ));
        $data = $this->applyCache($param);
        if (!$data) {
            $data = $this->model->trend($param['type'], $param['time']);
            $this->applyCache($param, $data, 3600*2);
        }
        show_json($data);
    }

    // 概览数据存缓存
    private function applyCache($input, $data=false, $timeout=600){
        $cckey = md5('analysis.data.'.ACT.'.'.json_encode($input));
        if ($data === false) return Cache::get($cckey);
        Cache::set($cckey, $data, $timeout);
    }
}