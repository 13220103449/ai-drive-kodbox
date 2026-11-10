<?php
/**
 * v0.9.0 mirror engine unit tests (stub environment, no live Baidu calls):
 * config resolution, tree scan, reconcile enqueue/retire semantics, queue
 * state transitions and the one-way guarantee (remote-stored files are skipped).
 */
define('PLUGIN_DIR', sys_get_temp_dir().'/aidrive-mirror-test-'.bin2hex(random_bytes(4)).'/');
mkdir(PLUGIN_DIR.'baiduDisk/lib',0777,true);

function _get($data,$key,$default=null){return is_array($data)&&array_key_exists($key,$data)?$data[$key]:$default;}
function show_json($data,$code=true){throw new RuntimeException(json_encode(array('code'=>$code,'data'=>$data)));}
define('REPEAT_SKIP',0);define('REPEAT_REPLACE',1);
define('TEMP_FILES',sys_get_temp_dir().'/aidrive-mirror-tmp/');
mkdir(TEMP_FILES,0777,true);
define('DATA_PATH',sys_get_temp_dir().'/aidrive-mirror-data/');
mkdir(DATA_PATH,0777,true);

/* ---- fake storage world: user space tree owned by userID=8 ---- */
class FakeMirrorWorld {
	public static $sources=array(
		100=>array('sourceID'=>100,'parentID'=>0,'targetType'=>1,'targetID'=>8,'name'=>'adminspace','isFolder'=>1,'isDelete'=>0,'size'=>0,'modifyTime'=>100,'fileID'=>0),
		101=>array('sourceID'=>101,'parentID'=>100,'name'=>'docs','isFolder'=>1,'isDelete'=>0,'size'=>0,'modifyTime'=>100,'fileID'=>0),
		102=>array('sourceID'=>102,'parentID'=>101,'name'=>'local.txt','isFolder'=>0,'isDelete'=>0,'size'=>10,'modifyTime'=>200,'fileID'=>1),
		103=>array('sourceID'=>103,'parentID'=>101,'name'=>'remote.txt','isFolder'=>0,'isDelete'=>0,'size'=>99,'modifyTime'=>200,'fileID'=>2),
		104=>array('sourceID'=>104,'parentID'=>101,'name'=>'AI Drive回收站(勿删)','isFolder'=>1,'isDelete'=>0,'size'=>0,'modifyTime'=>100,'fileID'=>0),
		105=>array('sourceID'=>105,'parentID'=>104,'name'=>'hidden.bin','isFolder'=>0,'isDelete'=>0,'size'=>5,'modifyTime'=>200,'fileID'=>3),
	);
	public static $files=array(
		1=>array('fileID'=>1,'ioType'=>1,'size'=>10),  // local storage
		2=>array('fileID'=>2,'ioType'=>4,'size'=>99),  // already on Baidu
		3=>array('fileID'=>3,'ioType'=>1,'size'=>5),
	);
	public static $mirrorRows=array();public static $mirrorSeq=0;public static $lastSave=null;
	public static $baiduCalls=array();
}
class FakeMirrorSourceModel {
	private $where;public function where($w){$this->where=$w;return $this;}
	public function select(){if(isset($this->where['parentID'])){ $out=array();foreach(FakeMirrorWorld::$sources as $s){if(intval($s['parentID'])===intval($this->where['parentID'])&&intval($s['isDelete'])===0)$out[]=$s;}return $out;}return array();}
	public function find(){foreach(FakeMirrorWorld::$sources as $s){$ok=true;foreach($this->where as $k=>$v){if(strval(_get($s,$k))!==strval($v)){$ok=false;break;}}if($ok)return $s;}return false;}
	public function field($f){return $this;}
}
class FakeMirrorFileModel {
	private $where;public function where($w){$this->where=$w;return $this;}
	public function find(){foreach(FakeMirrorWorld::$files as $f){if(intval($f['fileID'])===intval($this->where['fileID']))return $f;}return false;}
	public function field($f){return $this;}
}
class FakeMirrorUserModel {
	public function where($w){return $this;}
	public function find(){return array('userID'=>8,'status'=>1,'name'=>'admin');}
	public function getInfoSimple($id){return array('userID'=>$id,'status'=>1,'name'=>'admin');}
}
class FakeMirrorGroupModel {private $where;public function where($w){$this->where=$w;return $this;}public function find(){return false;}public function getInfo($id){return array('groupID'=>$id,'name'=>'智能体','parentID'=>1);}
	public function groupAdd($data){return 2;}
}
class FakeMirrorStorageModel {public function listData(){return array(array('id'=>4,'name'=>'aipan','driver'=>'Baidu'));}public function getConfig($id){return array('access_token'=>'tok','refresh_token'=>'r','token_expire_time'=>time()+99999);}}
class FakeMirrorTableModel {
	public static $lastSave=null;
	private $where;public function setDataAuto($v){return $this;}
	public function where($w){$this->where=$w;return $this;}
	public function select(){$out=array();foreach(FakeMirrorWorld::$mirrorRows as $r){$ok=true;foreach($this->where as $k=>$v){if(strval(_get($r,$k))!==strval($v)){$ok=false;break;}}if($ok)$out[]=$r;}return $out;}
	public function find(){foreach($this->select() as $r)return $r;return false;}
	public function limit($n){return $this;}
	public function order($o){return $this;}
	public function add($data){$data=array_merge(array('id'=>++FakeMirrorWorld::$mirrorSeq,'state'=>'pending'),$data);FakeMirrorWorld::$mirrorRows[$data['id']]=$data;return $data['id'];}
	public function save($data){$found=0;foreach($this->select() as $r){$found++;FakeMirrorWorld::$mirrorRows[$r['id']]=array_merge($r,$data);FakeMirrorWorld::$lastSave=$data;}return $found;}
	public function delete(){return 1;}
}
class FakeMirrorDb {public function getTables(){return array_keys(array_flip(array('plugin_ai_drive_agent','plugin_ai_drive_audit','plugin_ai_drive_token','plugin_ai_drive_version','plugin_ai_drive_trash','plugin_ai_drive_update','plugin_ai_drive_request','plugin_ai_drive_mirror')));}public function getFields($t){return array('homeDir'=>array(),'id'=>array());}public function execute($sql){return true;}}
function Model($name=null){
	if($name===null)return new FakeMirrorRootModel();
	$map=array('Source'=>'FakeMirrorSourceModel','File'=>'FakeMirrorFileModel','User'=>'FakeMirrorUserModel','Group'=>'FakeMirrorGroupModel','Storage'=>'FakeMirrorStorageModel','plugin_ai_drive_mirror'=>'FakeMirrorTableModel','plugin_ai_drive_agent'=>'FakeMirrorTableModel');
	if(isset($map[$name]))return new $map[$name];
	throw new RuntimeException('unexpected model '.$name);
}
class FakeMirrorRootModel {public function db(){return new FakeMirrorDb();}}
class IO {
	public static function infoFull($path){$id=KodIO::sourceID($path);foreach(FakeMirrorWorld::$sources as $s){if(intval($s['sourceID'])===$id)return array('sourceID'=>$id,'type'=>intval($s['isFolder'])===1?'folder':'file','size'=>intval($s['size']),'name'=>$s['name'],'path'=>$path);}return false;}
	public static function fileSubstr($path,$start,$length){$id=KodIO::sourceID($path);if($id===102)return substr('0123456789',$start,$length);return false;}
	public static function getLastError($f){return $f;}
}
class KodIO {public static function make($id){return '{source:'.intval($id).'}';}public static function sourceID($p){return preg_match('/^\{source:(\d+)\}/',strval($p),$m)?intval($m[1]):0;}}
/** Fake Baidu driver pre-registered so the engine picks it up instead of the real file. */
class PathDriverBaidu {
	public static $calls=array();
	public function __construct($config){self::$calls[]='construct';}
	public function mkdir($p){self::$calls[]='mkdir '.$p;return true;}
	public function upload($dest,$local,$m=false,$r=1){self::$calls[]='upload '.$dest;$content=file_get_contents($local);if($content===false)return false;return array('size'=>strlen($content));}
	public function moveFile($from,$to){self::$calls[]='move '.$from.' -> '.$to;return true;}
	public function fileInfo($p,$s=false){foreach(FakeMirrorWorld::$mirrorRows as $r){if($r['baiduPath']===$p&&$r['state']==='synced')return array('size'=>$r['size'],'name'=>basename($p));}return false;}
	public function fileOut($p,$d=false,$n=false){self::$calls[]='fileOut '.$p;echo 'BAIDUCONTENT';}
}
if(!defined('KOD_VERSION'))define('KOD_VERSION','1.71');
class PluginBase {public $in=array();public $pluginPath='';private $configStore=array('isOpen'=>'1','mirrorEnabled'=>'1','mirrorUsers'=>'admin');
	public function getConfig(){$c=new FakeMirrorConfig($this->configStore);return $c->data;}
	public function setConfigValue($k,$v){$this->configStore[$k]=$v;}
}
class FakeMirrorConfig {public $data;public function __construct($d){$this->data=$d;}}

require dirname(__DIR__).'/plugins/aiDrive/lib/MirrorEngine.class.php';
require dirname(__DIR__).'/plugins/aiDrive/lib/AgentStore.class.php';
class aiDrivePlugin extends PluginBase {public $pluginPath='';}
$plugin=new aiDrivePlugin();

function assert_same($e,$a,$m){if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}

$engine=new AiDriveMirrorEngine($plugin);

/* 1. config */
$c=$engine->config();
assert_same(true,$c['enabled'],'mirror enabled by fixture');
assert_same(array('admin'),$c['users'],'configured mirror user');
assert_same('/AI-Drive镜像',$c['root'],'default mirror root');

/* 2. reconcile: local file queued, remote-stored skipped, protection tree ignored */
$stats=$engine->reconcileSpace(1,8,'admin');
assert_same(1,$stats['queued'],'one local file queued');
assert_same(1,$stats['dirs'],'one directory queued');
assert_same(1,$stats['skippedRemote'],'remote-stored file skipped');
assert_same(3,$stats['total'],'scan sees 3 visible entries (protection tree excluded)');
$paths=array();foreach(FakeMirrorWorld::$mirrorRows as $r)$paths[$r['relPath']]=$r;
assert_same('pending',$paths['/docs/local.txt']['state'],'local file pending');
assert_same('/AI-Drive镜像/admin/docs/local.txt',$paths['/docs/local.txt']['baiduPath'],'baidu mirror path');

/* 3. reconcile again: unchanged rows must not be re-queued */
$before=array();foreach(FakeMirrorWorld::$mirrorRows as $r)$before[$r['relPath']]=$r['updatedAt'];
$stats2=$engine->reconcileSpace(1,8,'admin');
assert_same(0,$stats2['queued'],'unchanged content not re-queued');
foreach(FakeMirrorWorld::$mirrorRows as $r)assert_same($before[$r['relPath']],$r['updatedAt'],'unchanged rows untouched');

/* 4. process pending: dir mkdir + file upload, then synced */
$out=$engine->processPending(10);
assert_same(2,$out['processed'],'both pending rows processed');
if($out['failed']>0){foreach(FakeMirrorWorld::$mirrorRows as $r)if(!empty($r['lastError']))echo 'ERR: ',$r['relPath'],' => ',$r['lastError'],"\n";}
assert_same(0,$out['failed'],'no failures');
$paths=array();foreach(FakeMirrorWorld::$mirrorRows as $r)$paths[$r['relPath']]=$r;
assert_same('synced',$paths['/docs/local.txt']['state'],'file synced after upload');
assert_same('synced',$paths['/docs']['state'],'dir synced after mkdir');
$uploads=0;foreach(PathDriverBaidu::$calls as $call)if(strpos($call,'upload /AI-Drive镜像/admin/docs/local.txt')===0)$uploads++;
assert_same(1,$uploads,'exactly one baidu upload for local.txt');

/* 5. content change re-queues; state returns to pending then synced */
FakeMirrorWorld::$sources[102]['modifyTime']=999;
$stats3=$engine->reconcileSpace(1,8,'admin');
assert_same(1,$stats3['updated'],'changed file re-queued');
$paths3=array();foreach(FakeMirrorWorld::$mirrorRows as $r)$paths3[$r['relPath']]=$r;
assert_same('pending',$paths3['/docs/local.txt']['state'],'changed file is pending again');
$engine->processPending(10);
$paths=array();foreach(FakeMirrorWorld::$mirrorRows as $r)$paths[$r['relPath']]=$r;
assert_same('synced',$paths['/docs/local.txt']['state'],'changed file synced again');

/* 6. local removal retires to baidu trash folder */
unset(FakeMirrorWorld::$sources[102]);
$stats4=$engine->reconcileSpace(1,8,'admin');
assert_same(1,$stats4['retired'],'locally-gone file retired');
$paths=array();foreach(FakeMirrorWorld::$mirrorRows as $r)$paths[$r['relPath']]=$r;
assert_same('deleted',$paths['/docs/local.txt']['state'],'retired row marked deleted');
$moved=false;foreach(PathDriverBaidu::$calls as $call)if(strpos($call,'move /AI-Drive镜像/admin/docs/local.txt -> /AI-Drive镜像回收站/admin/docs/local.txt')===0)$moved=true;
assert_same(true,$moved,'baidu copy moved into the visible mirror trash folder');

/* 7. findSynced + disaster stream path */
$hit=$engine->findSynced(1,8,'/docs/local.txt');
assert_same(false,$hit,'deleted file is not offered for disaster read');
$hit2=$engine->findSynced(1,8,'/docs/remote.txt');
assert_same(false,$hit2,'remote-stored file has no mirror row');
$hit3=$engine->findSynced(1,8,'/docs');
assert_same(false,$hit3,'directories are not disaster-read targets');

/* 8. stats shape */
$stats5=$engine->stats();
assert_same(true,$stats5['enabled'],'stats reports enabled');
assert_same(1,$stats5['byState']['deleted'],'stats counts deleted rows');

echo "AI Drive mirror engine tests passed\n";
