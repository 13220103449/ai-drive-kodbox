<?php

define('APP_HOST', 'http://example.test/');
function _get($data, $key, $default = null) {return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;}
function show_json($data, $code = true) {throw new RuntimeException(json_encode(array('code'=>$code,'data'=>$data)));}

class FakeAgentState {
	public static $row = array('id'=>7,'agentID'=>'agent_test','name'=>'OpenClaw Test','userID'=>2,'tokenHash'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','status'=>0,'lastUsedAt'=>123,'createdAt'=>100,'updatedAt'=>101);
}
class FakeDb {public function getTables(){return array('plugin_ai_drive_agent','plugin_ai_drive_audit','plugin_ai_drive_token','plugin_ai_drive_version','plugin_ai_drive_update','plugin_ai_drive_request');}}
class FakeRootModel {public function db(){return new FakeDb();}}
class FakeUserModel {public function getInfoSimple($id){return intval($id)===2?array('name'=>'agent_example','nickName'=>'OpenClaw Test'):false;}}
class FakeAgentModel {
	private $where=array();
	public function setDataAuto($value){return $this;}
	public function field($value){return $this;}
	public function order($value){return $this;}
	public function where($value){$this->where=$value;return $this;}
	public function select(){return array(FakeAgentState::$row);}
	public function count(){return 0;}
	public function find(){foreach($this->where as $key=>$value){if(_get(FakeAgentState::$row,$key)!=$value)return false;}return FakeAgentState::$row;}
	public function save($data){foreach($this->where as $key=>$value){if(_get(FakeAgentState::$row,$key)!=$value)return false;}FakeAgentState::$row=array_merge(FakeAgentState::$row,$data);return 1;}
}
class FakeTokenModel {public static $rows=array();public function setDataAuto($value){return $this;}public function add($data){self::$rows[]=$data;return count(self::$rows);}public function where($value){return $this;}public function find(){return false;}public function save($data){return 1;}}
class FakeAuditModel {public function where($value){return $this;}public function count(){return 0;}}
function Model($name=null){if($name===null)return new FakeRootModel();if($name==='User')return new FakeUserModel();if($name==='plugin_ai_drive_agent')return new FakeAgentModel();if($name==='plugin_ai_drive_token')return new FakeTokenModel();if($name==='plugin_ai_drive_audit')return new FakeAuditModel();throw new RuntimeException('unexpected model '.$name);}

require dirname(__DIR__).'/plugins/aiDrive/lib/AgentStore.class.php';

function assert_true($value,$message){if(!$value)throw new RuntimeException($message);}
function assert_same($expected,$actual,$message){if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

$store=new AiDriveAgentStore(null);
$listed=$store->listAgents();
assert_same('aaaaaaaaaaaa',$listed[0]['tokenFingerprint'],'list must expose only a short hash fingerprint');
assert_true(!isset($listed[0]['tokenHash']),'list must never expose the stored token hash');

$beforeID=FakeAgentState::$row['id'];$beforeHash=FakeAgentState::$row['tokenHash'];
$rotated=$store->rotateAgent('agent_test');
assert_same($beforeID,FakeAgentState::$row['id'],'rotation must update the existing Agent record');
assert_true($beforeHash!==FakeAgentState::$row['tokenHash'],'rotation must replace the old token hash');
assert_same($beforeHash,FakeTokenModel::$rows[0]['tokenHash'],'rotation must retain the old hash during the grace period');
assert_true(FakeTokenModel::$rows[0]['expiresAt']>time(),'old token grace period must expire in the future');
assert_same(hash('sha256',$rotated['token']),FakeAgentState::$row['tokenHash'],'stored hash must match the one-time token');
assert_same(69,strlen($rotated['token']),'token must have the documented length');
assert_same(1,FakeAgentState::$row['status'],'rotation must reactivate the Agent');
assert_same(0,FakeAgentState::$row['lastUsedAt'],'rotation must reset last-used time');
assert_same(substr(FakeAgentState::$row['tokenHash'],0,12),$rotated['tokenFingerprint'],'returned fingerprint must match the stored hash');
assert_true(strpos($rotated['copyPrompt'],$rotated['token'])!==false,'one-time recovery prompt must include the new token');

echo "AI Drive token rotation tests passed\n";
