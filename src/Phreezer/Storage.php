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


namespace Phreezer;

use Phreezer\Phreezer;
use Phreezer\Cache;
use Phreezer\LazyProxy;
use Phreezer\Util;

abstract class Storage
{
	/**
	 * @var Phreezer
	 */
	protected $freezer;

	/**
	 * @var boolean
	 */
	protected $lazyLoad = FALSE;

	/**
	 * Constructor.
	 *
	 * @param  boolean         $useLazyLoad  Flag that controls whether objects are fetched using lazy load or not
	 * @param  Phreezer        $freezer      Phreezer instance to be used
	 */
	public function __construct($useLazyLoad = FALSE, Phreezer $freezer = NULL)
	{
		if ($freezer === NULL) {
			$freezer = new Phreezer;
		}

		$this->freezer = $freezer;

		$this->setUseLazyLoad($useLazyLoad);
	}

	/**
	 * Sets the flag that controls whether objects are fetched using lazy load.
	 *
	 * @param  boolean $flag
	 * @throws InvalidArgumentException
	 */
	public function setUseLazyLoad($flag)
	{
		// Bail out if a non-boolean was passed.
		if (!is_bool($flag)) {
			throw Util::getInvalidArgumentException(1, 'boolean');
		}

		$this->lazyLoad = $flag;
	}

	/**
	 * Freezes an object and stores it in the object storage.
	 *
	 * @param  object $object The object that is to be stored.
	 * @return string
	 */
	public function store($object)
	{
		// Bail out if a non-object was passed.
		if (!is_object($object)) {
			throw Util::getInvalidArgumentException(1, 'object');
		}

		$this->doStore($this->freezer->freeze($object));

		return $object->__phreezer_uuid;
	}

	/**
	 * Fetches a frozen object from the object storage and thaws it.
	 *
	 * @param  string $id The ID of the object that is to be fetched.
	 * @return object
	 */
	public function fetch($id)
	{
		// Bail out if a non-string was passed.
		if (!is_string($id)) {
			throw Util::getInvalidArgumentException(1, 'string');
		}

		// Try to retrieve object from the object cache.
		$object = Cache::get($id);

		if (!$object) {
			// Retrieve object from the object storage.
			$frozenObject = $this->doFetch($id);
			$this->fetchArray($frozenObject['objects'][$id]['state']);
			$object = $this->freezer->thaw($frozenObject);

			// Put object into the object cache.
			Cache::put($id, $object);
		}

		return $object;
	}

	/**
	 * Fetches a frozen array from the object storage and thaws it.
	 *
	 * @param array $array
	 * @param array $objects
	 */
	protected function fetchArray(array &$array, array &$objects = array())
	{
		foreach ($array as &$value) {
			if (is_array($value)) {
				$this->fetchArray($value, $objects);
			}

			else if (is_string($value) &&
					 strpos($value, '__phreezer_') === 0) {
				$uuid = str_replace('__phreezer_', '', $value);

				if (!$this->lazyLoad) {
					$this->doFetch($uuid, $objects);
				} else {
					$value = new LazyProxy($this, $uuid);
				}
			}
		}
	}

	/**
	 * Freezes an object and stores it in the object storage.
	 *
	 * @param array $frozenObject
	 */
	abstract protected function doStore(array $frozenObject);

	/**
	 * Fetches a frozen object from the object storage and thaws it.
	 *
	 * @param  string $id The ID of the object that is to be fetched.
	 * @return object
	 */
	abstract protected function doFetch($id);
}
