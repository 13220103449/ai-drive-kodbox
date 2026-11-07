<?php

define('REPEAT_SKIP', 0);
define('REPEAT_REPLACE', 1);
define('REPEAT_RENAME', 2);
define('REPEAT_RENAME_FOLDER', 3);

function _get($data, $key, $default = null) {return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;}
function show_json($data, $code = true) {throw new RuntimeException(json_encode(array('code' => $code, 'data' => $data)));}
function Model($name) {
	if ($name === 'Share') return new FakeShareModel();
	if ($name === 'User') return new FakeUserModel();
	throw new RuntimeException('unexpected model: '.$name);
}

class PluginBase {public $in = array();public function __construct() {}}

class FakeShareModel {
	public static $rows = array(
		'pub123' => array('shareID' => 11, 'shareHash' => 'pub123', 'isLink' => '1', 'title' => '公开资料', 'userID' => 7,
			'password' => '', 'timeTo' => 0, 'numDownload' => 0, 'options' => array(),
			'sourceInfo' => array('path' => '{source:100}/公开资料', 'isDelete' => 0)),
		'pwd456' => array('shareID' => 12, 'shareHash' => 'pwd456', 'isLink' => '1', 'title' => '加密资料', 'userID' => 7,
			'password' => 'test123', 'timeTo' => 0, 'numDownload' => 0, 'options' => array('downloadNumber' => 5),
			'sourceInfo' => array('path' => '{source:100}/加密资料', 'isDelete' => 0)),
		'old789' => array('shareID' => 13, 'shareHash' => 'old789', 'isLink' => '1', 'title' => '过期分享', 'userID' => 7,
			'password' => '', 'timeTo' => 1000, 'numDownload' => 0, 'options' => array(),
			'sourceInfo' => array('path' => '{source:100}/过期分享', 'isDelete' => 0)),
		'lim000' => array('shareID' => 14, 'shareHash' => 'lim000', 'isLink' => '1', 'title' => '限次分享', 'userID' => 7,
			'password' => '', 'timeTo' => 0, 'numDownload' => 5, 'options' => array('downloadNumber' => 5),
			'sourceInfo' => array('path' => '{source:100}/限次分享', 'isDelete' => 0)),
		'del111' => array('shareID' => 15, 'shareHash' => 'del111', 'isLink' => '1', 'title' => '已删分享', 'userID' => 7,
			'password' => '', 'timeTo' => 0, 'numDownload' => 0, 'options' => array(),
			'sourceInfo' => array('path' => '{source:100}/已删分享', 'isDelete' => 1)),
		'nolink' => array('shareID' => 16, 'shareHash' => 'nolink', 'isLink' => '0', 'title' => '协作分享', 'userID' => 7,
			'password' => '', 'timeTo' => 0, 'numDownload' => 0, 'options' => array(),
			'sourceInfo' => array('path' => '{source:100}/协作分享', 'isDelete' => 0)),
		'nosave' => array('shareID' => 17, 'shareHash' => 'nosave', 'isLink' => '1', 'title' => '禁止转存', 'userID' => 7,
			'password' => '', 'timeTo' => 0, 'numDownload' => 0, 'options' => array('notDownload' => '1'),
			'sourceInfo' => array('path' => '{source:100}/禁止转存', 'isDelete' => 0)),
	);
	public function getInfoByHash($hash) {return _get(self::$rows, strval($hash), false);}
}
class FakeUserModel {
	public function getInfoSimpleOuter($userID) {return $userID === 7 ? array('name' => 'owner-user') : array('name' => '');}
}
class IO {
	public static function getLastError($default = 'error') {return $default;}
}

require dirname(__DIR__).'/plugins/aiDrive/app.php';

function call_private($object, $method, $args) {
	$reflection = new ReflectionMethod($object, $method);
	$reflection->setAccessible(true);
	return $reflection->invokeArgs($object, $args);
}
function assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {fwrite(STDERR, "FAIL: $message\n  expected: ".var_export($expected, true)."\n  actual:   ".var_export($actual, true)."\n");exit(1);}
}

$plugin = new aiDrivePlugin();

// shareLinkHash: accepts full share urls and bare hashes
assert_same('EEzncfhm', call_private($plugin, 'shareLinkHash', array(array('url' => 'https://pan.example.com/#s/EEzncfhm'))), 'url fragment #s/<hash> must be extracted');
assert_same('EEzncfhm', call_private($plugin, 'shareLinkHash', array(array('url' => 'https://pan.example.com/index.php#s/EEzncfhm?from=mail'))), 'trailing query after the hash must be ignored');
assert_same('EFEhp-H3', call_private($plugin, 'shareLinkHash', array(array('shareHash' => 'EFEhp-H3'))), 'hashes with hyphens must survive');
assert_same('EFEhqC_H', call_private($plugin, 'shareLinkHash', array(array('url' => 'https://pan.example.com/#s/EFEhqC_H'))), 'hashes with underscores must survive');
assert_same('abc123', call_private($plugin, 'shareLinkHash', array(array('shareHash' => 'abc123'))), 'shareHash parameter must be accepted');
assert_same('abc123', call_private($plugin, 'shareLinkHash', array(array('url' => 'abc123'))), 'a bare hash in url must be accepted');
assert_same('', call_private($plugin, 'shareLinkHash', array(array())), 'no input must produce an empty hash');

// shareSubParts: traversal and NUL are rejected
assert_same(array(), call_private($plugin, 'shareSubParts', array('')), 'empty sharePath must address the shared item itself');
assert_same(array('a', 'b.txt'), call_private($plugin, 'shareSubParts', array('/a/b.txt')), 'sharePath must be split into safe parts');
assert_same(false, call_private($plugin, 'shareSubParts', array('../secret')), 'parent traversal must be rejected');
assert_same(false, call_private($plugin, 'shareSubParts', array("a\0b")), 'NUL bytes must be rejected');

// shareRealPath: owner-side path is resolved from sourceInfo
$share = FakeShareModel::$rows['pub123'];
assert_same('{source:100}/公开资料', call_private($plugin, 'shareRealPath', array($share, array())), 'share root must resolve to the source path');
assert_same('{source:100}/公开资料/doc.pdf', call_private($plugin, 'shareRealPath', array($share, array('doc.pdf'))), 'share sub path must append below the source path');

// shareItemInfo: never leaks owner-side paths
$item = call_private($plugin, 'shareItemInfo', array(array('name' => 'doc.pdf', 'type' => 'file', 'ext' => 'pdf', 'size' => 9, 'path' => '{source:999}/secret/doc.pdf', 'sourceID' => 999)));
assert_same(array('name' => 'doc.pdf', 'type' => 'file', 'ext' => 'pdf', 'size' => 9, 'createTime' => 0, 'modifyTime' => 0), $item, 'share item info must expose safe fields only');

// shareResolve: validation chain mirrors the share page without sessions
$fail = call_private($plugin, 'shareResolve', array(array()));
assert_same('shareHash or url is required; a share link looks like https://host/#s/<hash>', $fail['error'], 'missing hash must be reported');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'missing')));
assert_same('share link not found', $fail['error'], 'unknown hash must be reported as not found');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'nolink')));
assert_same('share link not found', $fail['error'], 'a non-link share must be reported as not found');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'del111')));
assert_same('shared content was deleted by its owner', $fail['error'], 'deleted share source must be reported');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'old789')));
assert_same('share link has expired', $fail['error'], 'expired share must be reported');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'lim000')));
assert_same('share download limit has been reached', $fail['error'], 'exhausted download limit must be reported');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'pwd456')));
assert_same('share password required', $fail['error'], 'protected share without password must demand one');
assert_same(true, $fail['needPassword'], 'protected share without password must set needPassword');
assert_same(false, $fail['share']['passwordOk'], 'meta must not pretend the password was accepted');
$fail = call_private($plugin, 'shareResolve', array(array('shareHash' => 'pwd456', 'password' => 'wrong')));
assert_same('share password is incorrect', $fail['error'], 'wrong password must be rejected');

$ok = call_private($plugin, 'shareResolve', array(array('url' => 'https://pan.example.com/#s/pwd456', 'password' => 'test123')));
assert_same('pwd456', $ok['share']['shareHash'], 'valid protected share must resolve');
assert_same('owner-user', $ok['meta']['owner'], 'meta must carry the share owner name');
assert_same(true, $ok['meta']['passwordOk'], 'meta must record the accepted password');
assert_same(true, $ok['meta']['saveAllowed'], 'share without notDownload must be saveable');
assert_same(5, $ok['meta']['downloadLimit'], 'meta must expose the download limit');

$ok = call_private($plugin, 'shareResolve', array(array('shareHash' => 'nosave')));
assert_same(true, $ok['meta']['passwordRequired'] === false && $ok['meta']['saveAllowed'] === false, 'notDownload share must resolve but stay unsavable');

echo "ai_drive_share_test: all assertions passed\n";
