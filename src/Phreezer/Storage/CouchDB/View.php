<?php

namespace Phreezer\Storage\CouchDB;

class View
{
	public function __construct($couch){
		$this->couch = $couch;
	}

	private function prepParams(&$params){
		$params['opts'] = @$params['opts'] ?: array();
		$params['query'] = @$params['opts'] ? $params['query'] : $params;
	}

	// TODO: ?? Swap out with SimpleHttpClient
	public function query($view, $params = array('query'=>array(),'opts'=>array())) {
		$this->prepParams($params);
		try{
			$ch = curl_init();
			$url = $this->couch->scheme.'://'.$this->couch->host.':'.$this->couch->port;
			$view = '/'.$this->couch->database.'/_design/'.$this->couch->database.'/_view/'.$view;
			$opt1 = $opt2 = array();

			if(!empty($params['keys'])){
				$opt1 = array(
					CURLOPT_POSTFIELDS => json_encode(array(
						'keys' => array_values($params['keys'])
					)),
					CURLOPT_POST => 1,
					CURLOPT_HTTPHEADER => array(
						"Content-Type: application/json"
					)
				);
				unset($params['keys']);
			}

			$qs = empty($params['query']) ? '' : '?'.http_build_query($params['query']);

			if(@$params['debug']){
				error_log('DEBUG _view url: '.$url.$view.$qs);
			}

			$opt2 = array(
				CURLOPT_URL => $url.$view.$qs,
				CURLOPT_HEADER => 0,
				CURLOPT_RETURNTRANSFER => 1
			);
			curl_setopt_array($ch, $opt1+$opt2);

			$result = curl_exec($ch);

			if(@$params['debug']){
				error_log('DEBUG _view raw result: '.$result);
			}

			if(curl_errno($ch)) {
				throw new \Exception('Error: '.curl_error($ch));
			}

			curl_close($ch);

			// whitelist meta-data for inclusion in result
			if(@$params['opts']['filter']){
				$filtered = $this->filter($params['opts']['filter'], json_decode($result,true), $params['opts']);
				if(@$params['debug']){
					error_log('DEBUG _view filtered result: '.json_encode($filtered));
				}
				return @$params['opts']['json'] ? json_encode($filtered) : $filtered;
			}


			return @$params['opts']['json'] ? $result : json_decode($result, true);
		}
		catch(\Exception $e){
			curl_close($ch);
			throw new \Exception($e->getMessage());
		}
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
					throw new Exception('Invalid filter on Couch View: '.$filtername);
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
