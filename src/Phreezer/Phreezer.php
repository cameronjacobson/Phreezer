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

use Phreezer\NonRecursiveSHA1;
use Phreezer\IdGenerator\UUID;
use Phreezer\Util;
use Phreezer\IdGenerator;
use Phreezer\HashGenerator;

class Phreezer
{
	/**
	 * @var boolean
	 */
	protected $autoload = TRUE;

	/**
	 * @var array
	 */
	protected $blacklist = array();

	/**
	 * @var Phreezer\IdGenerator
	 */
	protected $idGenerator;

	/**
	 * @var Phreezer\HashGenerator
	 */
	protected $hashGenerator;

	/**
	 * Constructor.
	 *
	 * @param  Phreezer\IdGenerator   $idGenerator
	 * @param  Phreezer\HashGenerator $hashGenerator
	 * @param  array                  $blacklist
	 * @param  boolean                $useAutoload
	 * @throws InvalidArgumentException
	 */
	public function __construct(IdGenerator $idGenerator = NULL, HashGenerator $hashGenerator = NULL, array $blacklist = array(), $useAutoload = TRUE)
	{
		// Use Phreezer\IdGenerator\UUID by default.
		if ($idGenerator === NULL) {
			$idGenerator = new UUID;
		}

		// Use Phreezer\HashGenerator\NonRecursiveSHA1 by default.
		if ($hashGenerator === NULL) {
			$hashGenerator = new NonRecursiveSHA1(
				$idGenerator
			);
		}

		$this->setIdGenerator($idGenerator);
		$this->setHashGenerator($hashGenerator);
		$this->setBlacklist($blacklist);
		$this->setUseAutoload($useAutoload);
	}

	public function freeze($object, array &$objects = array())
	{
		// Bail out if a non-object was passed.
		if (!is_object($object)) {
			throw Util::getInvalidArgumentException(1, 'object');
		}

		// The object has not been frozen before, generate a new UUID and
		// store it in the "special" __phreezer_uuid attribute.
		if (!isset($object->__phreezer_uuid)) {
			$object->__phreezer_uuid = $this->idGenerator->getId();
		}

		$isDirty = $this->isDirty($object, TRUE);
		$uuid = $object->__phreezer_uuid;

		if (!isset($objects[$uuid])) {
			$objects[$uuid] = array(
				'className' => get_class($object),
				'isDirty'   => $isDirty,
				'state'     => array()
			);

			// Iterate over the attributes of the object.
			foreach (Util::readAttributes($object) as $k => $v) {
				if ($k !== '__phreezer_uuid') {
					if (is_array($v)) {
						$this->freezeArray($v, $objects);
					}

					else if (is_object($v) &&
							 !in_array(get_class($v), $this->blacklist)) {
						// Freeze the aggregated object.
						$this->freeze($v, $objects);

						// Replace $v with the aggregated object's UUID.
						$v = '__phreezer_' .
							 $v->__phreezer_uuid;
					}

					else if (is_resource($v)) {
						$v = NULL;
					}

					// Store the attribute in the object's state array.
					$objects[$uuid]['state'][$k] = $v;
				}
			}
		}

		return array('root' => $uuid, 'objects' => $objects);
	}

	/**
	 * Freezes an array.
	 *
	 * @param array $array   The array that is to be frozen.
	 * @param array $objects Only used internally.
	 */
	protected function freezeArray(array &$array, array &$objects)
	{
		foreach ($array as &$value) {
			if (is_array($value)) {
				$this->freezeArray($value, $objects);
			}

			else if (is_object($value)) {
				$tmp   = $this->freeze($value, $objects);
				$value = '__phreezer_' . $tmp['root'];
				unset($tmp);
			}
		}
	}

	public function thaw(array $frozenObject, $root = NULL, array &$objects = array())
	{
		// Bail out if one of the required classes cannot be found.
		foreach ($frozenObject['objects'] as $object) {
			if (!class_exists($object['className'], $this->useAutoload)) {
				throw new RuntimeException(
					sprintf(
						'Class "%s" could not be found.', $object['className']
					)
				);
			}
		}

		// By default, we thaw the root object and (recursively)
		// its aggregated objects.
		if ($root === NULL) {
			$root = $frozenObject['root'];
		}

		// Thaw object (if it has not been thawed before).
		if (!isset($objects[$root])) {
			$className = $frozenObject['objects'][$root]['className'];
			$state = $frozenObject['objects'][$root]['state'];
			$reflector = new \ReflectionClass($className);
			$objects[$root] = $reflector->newInstanceWithoutConstructor();

			// Handle aggregated objects.
			$this->thawArray($state, $frozenObject, $objects);

			$reflector = new \ReflectionObject($objects[$root]);

			foreach ($state as $name => $value) {
				if (strpos($name, '__phreezer') !== 0) {
					if ($reflector->hasProperty($name)) {
						$attribute = $reflector->getProperty($name);
						$attribute->setAccessible(TRUE);
						$attribute->setValue($objects[$root], $value);
					} else {
						$objects[$root]->$name = $value;
					}
				}
			}

			// Store UUID.
			$objects[$root]->__phreezer_uuid = $root;

			// Store hash.
			if (isset($state['__phreezer_hash'])) {
				$objects[$root]->__phreezer_hash =
				$state['__phreezer_hash'];
			}
		}

		return $objects[$root];
	}

	/**
	 * Thaws an array.
	 *
	 * @param  array   $array        The array that is to be thawed.
	 * @param  array   $frozenObject The frozen object structure from which to thaw.
	 * @param  array   $objects      Only used internally.
	 */
	protected function thawArray(array &$array, array $frozenObject, array &$objects)
	{
		foreach ($array as &$value) {
			if (is_array($value)) {
				$this->thawArray($value, $frozenObject, $objects);
			}

			else if (is_string($value) &&
					 strpos($value, '__phreezer') === 0) {
				$aggregatedObjectId = str_replace(
					'__phreezer_', '', $value
				);

				if (isset($frozenObject['objects'][$aggregatedObjectId])) {
					$value = $this->thaw(
						$frozenObject, $aggregatedObjectId, $objects
					);
				}
			}
		}
	}

	/**
	 * Returns the Phreezer\IdGenerator implementation used
	 * to generate object identifiers.
	 *
	 * @return Phreezer\IdGenerator
	 */
	public function getIdGenerator()
	{
		return $this->idGenerator;
	}

	/**
	 * Sets the Phreezer\IdGenerator implementation used
	 * to generate object identifiers.
	 *
	 * @param Phreezer\IdGenerator $idGenerator
	 */
	public function setIdGenerator(IdGenerator $idGenerator)
	{
		$this->idGenerator = $idGenerator;
	}

	/**
	 * Returns the Phreezer\HashGenerator implementation used
	 * to generate hash objects.
	 *
	 * @return Phreezer\HashGenerator
	 */
	public function getHashGenerator()
	{
		return $this->hashGenerator;
	}

	/**
	 * Sets the Phreezer\HashGenerator implementation used
	 * to generate hash objects.
	 *
	 * @param Phreezer\HashGenerator $hashGenerator
	 */
	public function setHashGenerator(HashGenerator $hashGenerator)
	{
		$this->hashGenerator = $hashGenerator;
	}

	/**
	 * Returns the blacklist of class names for which aggregates objects are
	 * not frozen.
	 *
	 * @return array
	 */
	public function getBlacklist()
	{
		return $this->blacklist;
	}

	/**
	 * Sets the blacklist of class names for which aggregates objects are
	 * not frozen.
	 *
	 * @param  array $blacklist
	 * @throws InvalidArgumentException
	 */
	public function setBlacklist(array $blacklist)
	{
		$this->blacklist = $blacklist;
	}

	/**
	 * Returns the flag that controls whether or not __autoload()
	 * should be invoked.
	 *
	 * @return boolean
	 */
	public function getUseAutoload()
	{
		return $this->useAutoload;
	}

	/**
	 * Sets the flag that controls whether or not __autoload()
	 * should be invoked.
	 *
	 * @param  boolean $flag
	 * @throws InvalidArgumentException
	 */
	public function setUseAutoload($flag)
	{
		// Bail out if a non-boolean was passed.
		if (!is_bool($flag)) {
			throw Util::getInvalidArgumentException(1, 'boolean');
		}

		$this->useAutoload = $flag;
	}

	/**
	 * Checks whether an object is dirty, ie. if its SHA1 hash is still valid.
	 *
	 * Returns TRUE when the object's __phreezer_hash attribute is no
	 * longer valid or does not exist.
	 * Returns FALSE when the object's __phreezer_hash attribute is
	 * still valid.
	 *
	 * @param  object  $object The object that is to be checked.
	 * @param  boolean $rehash Whether or not to rehash dirty objects.
	 * @return boolean
	 * @throws InvalidArgumentException
	 */
	public function isDirty($object, $rehash = FALSE)
	{
		// Bail out if a non-object was passed.
		if (!is_object($object)) {
			throw Util::getInvalidArgumentException(1, 'object');
		}

		// Bail out if a non-boolean was passed.
		if (!is_bool($rehash)) {
			throw Util::getInvalidArgumentException(2, 'boolean');
		}

		$isDirty = TRUE;
		$hash = $this->hashGenerator->getHash($object);

		if (isset($object->__phreezer_hash) &&
			$object->__phreezer_hash == $hash) {
			$isDirty = FALSE;
		}

		if ($isDirty && $rehash) {
			$object->__phreezer_hash = $hash;
		}

		return $isDirty;
	}
}
