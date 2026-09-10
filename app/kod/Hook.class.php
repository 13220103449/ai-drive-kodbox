<?php
/*
* @link http://kodcloud.com/
* @author warlee | e-mail:kodcloud@qq.com
* @copyright warlee 2014.(Shanghai)Co.,Ltd
* @license http://kodcloud.com/tools/license/license.txt
*/

/**
 * hook::add('function','function')
 * hook::add('class:function','class.function')
 *
 * hook::run('class.function',param)
 * hook::run('function',param)
 * 
 */

class Hook{
	static private $events 		= array();
	static private $runMap 		= array();
	static public $logAllow		= false;
	static public function get($event=false){
		if(!$event){
			return self::$events;
		}else{
			return self::$events[$event];
		}
	}
	static public function apply($action,$args=array()) {
		$result = ActionApply($action,$args);
		if(is_string($action)){
			Hook::trigger($action); // 调用某个事件后触发的动作继续触发;
		}
		return $result;
	}
	
	/**
	 * 绑定事件到方法;$action 为可调用内容;
	 */
	static public function bind($event,$action,$once=false) {
		if(!is_string($event)) return false;
		if(!isset(self::$events[$event])){
			self::$events[$event] = array();
		}
		self::$events[$event][] = array(
			'action' => $action,
			'once' 	 => $once,
			'times'	 => 0
		);
	}
	static public function once($event,$action) {
		self::bind($event,$action,true);
	}
	static public function unbind($event,$action = false) {
		if(!is_string($event)) return false;
		//解绑所有;
		if(!$action){
			self::$events[$event] = array();
			return;
		}
		// 解绑指定事件;
		$eventsMatch = self::$events[$event];
		self::$events[$event] = array();
		if(!is_array($eventsMatch)) return;

		for ($i=0; $i < count($eventsMatch); $i++){
			if($eventsMatch[$i]['action'] == $action) continue;
			self::$events[$event][] = $eventsMatch[$i];
		}
	}
	//数据处理;只支持传入一个参数
	static public function filter($event,$param){
		return self::applyEvent($event,'filter',$param);	
	}	
	static public function trigger($event) {
		$args = func_get_args();array_shift($args);	
		return self::applyEvent($event,'trigger',$args);
	}
	
	static private function applyEvent($event,$type='filter',$param){
		if(defined("GLOBAL_LOG_HOOK") && GLOBAL_LOG_HOOK){self::$logAllow = true;}
		$result = ($type == 'filter') ? $param : false; // $type = filter|trigger;
		if(!is_string($event)) return $result;
		if(!isset(self::$events[$event])) return $result;
		if(count(self::$events[$event]) == 0) return $result;
		if(self::checkRunLoopStart($event)) return $result;

		$actions = &self::$events[$event];
		$actionsCount = count($actions);
		for($i=0; $i < $actionsCount; $i++){
			$action = $actions[$i];
			if($action['once'] && $action['times'] > 1) continue;
			$actionStr = self::getCallerStr($action['action']);
			self::log('[run  ] '.$event.'==>start: '.$actionStr.';'.$action['times']);
			$args = ($type == 'filter') ? array($result) : $param;
			
			try{
				$action['times']++;
				$res = ActionApply($action['action'],$args);
			}catch(Exception $e){
				$error = '['.$actionStr.']: '.$e->getMessage();
				$res = self::trigger('eventRun.error',$error);
				if(!$res){throw new Exception($e->getMessage());}
			}
			if(is_string($action['action'])){Hook::trigger($action['action']);}

			if($type == 'filter'){
				if(gettype($res) == gettype($result)){$result = $res;}
			}else if($type == 'trigger'){
				$result = is_null($res) ? $result:$res;
			}
		}
		self::checkRunLoopStop($event);
		return $result;
	}
		
	// 检查死循环; 事件触发后,内部继续调用事件可能造成死循环; 不支持事件递归的情况;
	static private function checkRunLoopStart($event){
		// self::$logAllow = true;
		self::log('[start] '.$event.';'.json_encode(self::$runMap));
		if(in_array($event,self::$runMap)){
			self::log('======= [END] loop; '.$event);
			return true;
		}
		// 递归深度限制,最多30层;
		if(count(self::$runMap) > 30){
			self::log('======= [END] too many; '.$event);
			return true;
		}
		self::$runMap[] = $event;
	}
	static private function checkRunLoopStop($event){
		self::log('[end  ] '.$event);
		array_pop(self::$runMap);
	}
	static private function log($log=''){
		if(!self::$logAllow){return;}
		write_log($log,'hook-filter');
	}
	
	static public function getCallerStr($action){
		if(is_string($action)){return $action;}
		if(is_array($action) && count($action) >= 2) {
			$callback = $action[0];
			$method   = $action[1] ? $action[1]:'';
			if(is_string($callback)){return $callback.'::'.$method;}
			if(is_object($callback)){return get_class($callback).'->'.$method;}
		}
		return 'callUnknown';
	}
}
