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

class CouchDB extends Storage
{
	public $database;
	public $scheme;
	public $host;
	public $port;

	/**
	 * @var array
	 */
	protected $revisions = [];

	/**
	 * @var boolean
	 */
	protected $debug = FALSE;

	/**
	 * Constructor.
	 *
	 * @param  string            $database      Name of the database to be used
	 * @param  Phreezer          $freezer       Phreezer instance to be used
	 * @param  boolean           $useLazyLoad   Flag that controls whether objects are fetched using lazy load or not
	 * @param  string            $host          Hostname of the CouchDB instance to be used
	 * @param  int               $port          Port of the CouchDB instance to be used
	 * @throws InvalidArgumentException
	 */
	public function __construct(array $options = [])
	{
		$options['scheme'] = @$options['scheme'] ?: 'http';
		$options['host'] = @$options['host'] ?: 'localhost';
		$options['port'] = @$options['port'] ?: 5984;
		$options['user'] = @$options['user'] ?: null;
		$options['pass'] = @$options['pass'] ?: null;
		$options['lazyproxy'] = @$options['lazyproxy'] ?: FALSE;
		$options['freezer'] = @$options['freezer'] ?: null;
		parent::__construct(@$options['lazyproxy'], @$options['freezer']);

		if (!is_string($options['database'])) {
			throw Util::getInvalidArgumentException(1, 'string');
		}

		if (!is_string($options['host'])) {
			throw Util::getInvalidArgumentException(4, 'string');
		}

		if (!is_numeric($options['port'])) {
			throw Util::getInvalidArgumentException(5, 'integer');
		}

		$this->user = $options['user'];
		$this->pass = $options['pass'];

		$this->database = $options['database'];
		$this->host = $options['host'];
		$this->port = $options['port'];
		$this->scheme = $options['scheme'];

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

			if (strpos($response['headers'], 'HTTP/1.1 201 Created') !== 0) {
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

			if (strpos($response['headers'], 'HTTP/1.1 200 OK') !== 0) {
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
		$socket = @fsockopen($this->host, $this->port, $errno, $errstr);

		if (!$socket) {
			throw new \RuntimeException($errno . ': ' . $errstr);
		}

		$request = sprintf(
			"%s %s HTTP/1.1\r\nHost: %s:%d\r\n%sContent-Type: application/json\r\nConnection: close\r\n",
			$method,
			$url,
			$this->host,
			$this->port,
			empty($this->user) ? '' : 'Authorization: Basic '.base64_encode($this->user.':'.$this->pass)."\r\n"
		);

		if ($payload !== NULL) {
			$request .= 'Content-Length: ' . strlen($payload) . "\r\n\r\n" .
						$payload;
		}

		$request .= "\r\n";

		// @codeCoverageIgnoreStart
		if ($this->debug) {
			print $request;
		}
		// @codeCoverageIgnoreEnd

		fwrite($socket, $request);

		$buffer = '';

		while (!feof($socket)) {
			$buffer .= fgets($socket);
		}

		list($headers, $body) = explode("\r\n\r\n", $buffer);

		return ['headers' => $headers, 'body' => $body];
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
