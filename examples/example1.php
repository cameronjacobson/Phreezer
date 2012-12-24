<?php

require_once(dirname(__DIR__).'/vendor/autoload.php');

use Phreezer\Phreezer;
use Phreezer\Storage\CouchDB;
use Phreezer\Cache;
use Phreezer\IdGenerator\UUID;

$lazyProxy = false;
$blacklist = array();
$useAutoload = true;

$freezer = new Phreezer(
	new UUID(),
	$blacklist,
	$useAutoload
);

$a = new CouchDB('mydb', $freezer, new Cache(), $lazyProxy, 'localhost', 5984);

var_dump($a);
