<?php
namespace Mouf\Database\TDBM;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Statement;
use Mouf\Database\MagicQuery;

/*
 Copyright (C) 2006-2015 David Négrier - THE CODING MACHINE

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */


/**
 * Iterator used to retrieve results.
 *
 */
class ResultIterator implements \Iterator, \Countable {

	/**
	 *
	 * @var Statement
	 */
	protected $statement;
	
	protected $fetchStarted = false;
	private $objectStorage;
	private $className;


	private $tdbmService;
	private $magicSql;
	private $magicSqlCount;
	private $parameters;
	private $limit;
	private $offset;
	private $columnDescriptors;
	private $magicQuery;

	/**
	 * The key of the current retrieved object.
	 *
	 * @var int
	 */
	protected $key = -1;

	protected $current = null;

	private $databasePlatform;

	private $totalCount;
	
	public function __construct($magicSql, $magicSqlCount, array $parameters, $limit, $offset, array $columnDescriptors, $objectStorage, $className, TDBMService $tdbmService, MagicQuery $magicQuery)
	{
		$this->magicSql = $magicSql;
		$this->magicSqlCount = $magicSqlCount;
		$this->objectStorage = $objectStorage;
		$this->className = $className;
		$this->tdbmService = $tdbmService;
		$this->parameters = $parameters;
		$this->limit = $limit;
		$this->offset = $offset;
		$this->columnDescriptors = $columnDescriptors;
		$this->magicQuery = $magicQuery;
		$this->databasePlatform = $this->tdbmService->getConnection()->getDatabasePlatform();
	}

	protected function executeQuery() {
		$sql = $this->magicQuery->build($this->magicSql, $this->parameters);
		$sql = $this->tdbmService->getConnection()->getDatabasePlatform()->modifyLimitQuery($sql, $this->limit, $this->offset);

		$this->statement = $this->tdbmService->getConnection()->executeQuery($sql, $this->parameters);

		$this->fetchStarted = true;
	}

	protected function executeCountQuery() {
		$sql = $this->magicQuery->build($this->magicSqlCount, $this->parameters);
		$sql = $this->tdbmService->getConnection()->getDatabasePlatform()->modifyLimitQuery($sql, $this->limit, $this->offset);

		$this->totalCount = $this->tdbmService->getConnection()->fetchColumn($sql, $this->parameters);
	}

	/**
	 * Counts found records (this is the number of records fetched, taking into account the LIMIT and OFFSET settings)
	 * @return int
	 */
	public function count()
	{
		if (!$this->fetchStarted) {
			$this->executeQuery();
		}
		return $this->statement->rowCount();
	}

	/**
	 * Counts all possible records (ignoring the LIMIT parameter)
	 */
	public function fullCount()
	{
		if ($this->totalCount === null) {
			$this->executeCountQuery();
		}
		return $this->totalCount;
	}

	/**
	 * Fetches record at current cursor.
	 * @return AbstractTDBMObject|null
	 */
	public function current()
	{
		return $this->current;
	}

	/**
	 * Returns the current result's key
	 * @return int
	 */
	public function key()
	{
		return $this->key;
	}

	/**
	 * Advances the cursor to the next result.
	 * Casts the database result into one (or several) beans.
	 */
	public function next()
	{
		$row = $this->statement->fetch(\PDO::FETCH_NUM);
		if ($row) {

			// array<tablegroup, array<table, array<column, value>>>
			$beansData = [];
			foreach ($row as $i => $value) {
				$columnDescriptor = $this->columnDescriptors[$i];
				// Let's cast the value according to its type
				$value = $columnDescriptor['type']->convertToPHPValue($value, $this->databasePlatform);

				$beansData[$columnDescriptor['tableGroup']][$columnDescriptor['table']][$columnDescriptor['column']] = $value;
			}

			$firstBean = true;
			foreach ($beansData as $beanData) {

				// Let's find the bean class name associated to the bean.

				list($actualClassName, $mainBeanTableName) = $this->tdbmService->_getClassNameFromBeanData($beanData);


				if ($this->className !== null) {
					$actualClassName = $this->className;
				}

				// Must we create the bean? Let's see in the cache if we have a mapping DbRow?
				// Let's get the first object mapping a row:
				// We do this loop only for the first table

				$primaryKeys = $this->tdbmService->_getPrimaryKeysFromObjectData($mainBeanTableName, $beanData[$mainBeanTableName]);
				$hash = $this->tdbmService->getObjectHash($primaryKeys);

				if ($this->objectStorage->has($mainBeanTableName, $hash)) {
					$dbRow = $this->objectStorage->get($mainBeanTableName, $hash);
					$bean = $dbRow->getTDBMObject();
				} else {
					// Let's construct the bean
					if (!isset($reflectionClassCache[$actualClassName])) {
						$reflectionClassCache[$actualClassName] = new \ReflectionClass($actualClassName);
					}
					// Let's bypass the constructor when creating the bean!
					$bean = $reflectionClassCache[$actualClassName]->newInstance();
					$bean->_constructFromData($beanData, $this->tdbmService);
				}

				// The first bean is the one containing the main table.
				if ($firstBean) {
					$firstBean = false;
					$this->current = $bean;
				}
			}

			$this->key++;
		} else {
			$this->current = null;
		}
	}
	
	/**
	 * Moves the cursor to the beginning of the result set
	 */
	public function rewind()
	{
		$this->executeQuery();
		$this->key = -1;
		$this->next();
	}
	/**
	 * Checks if the cursor is reading a valid result.
	 *
	 * @return boolean
	 */
	public function valid()
	{
		return $this->current !== null;
	}

	/**
	 * Sets the maximum number of records to be returned.
	 * Note: this will reset the cursor.
	 *
	 * @param int $limit
	 * @return $this
	 */
	public function setLimit($limit) {
		$this->limit = $limit;
		$this->reset(false);
		return $this;
	}

	/**
	 * Sets the start offset for returned records.
	 * Note: this will reset the cursor.
	 *
	 * @param int $offset
	 * @return $this
	 */
	public function setOffset($offset) {
		$this->offset = $offset;
		$this->reset(false);
		return $this;
	}

	/**
	 * Sets SQL parameters.
	 * Note: this will reset the cursor.
	 *
	 * @param array $parameters
	 * @return $this
	 */
	public function setParameters(array $parameters) {
		$this->parameters = $parameters;
		$this->reset(true);
		return $this;
	}

	/**
	 * Resets the query.
	 * Stops any running query. Does not run the query again.
	 *
	 * @param bool $resetTotalCount
	 */
	protected function reset($resetTotalCount = false) {
		if ($this->statement) {
			$this->statement->closeCursor();
		}
		$this->current = null;
		$this->key = -1;
		$this->fetchStarted = false;
		if ($resetTotalCount) {
			$this->totalCount = null;
		}

	}

	/**
	 * Casts the result set to a PHP array.
	 *
	 * @return array
	 */
	public function toArray() {
		return iterator_to_array($this);
	}

	/**
	 * Returns a new iterator mapping any call using the $callable function.
	 *
	 * @param callable $callable
	 * @return MapIterator
	 */
	public function map(callable $callable) {
		return new MapIterator($this, $callable);
	}

	// TODO: add support for map() method!

	/**
	 * Fetches all records (this could impact into your site performance) and rewinds the cursor
	 * @param boolean $asRecords Bind into record class?
	 * @return array[Record_PDO]|array[array] Array of records or arrays (depends on $asRecords)
	 */
	/*public function getAll($asRecords = true)
	{
		$all = array();
		$this->rewind();
		foreach ($this->pdoStatement as $id => $doc) {
			if ($asRecords)
				$all[$id] = $this->cast($doc);
			else
				$all[$id] = $doc;
		}
		return $all;
	}*/
	/**
	 * @return PDOStatement
	 */
	/*public function getPDOStatement()
	{
		return $this->pdoStatement;
	}*/
}