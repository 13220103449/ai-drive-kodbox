<?php
$tmp=sys_get_temp_dir().'/aidrive-maintenance-'.bin2hex(random_bytes(4)).'/';mkdir($tmp,0777,true);define('DATA_PATH',$tmp);define('PLUGIN_DIR',$tmp.'plugins/');
function path_writeable($path){return is_writable($path);}function mk_dir($path){return is_dir($path)||mkdir($path,0777,true);}function _get($data,$key,$default=null){return is_array($data)&&array_key_exists($key,$data)?$data[$key]:$default;}
class BackupRows {private $name;public function __construct($name){$this->name=$name;}public function order($value){return $this;}public function limit($value){return $this;}public function select(){return array($this->name==='plugin_ai_drive_version'?array('id'=>1,'blobPath'=>''):array('id'=>1,'table'=>$this->name));}}
function Model($name){return new BackupRows($name);}
class BackupStoreStub {public function initTable(){}public function listAgents(){return array(array('agentID'=>'agent-test'));}public function listAudit($filters){return array(array('action'=>'upload'));}}
require dirname(__DIR__).'/plugins/aiDrive/lib/Maintenance.class.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$service=new AiDriveMaintenance(new BackupStoreStub());$result=$service->backup();
check(strpos($result['scope'],'metadata only')!==false,'backup result must disclose its limited scope');
check(in_array('trash',$result['included'],true),'trash metadata should be included');
$file=DATA_PATH.$result['file'];$payload=json_decode(file_get_contents($file),true);
check($payload['format']==='ai-drive-metadata-backup-v2','unexpected metadata snapshot format');
check(isset($payload['trash'][0])&&isset($payload['versions'][0]),'metadata snapshot omitted protection records');
check(strpos($payload['limitations'][0],'KodBox database')!==false,'snapshot must state that full KodBox database is not included');
check(hash_file('sha256',$file)===$result['sha256'],'reported snapshot checksum mismatch');
unlink($file);rmdir(dirname($file));rmdir(DATA_PATH.'ai-drive');rmdir(DATA_PATH);
echo "AI Drive metadata backup scope tests passed\n";
