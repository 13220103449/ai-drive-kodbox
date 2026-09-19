<?php

define('REPEAT_SKIP', 0);

function _get($data, $key, $default = null) {return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;}
function show_json($data, $code = true) {throw new RuntimeException(json_encode(array('code' => $code, 'data' => $data)));}
function Model($name) {if ($name !== 'Source') throw new RuntimeException('unexpected model: '.$name);return new FakeSourceModel();}

class PluginBase {public function __construct() {}}
class FakeSourceStore {
	public static $rows = array(
		1 => array('sourceID' => 1, 'parentID' => 0, 'name' => 'root', 'isFolder' => 1, 'isDelete' => 0),
		2 => array('sourceID' => 2, 'parentID' => 1, 'name' => '我的文档', 'isFolder' => 1, 'isDelete' => 0),
		3 => array('sourceID' => 3, 'parentID' => 2, 'name' => 'agents-starbucks', 'isFolder' => 1, 'isDelete' => 0),
	);
}
class FakeSourceModel {
	private $where = array();
	public function where($where) {$this->where = $where;return $this;}
	public function field($fields) {return $this;}
	public function find() {
		foreach (FakeSourceStore::$rows as $row) {
			$match = true;foreach ($this->where as $key => $value) if (strval($row[$key]) !== strval($value)) {$match = false;break;}
			if ($match) return $row;
		}
		return false;
	}
}
class KodIO {
	public static function make($sourceID) {return '{source:'.intval($sourceID).'}/';}
	public static function sourceID($path) {return preg_match('/^\{source:(\d+)\}/', strval($path), $match) ? intval($match[1]) : 0;}
}
class IO {
	public static $listCalls = 0;
	public static function infoFull($path) {
		if (!preg_match('/^\{source:(\d+)\}\/\z/', strval($path), $match)) return false;
		$id = intval($match[1]);if (!isset(FakeSourceStore::$rows[$id])) return false;$row = FakeSourceStore::$rows[$id];
		return array('sourceID' => $id, 'name' => $row['name'], 'type' => $row['isFolder'] ? 'folder' : 'file', 'path' => KodIO::make($id));
	}
	public static function listPath($path) {self::$listCalls++;return array('folderList' => array(array('name' => '我的文档', 'path' => KodIO::make(1))));}
	public static function mkdir($path, $repeat) {
		if (!preg_match('/^\{source:(\d+)\}\/(.+)$/u', strval($path), $match)) return false;
		$parentID = intval($match[1]);$name = $match[2];$id = max(array_keys(FakeSourceStore::$rows)) + 1;
		FakeSourceStore::$rows[$id] = array('sourceID' => $id, 'parentID' => $parentID, 'name' => $name, 'isFolder' => 1, 'isDelete' => 0);
		return KodIO::make($id);
	}
}

require dirname(__DIR__).'/plugins/aiDrive/app.php';

function call_private($object, $method, array $arguments) {
	$reflection = new ReflectionMethod($object, $method);$reflection->setAccessible(true);return $reflection->invokeArgs($object, $arguments);
}
function assert_same($expected, $actual, $message) {
	if ($expected !== $actual) throw new RuntimeException($message.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
}

$plugin = (new ReflectionClass('aiDrivePlugin'))->newInstanceWithoutConstructor();
$root = KodIO::make(1);

assert_same(KodIO::make(2), call_private($plugin, 'agentPath', array($root, '/我的文档')), 'alias must resolve to the real child');
assert_same(KodIO::make(3), call_private($plugin, 'agentPath', array($root, '/我的文档/agents-starbucks')), 'nested path must resolve exactly');
assert_same(false, call_private($plugin, 'agentPath', array($root, '/missing/child')), 'missing intermediate path must not fall back to root');
assert_same($root.'missing', call_private($plugin, 'agentPath', array($root, '/missing')), 'missing final path may only be represented as a nonexistent target');
assert_same(0, IO::$listCalls, 'path resolution must not use display-list aliases');

$created = call_private($plugin, 'ensureFolderPath', array($root, '/new-parent/new-child'));
$createdInfo = IO::infoFull($created);
assert_same('new-child', $createdInfo['name'], 'mkdir must create the complete directory chain');
$resolved = call_private($plugin, 'agentPath', array($root, '/new-parent/new-child'));
assert_same($created, $resolved, 'new nested folder must resolve through the same strict hierarchy');

echo "AI Drive strict path tests passed\n";
