<?php
/**
 * PDO-based database layer
 *
 * @see http://www.php.net/manual/en/book.pdo.php
 *
 * @package Cotonti
 * @version 0.9.0
 * @author Trustmaster
 * @copyright (c) Cotonti Team 2010
 * @license BSD
 */

defined('COT_CODE') or die('Wrong URL');

/**
 * Cotonti Database Connection class.
 * A compact extension to standard PHP PDO class with slight Cotonti-specific needs,
 * handy functions and query builder.
 *
 * @see http://www.php.net/manual/en/class.pdo.php
 *
 * @property-read int $affectedRows Number of rows affected by the most recent query
 * @property-read int $count Total query count
 * @property-read int $errno Most recent error code
 * @property-read int $error Most recent error message
 * @property-read int $timeCount Total query execution time
 */
class CotDB extends PDO {
	/**
	 * @var int Number of rows affected by the most recent query
	 */
	private $_affected_rows = 0;
	/**
	 * @var int Total query count
	 */
	private $_count = 0;
	/**
	 * @var int Total query execution time
	 */
	private $_tcount = 0;
	/**
	 * @var string Timer start microtime
	 */
	private $_xtime = 0;

	/**
	 * Creates a PDO instance to represent a connection to the requested database.
	 *
	 * @param string $dsn The Data Source Name, or DSN, contains the information required to connect to the database.
	 * @param string $username The user name for the DSN string.
	 * @param string $passwd The password for the DSN string.
	 * @param array $options A key=>value array of driver-specific connection options.
	 * @see http://www.php.net/manual/en/pdo.construct.php
	 */
	public function  __construct($dsn, $username, $passwd, $options = array())
	{
		global $cfg;
		if (!empty($cfg['mysqlcharset']))
		{
			$collation_query = "SET NAMES '{$cfg['mysqlcharset']}'";
			if (!empty($cfg['mysqlcollate']) )
			{
				$collation_query .= " COLLATE '{$cfg['mysqlcollate']}'";
			}
			$options[PDO::MYSQL_ATTR_INIT_COMMAND] = $collation_query;
		}
		parent::__construct($dsn, $username, $passwd, $options);
	}

	/**
	 * Provides access to properties
	 * @param string $name Property name
	 * @return mixed Property value
	 */
	public function __get($name)
	{
		switch ($name)
		{
			case 'affectedRows':
				return $this->_affected_rows;
				break;
			case 'count':
				return $this->_count;
				break;
			case 'errno':
				$info = $this->errorInfo();
				return $info[1];
				break;
			case 'error':
				$info = $this->errorInfo();
				return $info[2];
				break;
			case 'timeCount':
				return $this->_tcount;
				break;
			default:
				return null;
		}
	}

	/**
	 * Binds parameters to a statement
	 *
	 * @param PDOStatement $statement PDO statement
	 * @param array $parameters Array of parameters, numeric or associative
	 */
	private function _bindParams($statement, $parameters)
	{
		$is_numeric = is_int(key($parameters));
		foreach ($parameters as $key => $val)
		{
			$type = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
			$is_numeric ? $statement->bindParam($key + 1, $val, $type) : $statement->bindParam($key, $val, $type);
		}
	}

	/**
	 * Starts query execution timer
	 */
	private function _startTimer()
	{
		$this->_count++;
		$this->_xtime = microtime();
	}

	/**
	 * Stops query execution timer
	 */
	private function _stopTimer($query)
	{
		global $cfg, $usr, $sys;
		$ytime = microtime();
		$xtime = explode(' ',$xtime);
		$ytime = explode(' ',$ytime);
		$this->_tcount += $ytime[1] + $ytime[0] - $xtime[1] - $xtime[0];
		if ($cfg['devmode'] && $usr['isadmin'])
		{
			$sys['devmode']['queries'][] = array ($this->_count, $ytime[1] + $ytime[0] - $xtime[1] - $xtime[0], $query);
			$sys['devmode']['timeline'][] = $xtime[1] + $xtime[0] - $sys['starttime'];
		}
	}

	/**
	 * Returns total number of records contained in a table
	 * @param string $table_name Table name
	 * @return int
	 */
	public function countRows($table_name)
	{
		return $this->query("SELECT COUNT(*) FROM `$table_name`")->fetchColumn();
	}

	/**
	 * Performs simple SQL DELETE query and returns number of removed items.
	 *
	 * @param string $table_name Table name
	 * @param string $condition Body of WHERE clause
	 * @param array $parameters Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
	 * @return int Number of records removed on success or FALSE on error
	 */
	public function delete($table_name, $condition = '', $parameters = array())
	{
		$query = empty($condition) ? "DELETE FROM `$table_name`" : "DELETE FROM `$table_name` WHERE $condition";
		$this->_startTimer();
		if (count($parameters) > 0)
		{
			$stmt = $this->prepare($query);
			$this->_bindParams($stmt, $parameters);
			$res = $stmt->execute() ? $stmt->rowCount() : false;
		}
		else
		{
			$res = $this->exec($query);
		}
		$this->_stopTimer($query);
		return $res;
	}

	/**
	 * Performs SQL INSERT on simple data array. Array keys must match table keys, optionally you can specify
	 * key prefix as third parameter. Strings get quoted and escaped automatically.
	 * Ints and floats must be typecasted.
	 * You can use special values in the array:
	 * - PHP NULL => SQL NULL
	 * - 'NOW()' => SQL NOW()
	 * Performs single row INSERT if $data is an associative array,
	 * performs multi-row INSERT if $data is a 2D array (numeric => assoc)
	 *
	 * @param string $table_name Table name
	 * @param array $data Associative or 2D array containing data for insertion.
	 * @param string $prefix Optional key prefix, e.g. 'page_' prefix will result into 'page_name' key.
	 * @return int The number of affected records
	 */
	public function insert($table_name, $data, $prefix = '')
	{
		if (!is_array($data))
		{
			return 0;
		}
		$keys = '';
		$vals = '';
		// Check the array type
		$arr_keys = array_keys($data);
		$multiline = is_numeric($arr_keys[0]);
		// Build the query
		if ($multiline)
		{
			$rowset = &$data;
		}
		else
		{
			$rowset = array($data);
		}
		$keys_built = false;
		$cnt = count($rowset);
		for ($i = 0; $i < $cnt; $i++)
		{
			$vals .= ($i > 0) ? ',(' : '(';
			$j = 0;
			if (is_array($rowset[$i]))
			{
				foreach ($rowset[$i] as $key => $val)
				{
					if (is_null($val))
					{
						continue;
					}
					if ($j > 0) $vals .= ',';
					if (!$keys_built)
					{
						if ($j > 0) $keys .= ',';
						$keys .= "`{$prefix}$key`";
					}
					if ($val === 'NOW()')
					{
						$vals .= 'NOW()';
					}
					elseif (is_int($val) || is_float($val))
					{
						$vals .= $val;
					}
					else
					{
						$vals .= $this->quote($val);
					}
					$j++;
				}
			}
			$vals .= ')';
			$keys_built = true;
		}
		if (!empty($keys) && !empty($vals))
		{
			$query = "INSERT INTO `$table_name` ($keys) VALUES $vals";
			$this->_startTimer();
			$res = $this->query($query);
			$this->_stopTimer($query);
			return $res->rowCount();
		}
		return 0;
	}

	public function prep($str)
	{
		return preg_replace("#^'(.*)'\$#", '$1', $this->quote($str));
	}

	/**
	 * Runs an SQL script containing multiple queries.
	 *
	 * @param string $script SQL script body, containing formatted queries separated by semicolons and newlines
	 * @param resource $conn Custom connection handle
	 * @return string Error message if an error occurs or empty string on success
	 */
	public function runScript($script)
	{
		$error = '';
		// Remove comments
		$script = preg_replace('#^/\*.*?\*/#m', '', $script);
		$script = preg_replace('#^--.*?$#m', '', $script);
		// Run queries separated by ; at the end of line
		$queries =  preg_split('#;\r?\n#', $script);
		foreach ($queries as $query)
		{
			$query = trim($query);
			if (!empty($query))
			{
				if ($db_x != 'cot_')
				{
					$query = str_replace('`cot_', '`'.$db_x, $query);
				}
				$result = $this->query($query);
				if (!$result)
				{
					return $this->error . '<br />' . htmlspecialchars($query) . '<hr />';
				}
			}
		}
		return '';
	}

	/**
	 * 1) If called with one parameter:
	 * Works like PDO::query()
	 * Executes an SQL statement in a single function call, returning the result set (if any) returned by the statement as a PDOStatement object.
	 * 2) If called with second parameter as array of input parameter bindings:
	 * Works like PDO::prepare()->execute()
	 * Prepares an SQL statement and executes it.
	 * @see http://www.php.net/manual/en/pdo.query.php
	 * @see http://www.php.net/manual/en/pdo.prepare.php
	 * @param string $query The SQL statement to prepare and execute.
	 * @param array $parameters An array of values to be binded as input parameters to the query. PHP int parameters will beconsidered as PDO::PARAM_INT, others as PDO::PARAM_STR.
	 * @return PDOStatement
	 */
	public function query($query, $parameters = array())
	{
		$this->_startTimer();
		if (count($parameters) > 0)
		{
			
			$result = parent::prepare($query);
			$this->_bindParams($result, $parameters);
			$result->execute();
		}
		else
		{
			$result = parent::query($query) OR cot_diefatal('SQL error : '.$this->error);
		}
		$this->_stopTimer($query);
		// In Cotonti we use PDO::FETCH_ASSOC by default to save memory
		$result->setFetchMode(PDO::FETCH_ASSOC);
		$this->_affected_rows = $result->rowCount();
		return $result;
	}

	/**
	 * Performs SQL UPDATE with simple data array. Array keys must match table keys, optionally you can specify
	 * key prefix as fourth parameter. Strings get quoted and escaped automatically.
	 * Ints and floats must be typecasted.
	 * You can use special values in the array:
	 * - PHP NULL => SQL NULL
	 * - 'NOW()' => SQL NOW()
	 *
	 * @param string $table_name Table name
	 * @param array $data Associative array containing data for update
	 * @param string $condition Body of SQL WHERE clause
	 * @param array $parameters Array of statement input parameters, see http://www.php.net/manual/en/pdostatement.execute.php
	 * @param bool $update_null Nullify cells which have null values in the array. By default they are skipped
	 * @return int The number of affected records or FALSE on error
	 */
	public function update($table_name, $data, $condition, $parameters = array(), $update_null = false)
	{
		if(!is_array($data))
		{
			return 0;
		}
		$upd = '';
		$condition = empty($condition) ? '' : 'WHERE '.$condition;
		foreach ($data as $key => $val)
		{
			if (is_null($val) && !$update_null)
			{
				continue;
			}
			$upd .= "`$key`=";
			if (is_null($val))
			{
				$upd .= 'NULL,';
			}
			elseif ($val === 'NOW()')
			{
				$upd .= 'NOW(),';
			}
			elseif (is_int($val) || is_float($val))
			{
				$upd .= $val.',';
			}
			else
			{
				$upd .= $this->quote($val) . ',';
			}

		}
		if (!empty($upd))
		{
			$upd = mb_substr($upd, 0, -1);
			$query = "UPDATE `$table_name` SET $upd $condition";
			$this->_startTimer();
			if (count($parameters) > 0)
			{
				$stmt = $this->prepare($query);
				$this->_bindParams($stmt, $parameters);
				$res = $stmt->execute() ? $stmt->rowCount() : false;
			}
			else
			{
				$res = $this->exec($query);
			}
			$this->_stopTimer($query);
			return $res;
		}
		return 0;
	}
}

?>