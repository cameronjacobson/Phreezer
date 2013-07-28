<?php

namespace Phreezer\Storage\CouchDB;

use \Phreezer\Phreezer;

class View
{
	private $callbacks = array();
	private $buffers = array();

	public function __construct($couch){
		$this->couch = $couch;
	}

	private function prepParams(&$params){
		$params['opts'] = @$params['opts'] ?: array();
		$params['query'] = @$params['opts'] ? $params['query'] : $params;
	}

	public function async($view, $params = array('query'=>array(), 'opts'=>array())){
		$this->prepParams($params);
		$url = '/'.$this->couch->database.'/_design/'.$this->couch->database.'/_view/'.$view;
		$this->couch->transport->get($url);

		$this->callbacks[$this->couch->transport->getCount()] = function($result) use($params) {
			// whitelist meta-data for inclusion in result
			if(@$params['opts']['filter']){
				$filtered = $this->filter($params['opts']['filter'], json_decode($result,true), $params['opts']);
				if(@$params['debug']){
					error_log('DEBUG _view filtered result: '.json_encode($filtered));
				}
				return @$params['opts']['json'] ? json_encode($filtered) : $filtered;
			}
			elseif(!empty($params['opts']['thaw'])){
				$return = array();
				$phreezer = new Phreezer();
				$result = json_decode($result, true);
				foreach($result['rows'] as $k=>&$v){
					$object = array(
                        'objects'=>array($v['doc']['_id']=>array(
                            'className'=>$v['doc']['class'],
                            'state'=>$v['doc']['state']
                        ))
                    );
					$return[$v['id']] = $phreezer->thaw($object,$v['doc']['_id']);
					$this->couch->setRevision($v['doc']['_id'], $v['doc']['_rev']);
				}
				return $return;
			}
			return @$params['opts']['json'] ? $result : json_decode($result, true);
		};
		$this->callbacks[$this->couch->transport->getCount()]->bindTo($this);
	}

	public function fetch(){
		$this->couch->transport->fetch();
		$buffers = $this->couch->transport->getBuffers('body');
		foreach($buffers as $key=>$buffer){
			$this->buffers[$key] = $this->callbacks[$key]($buffer);
			$this->cleanup($key);
		}
	}

	public function flush(){
		$this->couch->transport->flush();
		$this->callbacks = array();
		$this->buffers = array();
	}

	public function getBuffers(){
		return $this->buffers;
	}

	// TODO: ?? Swap out with SimpleHttpClient
	public function query($view, $params = array('query'=>array(),'opts'=>array())) {
		$this->prepParams($params);
		$view = '/'.$this->couch->database.'/_design/'.$this->couch->database.'/_view/'.$view;
		$qs = empty($params['query']) ? '' : '?'.http_build_query($params['query']);
		$this->async($view.$qs, $params);
		$this->couch->transport->fetch();
		$buffers = $this->couch->transport->getBuffers('body');
		$result = $this->callbacks[1]($buffers[1]);
		$this->cleanup(1);
		return $result;
	}

	private function cleanup($index){
		unset($this->callbacks[$index]);
	}

	private function filter($filtername, $data, $opts){
		$return = array('rows'=>array());
		foreach($data['rows'] as $k=>&$v){
			$buff = array();
			switch($filtername){
				case 'id_only':
					$buff = $v['id'];
					break;
				case 'key_only':
					$buff = $v['key'];
					break;
				case 'doc_only':
					$buff = $v['doc'];
					break;
				case 'docstate_only':
					$buff = $v['doc']['state'];
					$k = $v['id'];
					break;
				case 'value_only':
					$buff = $v['value'];
					break;
				default:
					throw new \Exception('Invalid filter on Couch View: '.$filtername);
					break;
			}
			if(!is_string($buff)){
				if(!empty($opts['blacklist'])){
					foreach($opts['blacklist'] as $key){
						unset($buff[$key]);
					}
				}
				elseif(!empty($opts['whitelist'])){
					$tmp = array();
					foreach($opts['whitelist'] as $key){
						$tmp[$key] = $buff[$key];
					}
					$buff = $tmp;
				}
			}
			$return['rows'][$k] = $buff;
		}
		return $return;
	}
}
