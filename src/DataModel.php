<?php

namespace ItvisionSy\SimpleORM;

use Exception;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;

/**
 * Simple ORM base class.
 * 
 * @package ItvisionSy\SimpleORM
 * @abstract
 * @author     Alex Joyce <im@alex-joyce.com>
 * @author     Muhannad Shelleh <muhannad.shelleh@live.com>
 */
abstract class DataModel {

    private static
            $conn;
    protected static
            $tableName,
            $pkColumn,
            $createdAtColumn,
            $updatedAtColumn,
            $readOnly = false,
            $defaultValues;
    protected
            $reflectionObject,
            $loadMethod,
            $loadData,
            $modifiedFields = array(),
            $isNew = false,
            $parentObject,
            $ignoreKeyOnUpdate = true,
            $ignoreKeyOnInsert = true;

    /**
     * ER Fine Tuning
     */
    const
            FILTER_IN_PREFIX = 'filterIn',
            FILTER_OUT_PREFIX = 'filterOut';

    /**
     * Loading options.
     */
    const
            LOAD_BY_PK = 1,
            LOAD_BY_ARRAY = 2,
            LOAD_NEW = 3,
            LOAD_EMPTY = 4;

    /**
     * Fetch options:
     * FIELD: only first field of first record
     * ONE: Fetch & return one record only.
     * MANY: Fetch multiple records.
     * NONE: Don't fetch.
     */
    const
            FETCH_ONE = 1,
            FETCH_MANY = 2,
            FETCH_NONE = 3,
            FETCH_FIELD = 4;

    /**
     * Constructor.
     * 
     * @access public
     * @param mixed $data
     * @param integer $method
     * @return void
     */
    public function __construct($data = null, $method = self::LOAD_EMPTY) {
        // store raw data
        $this->loadData = $data;
        $this->loadMethod = $method;

        // load our data
        switch ($method) {
            case self::LOAD_BY_PK:
                $this->loadByPK();
                break;

            case self::LOAD_BY_ARRAY:
                $this->loadByArray();
                break;

            case self::LOAD_NEW:
                $this->hydrateEmpty();
                $this->loadByArray();
                $this->insert();
                break;

            case self::LOAD_EMPTY:
                $this->hydrateEmpty();
                break;
        }

        $this->initialise();
    }

    /**
     * Give the class a connection to play with.
     * 
     * @access public
     * @static
     * @param PDO $conn PDO connection instance.
     * @param string $database
     * @return void
     */
    public static function useConnection(PDO $conn) {
        self::$conn = $conn;
    }

    public static function createConnection($host, $username, $password, $database, array $options = [], $port = 3306, $charset = null) {
        $dsn = "mysql:dbname={$database};host={$host};port={$port}";
        self::$conn = new PDO($dsn, $username, $password, $options);
    }

    /**
     * Get our connection instance.
     * 
     * @access public
     * @static
     * @return PDO
     */
    public static function getConnection() {
        return self::$conn;
    }

    /**
     * Get load method.
     *
     * @access public
     * @return integer
     */
    public function getLoadMethod() {
        return $this->loadMethod;
    }

    /**
     * Get load data (raw).
     *
     * @access public
     * @return array
     */
    public function getLoadData() {
        return $this->loadData;
    }

    /**
     * Load ER by Primary Key
     * 
     * @access protected
     * @return void
     */
    protected function loadByPK() {
        // populate PK
        $this->{self::getTablePk()} = $this->loadData;

        // load data
        $this->hydrateFromDatabase();
    }

    /**
     * Load ER by array hydration.
     * 
     * @access protected
     * @return void
     */
    protected function loadByArray() {
        // set our data
        foreach ($this->loadData AS $key => $value) {
            $this->{$key} = $value;
        }
        // extract columns
        $this->executeOutputFilters();
    }

    /**
     * Hydrate the object with null or default values.
     * Fetches column names using DESCRIBE.
     * 
     * @access protected
     * @return void
     */
    protected function hydrateEmpty() {

        $defaults = static::$defaultValues ? static::$defaultValues : [];

        foreach ($this->getColumnNames() AS $field) {
            $this->{$field} = array_key_exists($field, $defaults) ? $defaults[$field] : null;
        }

        // mark object as new
        $this->isNew = true;
    }

    /**
     * Fetch the data from the database.
     * 
     * @access protected
     * @throws Exception If the record is not found.
     * @return void
     */
    protected function hydrateFromDatabase() {
        $sql = sprintf("SELECT * FROM `%s` WHERE `%s` = '%s';", self::getTableName(), self::getTablePk(), $this->id());
        $result = self::getConnection()->query($sql);

        if (!$result->rowCount()) {
            throw new Exception(sprintf("%s record not found in database. (PK: %s)", get_called_class(), $this->id()), 2);
        }

        foreach ($result->fetch(PDO::FETCH_ASSOC) AS $key => $value) {
            $this->{$key} = $value;
        }

        unset($result);

        // extract columns
        $this->executeOutputFilters();
    }

    /**
     * Get the table name for this ER class.
     * 
     * @access public
     * @static
     * @return string
     */
    public static function getTableName() {
        return @static::$tableName ? static::$tableName : strtolower(basename(str_replace("\\", DIRECTORY_SEPARATOR, get_called_class())));
    }

    /**
     * Get the PK field name for this ER class.
     * 
     * @access public
     * @static
     * @return string
     */
    public static function getTablePk() {
        return @static::$pkColumn ? static::$pkColumn : 'id';
    }

    /**
     * Return the PK for this record.
     * 
     * @access public
     * @return integer
     */
    public function id() {
        return $this->{self::getTablePk()};
    }

    /**
     * Check if the current record has just been created in this instance.
     * 
     * @access public
     * @return boolean
     */
    public function isNew() {
        return $this->isNew;
    }

    /**
     * Executed just before any new records are created.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function preInsert(array &$data = []) {
        if (static::$createdAtColumn) {
            $data[static::$createdAtColumn] = RawSQL::make('CURRENT_TIMESTAMP');
        }
        if (static::$updatedAtColumn) {
            $data[static::$updatedAtColumn] = RawSQL::make('CURRENT_TIMESTAMP');
        }
    }

    /**
     * Executed just after any new records are created.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function postInsert() {
        
    }

    /**
     * Executed just before any new records are created.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function preDelete() {
        
    }

    /**
     * Executed just after any new records are created.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function postDelete() {
        
    }

    /**
     * Executed just before any new records are created.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function preUpdate(array &$data = []) {
        if (static::$updatedAtColumn) {
            $data[static::$updatedAtColumn] = RawSQL::make('CURRENT_TIMESTAMP');
        }
    }

    /**
     * Executed just after any new records are created.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function postUpdate() {
        
    }

    /**
     * Executed just after the record has loaded.
     * Place holder for sub-classes.
     * 
     * @access public
     * @return void
     */
    public function initialise() {
        
    }

    /**
     * Execute these filters when loading data from the database.
     * 
     * @access protected
     * @return void
     */
    protected function executeOutputFilters() {
        $r = new ReflectionClass(get_class($this));

        foreach ($r->getMethods() AS $method) {
            if (substr($method->name, 0, strlen(self::FILTER_OUT_PREFIX)) == self::FILTER_OUT_PREFIX) {
                $this->{$method->name}();
            }
        }
    }

    /**
     * Execute these filters when saving data to the database.
     * 
     * @access protected
     * @return void
     */
    protected function executeInputFilters($array) {
        $r = new ReflectionClass(get_class($this));

        foreach ($r->getMethods() AS $method) {
            if (substr($method->name, 0, strlen(self::FILTER_IN_PREFIX)) == self::FILTER_IN_PREFIX) {
                $array = $this->{$method->name}($array);
            }
        }

        return $array;
    }

    /**
     * Save (insert/update) to the database.
     *
     * @access public
     * @return void
     */
    public function save() {
        if ($this->isNew()) {
            $this->insert();
        } else {
            $this->update();
        }
    }

    /**
     * Insert the record.
     *
     * @access protected
     * @throws Exception
     * @return void
     */
    protected function insert() {

        if (static::$readOnly) {
            throw new Exception("Cannot write to READ ONLY tables.");
        }

        $array = $this->get();

        $valid = $this->validateDataBeforeSave($array);
        if ($valid !== true) {
            throw new Exception(is_string($valid) ? $valid : 'Validation failed before saving');
        }

        // run pre inserts
        if ($this->preInsert($array) === false) {
            return;
        }

        // input filters
        $array = $this->executeInputFilters($array);

        // remove data not relevant
        $array = array_intersect_key($array, array_flip($this->getColumnNames()));

        // to PK or not to PK
        if ($this->ignoreKeyOnInsert === true) {
            unset($array[self::getTablePk()]);
        }

        // compile statement
        $fieldNames = $fieldMarkers = $types = $values = array();

        foreach ($array AS $key => $value) {
            $fieldNames[] = sprintf('`%s`', $key);
            if (is_object($value) && $value instanceof RawSQL) {
                $fieldMarkers[] = (string) $value;
            } else {
                $fieldMarkers[] = '?';
                $types[] = $this->parseValueType($value);
                $values[] = &$array[$key];
            }
        }

        // build sql statement
        $sql = sprintf("INSERT INTO `%s` (%s) VALUES (%s)", self::getTableName(), implode(', ', $fieldNames), implode(', ', $fieldMarkers));

        // prepare, bind & execute
        /* @var $stmt PDOStatement */
        $stmt = self::getConnection()->prepare($sql);

        if (!$stmt) {
            throw new Exception(self::getConnection()->errorCode() . "\n\n" . $sql);
        }

        if (!$stmt->execute(array_values($values))) {
            throw new Exception($stmt->errorCode() . "\n\n" . $sql);
        }

        $lastId = static::getConnection()->lastInsertId();

        // set our PK (if exists)
        if ($lastId) {
            $this->{self::getTablePk()} = $lastId;
        }

        // mark as old
        $this->isNew = false;

        // hydrate
        $this->hydrateFromDatabase($lastId);

        // run post inserts
        $this->postInsert();
    }

    /**
     * Update the record.
     * 
     * @access public
     * @throws Exception
     * @return void
     */
    public function update() {

        if (static::$readOnly) {
            throw new Exception("Cannot write to READ ONLY tables.");
        }

        if ($this->isNew()) {
            return $this->insert();
        }

        $pk = self::getTablePk();
        $id = $this->id();

        $array = $this->get();

        //validate
        $valid = $this->validateDataBeforeSave($array);
        if ($valid !== true) {
            throw new Exception(is_string($valid) ? $valid : 'Validation failed before saving');
        }

        //preupdate
        if ($this->preUpdate($array) === false) {
            return;
        }

        // input filters
        $array = $this->executeInputFilters($array);

        // remove data not relevant
        $array = array_intersect_key($array, array_flip($this->getColumnNames()));

        // to PK or not to PK
        if ($this->ignoreKeyOnUpdate === true) {
            unset($array[$pk]);
        }

        // compile statement
        $fields = $types = $values = array();

        foreach ($array AS $key => $value) {
            if (is_object($value) && $value instanceof RawSQL) {
                $fields[] = sprintf('`%s` = %s', $key, (string) $value);
            } else {
                $fields[] = sprintf('`%s` = ?', $key);
                $types[] = $this->parseValueType($value);
                $values[] = &$array[$key];
            }
        }

        // where
        $types[] = 'i';
        $values[] = &$id;

        // build sql statement
        $sql = sprintf("UPDATE `%s` SET %s WHERE `%s` = ?", self::getTableName(), implode(', ', $fields), $pk);

        // prepare, bind & execute
        $stmt = self::getConnection()->prepare($sql);

        if (!$stmt) {
            throw new Exception(self::getConnection()->errorCode() . "\n\n" . $sql);
        }

        $result = $stmt->execute($values);

        if (!$result) {
            throw new Exception($stmt->errorCode() . "\n\n" . $sql);
        }

        // reset modified list
        $this->modifiedFields = array();

        $this->hydrateFromDatabase();

        $this->postUpdate();
    }

    /**
     * Delete the record from the database.
     * 
     * @access public
     * @return void
     */
    public function delete() {

        if (static::$readOnly) {
            throw new Exception("Cannot write to READ ONLY tables.");
        }

        if ($this->isNew()) {
            throw new Exception('Unable to delete object, record is new (and therefore doesn\'t exist in the database).');
        }

        if ($this->preDelete() === false) {
            return;
        }

        // build sql statement
        $sql = sprintf("DELETE FROM `%s` WHERE `%s` = ?", self::getTableName(), self::getTablePk());

        // prepare, bind & execute
        $stmt = self::getConnection()->prepare($sql);

        if (!$stmt) {
            throw new Exception(self::getConnection()->errorCode());
        }

        $id = $this->id();
        $result = $stmt->execute([$id]);

        if (!$result) {
            throw new Exception($stmt->errorCode() . "\n\n" . $sql);
        }

        $this->postDelete();
    }

    /**
     * Fetch column names directly from MySQL.
     * 
     * @access public
     * @return array
     */
    public function getColumnNames() {
        $conn = self::getConnection();
        $result = $conn->query(sprintf("DESCRIBE %s;", self::getTableName()));

        if ($result === false) {
            throw new Exception(sprintf('Unable to fetch the column names. %s.', $conn->errorCode()));
        }

        $ret = array();

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $ret[] = $row['Field'];
        }

        $result->closeCursor();

        return $ret;
    }

    /**
     * Parse a value type.
     * 
     * @access protected
     * @param mixed $value
     * @return string
     */
    protected function parseValueType($value) {
        // ints
        if (is_int($value)) {
            return 'i';
        }

        // doubles
        if (is_double($value)) {
            return 'd';
        }

        return 's';
    }

    /**
     * Get/set the parent object for this record.
     * Useful if you want to access the owning record without looking it up again.
     *
     * Use without parameters to return the parent object.
     * 
     * @access public
     * @param object $obj
     * @return object
     */
    public function parentObject($obj = false) {
        $this->parentObject = $obj && is_object($obj) ? $obj : $this->parentObject;

        return $this->parentObject;
    }

    /**
     * Revert the object by reloading our data.
     * 
     * @access public
     * @param boolean $return If true the current object won't be reverted, it will return a new object via cloning.
     * @return void | clone
     */
    public function revert($return = false) {
        if ($return) {
            $ret = clone $this;
            $ret->revert();

            return $ret;
        }

        $this->hydrateFromDatabase();
    }

    /**
     * Get a value for a particular field or all values.
     * 
     * @access public
     * @param string $fieldName If false (default), the entire record will be returned as an array.
     * @return array | string
     */
    public function get($fieldName = false) {
        // return all data
        if ($fieldName === false) {
            return self::convertObjectToArray($this);
        }

        return $this->{$fieldName};
    }

    /**
     * Convert an object to an array.
     *
     * @access public
     * @static
     * @param object $object
     * @return array
     */
    public static function convertObjectToArray($object) {
        if (!is_object($object)) {
            return $object;
        }

        $array = array();
        $r = new ReflectionObject($object);

        foreach ($r->getProperties(ReflectionProperty::IS_PUBLIC) AS $key => $value) {
            $key = $value->getName();
            $value = $value->getValue($object);

            $array[$key] = is_object($value) ? self::convertObjectToArray($value) : $value;
        }

        return $array;
    }

    /**
     * Set a new value for a particular field.
     * 
     * @access public
     * @param string|array $fieldName list of key=>values OR a key name
     * @param string $newValue if $dataMapOrFieldName is a key name, this will be the value
     * @return void
     */
    public function set($fieldName, $newValue = null) {
        if (is_array($fieldName)) {
            foreach ($fieldName as $key => $value) {
                $this->set($key, $value);
            }
            return $this;
        } elseif (is_scalar($fieldName)) {
            // if changed, mark object as modified
            if ($this->{$fieldName} != $newValue) {
                $this->modifiedFields($fieldName, $newValue);
            }

            $this->{$fieldName} = $newValue;
        }
        return $this;
    }

    /**
     * Check if our record has been modified since boot up.
     * This is only available if you use set() to change the object.
     * 
     * @access public
     * @return array | false
     */
    public function isModified() {
        return (count($this->modifiedFields) > 0) ? $this->modifiedFields : false;
    }

    /**
     * Mark a field as modified & add the change to our history.
     * 
     * @access protected
     * @param string $fieldName
     * @param string $newValue
     * @return void
     */
    protected function modifiedFields($fieldName, $newValue) {
        // add modified field to a list
        if (!isset($this->modifiedFields[$fieldName])) {
            $this->modifiedFields[$fieldName] = $newValue;

            return;
        }

        // already modified, initiate a numerical array
        if (!is_array($this->modifiedFields[$fieldName])) {
            $this->modifiedFields[$fieldName] = array($this->modifiedFields[$fieldName]);
        }

        // add new change to array
        $this->modifiedFields[$fieldName][] = $newValue;
    }

    /**
     * Execute an SQL statement & get all records as hydrated objects.
     * 
     * @access public
     * @param string $sql
     * @param integer $return
     * @return mixed
     */
    public static function sql($sql, $return = self::FETCH_MANY) {
        // shortcuts
        $sql = str_replace(array(':table', ':pk'), array(self::getTableName(), self::getTablePk()), $sql);

        // execute
        $result = self::getConnection()->query($sql);

        if (!$result) {
            throw new Exception(sprintf('Unable to execute SQL statement. %s', self::getConnection()->errorCode()));
        }

        if ($return === static::FETCH_NONE) {
            return;
        }

        $ret = array();

        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $ret[] = call_user_func_array(array(get_called_class(), 'hydrate'), array($row));
        }

        $result->closeCursor();

        // return one if requested
        if ($return === static::FETCH_ONE || $return === static::FETCH_FIELD) {
            $ret = isset($ret[0]) ? $ret[0] : null;
        }

        if ($return === static::FETCH_FIELD && $ret && count($ret->get()) > 0) {
            $ret = array_values($ret->get())[0];
        }

        return $ret;
    }

    /**
     * Execute a Count SQL statement & return the number.
     * 
     * @access public
     * @param string $sql
     * @param integer $return
     * @return mixed
     */
    public static function count($sql = "SELECT count(*) FROM :table") {
        $count = (int) (self::sql($sql, static::FETCH_FIELD));

        return $count > 0 ? $count : 0;
    }

    /**
     * Truncate the table.
     * All data will be removed permanently.
     * 
     * @access public
     * @static
     * @return void
     */
    public static function truncate() {

        if (static::$readOnly) {
            throw new Exception("Cannot write to READ ONLY tables.");
        }

        self::sql('TRUNCATE :table', static::FETCH_NONE);
    }

    /**
     * Get all records.
     * 
     * @access public
     * @return array
     */
    public static function all() {
        return self::sql("SELECT * FROM :table");
    }

    /**
     * Retrieve a record by its primary key (PK).
     * 
     * @access public
     * @param integer $pk
     * @return mixed|static|$this|DataModel|self|object
     */
    public static function retrieveByPK($pk) {
        if (!is_numeric($pk)) {
            throw new InvalidArgumentException('The PK must be an integer.');
        }

        $reflectionObj = new ReflectionClass(get_called_class());

        return $reflectionObj->newInstanceArgs(array($pk, static::LOAD_BY_PK));
    }

    /**
     * Load an ER object by array.
     * This skips reloading the data from the database.
     * 
     * @access public
     * @param array $data
     * @return object
     */
    public static function hydrate($data) {
        if (!is_array($data)) {
            throw new InvalidArgumentException('The data given must be an array.');
        }

        $reflectionObj = new ReflectionClass(get_called_class());

        return $reflectionObj->newInstanceArgs(array($data, static::LOAD_BY_ARRAY));
    }

    /**
     * Retrieve a record by a particular column name using the retrieveBy prefix.
     * e.g.
     * 1) Foo::retrieveByTitle('Hello World') is equal to Foo::retrieveByField('title', 'Hello World');
     * 2) Foo::retrieveByIsPublic(true) is equal to Foo::retrieveByField('is_public', true);
     * 
     * @access public
     * @static
     * @param string $name
     * @param array $args
     * @return mixed
     */
    public static function __callStatic($name, $args) {
        $class = get_called_class();

        if (substr($name, 0, 10) == 'retrieveBy') {
            // prepend field name to args
            $field = strtolower(preg_replace('/\B([A-Z])/', '_${1}', substr($name, 10)));
            array_unshift($args, $field);

            return call_user_func_array(array($class, 'retrieveByField'), $args);
        }

        throw new Exception(sprintf('There is no static method named "%s" in the class "%s".', $name, $class));
    }

    /**
     * Retrieve a record by a particular column name.
     * 
     * @access public
     * @static
     * @param string $field
     * @param mixed $value
     * @param integer $return
     * @return mixed|static|$this|DataModel|self
     */
    public static function retrieveByField($field, $value, $return = self::FETCH_MANY) {
        if (!is_string($field))
            throw new InvalidArgumentException('The field name must be a string.');

        // build our query
        $operator = (strpos($value, '%') === false) ? '=' : 'LIKE';

        $sql = sprintf("SELECT * FROM :table WHERE %s %s '%s'", $field, $operator, $value);

        if ($return === static::FETCH_ONE) {
            $sql .= ' LIMIT 0,1';
        }

        // fetch our records
        return self::sql($sql, $return);
    }

    /**
     * Get array for select box.
     *
     * NOTE: Class must have __toString defined.
     * 
     * @access public
     * @param string $where
     * @return array
     */
    public static function buildSelectBoxValues($where = null) {
        $sql = 'SELECT * FROM :table';

        // custom where?
        if (is_string($where))
            $sql .= sprintf(" WHERE %s", $where);

        $values = array();

        foreach (self::sql($sql) AS $object) {
            $values[$object->id()] = (string) $object;
        }

        return $values;
    }

    protected function validateDataBeforeSave(array $data) {
        return true;
    }

}
