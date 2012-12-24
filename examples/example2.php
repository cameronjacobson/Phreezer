<?php

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Phreezer\Phreezer;
use Phreezer\Storage\CouchDB;
use Phreezer\Cache;

$lazyProxy = false;
$blacklist = array();
$useAutoload = true;

$freezer = new Phreezer(
	$blacklist,
	$useAutoload
);

$couch = new CouchDB('mydb', $freezer, new Cache(), $lazyProxy, 'localhost', 5984);

$ids = [];
for($x=0;$x<1;$x++){
	$obj = new blah();
	$obj->a = 1+$x;
	$obj->b = 2+$x;
	$obj->c = 3+$x;
	$ids[] = $id = $couch->store($obj);
	echo 'STORING: '.$id.PHP_EOL;
}
echo PHP_EOL;

foreach($ids as $id){
	echo 'FETCHING: '.$id.PHP_EOL;
	$obj = $couch->fetch($id);
	echo 'UPDATING: '.$obj->a.' TO "'.$obj->blah().'"'.PHP_EOL;
	$obj->a = $obj->blah();
	echo 'STORING UPDATED VERSION OF: '.$id.PHP_EOL;
	$couch->store($obj);
	echo PHP_EOL;
}
echo PHP_EOL;

// verify hashing function prevents resubmission of duplicate object
foreach($ids as $id){
	echo 'FETCHING: '.$id.PHP_EOL;
	$obj = $couch->fetch($id);
	echo 'STORING SAME VERSION OF: '.$id.PHP_EOL;
	$couch->store($obj);
	echo PHP_EOL;
}
echo PHP_EOL;

foreach($ids as $id){
	$obj = $couch->fetch($id);
	echo 'DELETING: '.$id.PHP_EOL;
	$obj->_delete = true;
	$couch->store($obj);
}
echo PHP_EOL;

class blah
{
	public $a;
	public $b;
	public $c;
	public function blah(){
		return 'blahblah';
	}
}

