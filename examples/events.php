<?php

// Shows how to use 'Phixd' events
// There are currently 2 events fired inside Phreezer\Storage
//   -beforestore
//   -afterfetch

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Phreezer\Storage\CouchDB;
use Phixd\Phixd;

$lazyProxy = false;
$blacklist = array();
$useAutoload = true;

$start = microtime(true);

$couch = new CouchDB([
	'database'  => 'mydb',
	'user'      => 'cameron',
	'pass'      => 'cameron'
]);

Phixd::on('beforestore', function($obj){
	echo 'BEFORE STORE: '.$obj->c.PHP_EOL;
});

Phixd::on('afterfetch', function($obj){
	echo 'AFTER FETCH: '.$obj->b.PHP_EOL;
});


$obj = new blah();
$obj->a = 1+$x;
$obj->b = 2+$x;
$obj->c = 3+$x;

echo 'STORING RECORD: ';
$id = $couch->store($obj);
echo $id.PHP_EOL;
echo PHP_EOL;

echo 'FETCHING: '.$id.PHP_EOL;
$obj = $couch->fetch($id);
echo 'UPDATING: '.$obj->a.' TO "'.$obj->blah().'"'.PHP_EOL;
$obj->a = $obj->blah();
echo 'STORING UPDATED VERSION OF: '.$id.PHP_EOL;
$couch->store($obj);
echo PHP_EOL;

echo 'FETCHING: '.$id.PHP_EOL;
$obj = $couch->fetch($id);
echo 'DELETING: '.$id.PHP_EOL;
$obj->_delete = true;
$couch->store($obj);
echo PHP_EOL;

echo 'COMPLETED IN: '.(microtime(true)-$start).' SECONDS'.PHP_EOL;

class blah
{
	public $a;
	public $b;
	public $c;
	public function blah(){
		return 'blahblah';
	}
}

