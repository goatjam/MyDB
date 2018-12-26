<?php
/*
  MyDB.php v1.0 - A simple mysql persistence class for PHP

  Copyright Brett Glasson <brett.glasson@zoho.com> 2018

  I created this for my own personal use because I didn't want to
  use the more complicated persistence frameworks such as symfony or
  doctrine. If your project is very large you probably dont want to
  use this, otherwise enjoy!

  -----------------------------------------------------------------------

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see <https://www.gnu.org/licenses/>.

  -----------------------------------------------------------------------
*/

class MyDB
{
  private $conn;

  public function __construct(array $connection)
  {
    try
    {
      $this->conn = new PDO("mysql:host=".$connection['host'].";dbname=".$connection['dbname'].";charset=utf8",$connection['user'],$connection['password']);
    }
    catch(PDOException $ex)
    {
      die(json_encode(array('outcome' => false, 'message' => 'Unable to connect')));
    }
  }

  /**
  * get an entity from class base table by id
  * @param int $id
  * @param string $classname
  * @return Object
  */
  public function get(int $id, string $classname)
  {
    $entity = new $classname();
    $table = strtolower($classname);

    $s = $this->prepare("SELECT * FROM ".$table." WHERE id = ?");
    $array = $this->findOne($s, array($id));

    if(!$array)
      return null;

    foreach($array as $key => $value)
    {
      $setter = "set".ucwords(strtolower($key));
      if(is_numeric($value))
      {
        $value = (int)$value;
      }

      $entity->$setter($value);
    }

    return $entity;
  }

  /**
  * wrapper for conn->prepare
  * @param string $sql
  * @return PDOStatement
  */
  public function prepare(string $sql)
  {
    return $this->conn->prepare($sql);
  }

  /**
  * get the id for the last entity that was inserted into the database
  * @return int
  */
  public function lastInsertId()
  {
    return $this->conn->lastInsertId();
  }

  /**
  * execute a pdo statement
  * @param PDOStatement $s
  * @param array $criteria
  * @return PDOStatement
  */
  public function execute(PDOStatement $s, array $criteria = array())
  {
    // Prepared statements can either be indexed (id = ?) or named (id = :id)
    $PDO_named_mode = array_keys($criteria) !== range(0, count($criteria) - 1);

    foreach($criteria as $key => $val)
    {
      // Indexed arrays start at zero but pdo numbering starts at 1 so we need to fix that
      if(!$PDO_named_mode)
        $key = $key+1;

      if(is_string($val))
        $s->bindValue($key, $val);
      else
        $s->bindValue($key, (int)$val, PDO::PARAM_INT);
    }

    if($s->execute())
      return $s;
    else
      $this->_pdoError($s);
  }

  /**
  * get a row from the dataabase
  * @param PDOStatement $s
  * @param array $criteria
  * @return array
  */
  public function findOne(PDOStatement $s, array $criteria)
  {
    $s = $this->execute($s, $criteria);
    return $s->fetch(PDO::FETCH_ASSOC);
  }

  /**
  * get multiple rows from the dataabase
  * @param PDOStatement $s
  * @param array $criteria
  * @return array
  */
  public function findAll(PDOStatement $s, array $criteria = array())
  {
    $s = $this->execute($s, $criteria);
    return $s->fetchAll(PDO::FETCH_ASSOC);
  }

  /**
  * persist an entity into a table
  * The entity classname must match the table name and must define persistable fields
  * as global protected variables that match the db column name and must have a setter
  * and getter of the same name in camelCase. The entity also must contain an
  * autoincrement id INT column
  *
  * class Table
  * {
  *   protected $id;
  *   protected $column;
  *
  *   public function getId()
  *   public function setId(int $id)
  *   public function getColumn()
  *   public function setColumn($variable)
  *
  * etc etc
  *
  * returns the id for the persisted entity
  * @param Object
  * @param array
  * @return int
  */
  public function persist(Object $entity)
  {
    $table = strtolower(get_class($entity));

    $criteria = array();
    $placeholders = '';
    $fields = '';

    $id = $entity->getId();
    if($id == 0) // <------ INSERT ------->
    {
      # INSERT takes fields and values seperately like this: (id, name) & (1, "test")
      # so we will assemble a comma seperated string for the field list and an array
      # for the values
      foreach((array)$entity as $key => $value)
      {
        # Do nothing if the value is null. If you actually want a null value to be
        # created in the database for this insert then you should set that as the default
        # when you create the table
        if(is_null($value))
          continue;

        $key = $this->_escapeString($key);

        $fields = $fields.",".$key;
        $criteria[] = $value;
        $placeholders = $placeholders.'?,';
      }

      # remove unwanted commas
      $placeholders = rtrim($placeholders,',');
      $fields = ltrim($fields, ',');

      $s = $this->prepare("INSERT INTO ".$table." (".$fields.") VALUES (".$placeholders.")");

      $this->execute($s,$criteria);
      return $this->lastInsertId();
    }
    else // <------ UPDATE ------->
    {
      # UPDATE takes fields and values like this: id=1, name="test"
      # so we will assemble a string like this:   id=?, name=?
      # and an array for the values
      $updatestring = '';

      foreach((array)$entity as $key => $value)
      {
        $key = $this->_escapeString($key);
        $updatestring = $updatestring.$key." = ?,";
        $criteria[] = $value;
      }

      # remove unwanted commas
      $updatestring = rtrim($updatestring,",");

      $s = $this->prepare("UPDATE ".$table." SET ".$updatestring." WHERE id = ".$id);

      if($this->execute($s,$criteria))
        return $id;
    }
  }

  /**
  * delete an entity from class base table
  * @param Object $entity
  * @return bool
  */
  public function delete(Object $entity)
  {
    $table = strtolower(get_class($entity));
    $id = $entity->getId();
    $s = $this->prepare("DELETE FROM ".$table." WHERE id = ?");

    return $this->execute($s, array($id));
  }

  /*
  ######################################################################################################
    private functions
  ######################################################################################################
  */

  private function _escapeString($string)
  {
    $string = trim(preg_replace('/[\*]+/', '', $string));
    $string = str_replace("⅓","\⅓",$string);
    return trim($string);
  }

  private function _pdoError(PDOStatement $s)
  {
    if(php_sapi_name() === 'cli')
      $lf = "\n";
    else
      $lf = "</br>";

    echo("Failed to execute PDOStatement:".$lf.$lf);
    var_dump($s->debugDumpParams());
    echo($lf);

    $trace = debug_backtrace();

    $prepend = '';
    foreach($trace as $caller)
    {
      $prepend = $prepend.'-';
      echo(" ".$prepend." ".$caller['file']." line ".$caller['line'].$lf);
    }
    die($lf);
  }
}
?>
