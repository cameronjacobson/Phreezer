<?php

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Phreezer\Phreezer;
use Phreezer\Storage\CouchDB;

$lazyProxy = false;
$blacklist = array();
$useAutoload = true;

$freezer = new Phreezer([
	'blacklist' => $blacklist,
	'autoload'  => $useAutoload
]);

$a = new CouchDB([
	'database'  => 'mydb',
	'host'      => 'localhost',
	'port'      => 5984,
	'lazyproxy' => $lazyProxy,
	'freezer'   => $freezer
]);

var_dump($a);
