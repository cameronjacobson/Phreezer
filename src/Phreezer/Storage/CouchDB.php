<?php

/**
 * Object_Freezer
 *
 * Copyright (c) 2008-2012, Sebastian Bergmann <sb@sebastian-bergmann.de>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Sebastian Bergmann nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @package    Object_Freezer
 * @author     Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @copyright  2008-2012 Sebastian Bergmann <sb@sebastian-bergmann.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @since      File available since Release 1.0.0
 */


namespace Phreezer\Storage;

use Phreezer\Phreezer;
use Phreezer\Storage;
use Phreezer\Util;
use Phreezer\Storage\CouchDB\View;
use \SimpleHttpClient\SimpleHttpClient;

class CouchDB extends Storage
{
	public $database;
	/**
	 * @var array
	 */
	protected $revisions = [];

	/**
	 * @var boolean
	 */
	protected $debug = FALSE;

	public $_view;
	public $transport;

	/**
	 * Constructor.
	 *
	 * @param  string            $database      Name of the database to be used
	 * @param  Phreezer          $freezer       Phreezer instance to be used
	 * @param  boolean           $useLazyLoad   Flag that controls whether objects are fetched using lazy load or not
	 * @param  string            $host          Hostname of the CouchDB instance to be used
	 * @param  int               $port          Port of the CouchDB instance to be used
	 * @throws Exception
	 */
	public function __construct(array $options = [])
	{
		$this->transport = new SimpleHttpClient([
			'scheme'      => @$options['scheme'] ?: 'http',
			'host'        => @$options['host']   ?: 'localhost',
			'port'        => @$options['port']   ?: 5984,
			'user'        => @$options['user']   ?: null,
			'pass'        => @$options['pass']   ?: null,
			'contentType' => 'application/json'
		]);

		$options['lazyproxy'] = @$options['lazyproxy'] ?: FALSE;
		$options['freezer'] = @$options['freezer'] ?: null;

		parent::__construct(@$options['lazyproxy'], @$options['freezer']);

		$this->database = $options['database'];

		foreach((array)@$options['services'] as $servicename=>$service){
			switch($servicename){
				case '_view':
				case '_show':
				case '_list':
				case '_filter':
				case '_repl':
					$this->$servicename = $service($this);
					break;
				default:
					throw new \Exception('invalid CouchDB servicename: '.$servicename);
					break;
			}
		}

		if(empty($this->_view)){
			// Default VIEW service
			$this->_view = new View($this);
		}
	}

	public function __get($key){
		switch($key){
			case 'scheme':
			case 'host':
			case 'port':
			case 'user':
			case 'pass':
				return $this->transport->$key;
				break;
		}
	}

	public function __set($key, $value){
		switch($key){
			case 'scheme':
			case 'host':
			case 'port':
			case 'user':
			case 'pass':
				$this->transport->$key = $value;
				break;
		}
	}

	public function setRevision($uuid, $rev){
		$this->revisions[$uuid] = $rev;
	}

	/**
	 * Freezes an object and stores it in the object storage.
	 *
	 * @param array $frozenObject
	 */
	protected function doStore(array $frozenObject)
	{
		$payload = ['docs' => []];

		foreach ($frozenObject['objects'] as $id => $object) {
			$revision = NULL;

			if (isset($this->revisions[$id])) {
				$revision = $this->revisions[$id];
			}

			$data = [
				'_id'   => $id,
				'_rev'  => $revision,
				'class' => $object['className'],
				'state' => $object['state']
			];

			if(isset($data['state']['_delete'])){
				$data['_deleted'] = true;
				unset($object['state']['_delete']);
			}

			if (!$data['_rev']) {
				unset($data['_rev']);
			}

			$payload['docs'][] = $data;
		}

		if (!empty($payload['docs'])) {
			$response = $this->send(
				'POST',
				'/' . $this->database . '/_bulk_docs',
				json_encode($payload)
			);

			if ((strpos($response['headers'], 'HTTP/1.0 201 Created') !== 0)
				&& (strpos($response['headers'], 'HTTP/1.0 200 OK') !== 0)) {
				// @codeCoverageIgnoreStart
				throw new \RuntimeException('Could not save objects.');
				// @codeCoverageIgnoreEnd
			}

			$data = json_decode($response['body'], TRUE);

			foreach ($data as $state) {
				if (isset($state['error'])) {
					// @codeCoverageIgnoreStart
					throw new \RuntimeException(
						sprintf(
							'Could not save object "%s": %s - %s',
							$state['id'],
							$state['error'],
							$state['reason']
						)
					);
					// @codeCoverageIgnoreEnd
				} else {
					$this->revisions[$state['id']] = $state['rev'];
				}
			}
		}
	}

	/**
	 * Fetches a frozen object from the object storage and thaws it.
	 *
	 * @param  string $id      The ID of the object that is to be fetched.
	 * @param  array  $objects Only used internally.
	 * @return object
	 * @throws InvalidArgumentException
	 * @throws RuntimeException
	 */
	protected function doFetch($id, array &$objects = [])
	{
		$isRoot = empty($objects);

		if (!isset($objects[$id])) {
			$response = $this->send(
				'GET', '/' . $this->database . '/' . urlencode($id)
			);

			if (strpos($response['headers'], 'HTTP/1.0 200 OK') !== 0) {
				throw new \RuntimeException(
					sprintf('Object with id "%s" could not be fetched.', $id)
				);
			}

			$object = json_decode($response['body'], TRUE);
			$this->revisions[$object['_id']] = $object['_rev'];

			$objects[$id] = [
				'className' => $object['class'],
				'state' => $object['state']
			];

			if (!$this->lazyLoad) {
				$this->fetchArray($object['state'], $objects);
			}
		}

		if ($isRoot) {
			return ['root' => $id, 'objects' => $objects];
		}
	}

	/**
	 * Sends an HTTP request to the CouchDB server.
	 *
	 * @param  string $method
	 * @param  string $url
	 * @param  string $payload
	 * @return array
	 * @throws RuntimeException
	 */
	public function send($method, $url, $payload = NULL)
	{
		switch(strtolower($method)){
			case 'get':
				$this->transport->get($url);
				break;
			case 'post':
				$this->transport->post($url, $payload);
				break;
		}
		$this->transport->fetch();
		$buffers = $this->transport->getBuffers(function($doc){
			return explode("\r\n\r\n", $doc, 2);
		});
		$this->transport->flush();

		return ['headers' => $buffers[1][0], 'body' => $buffers[1][1]];
	}

	/**
	 * Sets the flag that controls whether or not debug messages are printed.
	 *
	 * @param  boolean $flag
	 * @throws InvalidArgumentException
	 */
	public function setDebug($flag)
	{
		// Bail out if a non-boolean was passed.
		if (!is_bool($flag)) {
			throw Util::getInvalidArgumentException(1, 'boolean');
		}

		$this->debug = $flag;
	}
}
