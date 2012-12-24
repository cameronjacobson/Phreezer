<?php

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Phreezer\Phreezer;
use Phreezer\Storage\CouchDB;


#########################################
// LONG CONSTRUCTOR

$lazyProxy = false;
$blacklist = array();
$useAutoload = true;

$freezer = new Phreezer([
	'blacklist' => $blacklist,
	'autoload'  => $useAutoload
]);

$couch = new CouchDB([
	'database'  => 'mydb',
	'host'      => 'localhost',
	'port'      => 5984,
	'lazyproxy' => $lazyProxy,
	'freezer'   => $freezer
]);
var_dump($couch);


#########################################
// SHORTCUT CONSTRUCTOR : only 'database' is required argument

$couch = new CouchDB([
	'database'=>'mydb'
]);
var_dump($couch);
