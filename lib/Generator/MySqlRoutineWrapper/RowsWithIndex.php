<?php
//----------------------------------------------------------------------------------------------------------------------
/**
 * myStratumPhp
 *
 * @copyright 2003-2014 Paul Water / Set Based IT Consultancy (https://www.setbased.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link
 */
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\DataLayer\Generator\MySqlRoutineWrapper;

use SetBased\DataLayer\Generator\MySqlRoutineWrapper;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for generating a wrapper method for a stored procedure that selects 0 or more rows. The rows are
 * returned as nested arrays.
 */
class RowsWithIndex extends MySqlRoutineWrapper
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   */
  protected function writeResultHandler( $theRoutine )
  {
    $routine_args = $this->getRoutineArgs( $theRoutine );

    $index = '';
    foreach ($theRoutine['columns'] as $column)
    {
      $index .= '[$row[\''.$column.'\']]';
    }

    $this->writeLine( '$result = self::query( \'CALL '.$theRoutine['routine_name'].'('.$routine_args.')\');' );
    $this->writeLine( '$ret = array();' );
    $this->writeLine( 'while($row = $result->fetch_array( MYSQLI_ASSOC )) $ret'.$index.'[] = $row;' );
    $this->writeLine( '$result->free();' );
    $this->writeLine( 'if(self::$ourMySql->more_results()) self::$ourMySql->next_result();' );
    $this->writeLine( 'return $ret;' );
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   */
  protected function writeRoutineFunctionLobFetchData( $theRoutine )
  {
    $index = '';
    foreach ($theRoutine['columns'] as $column)
    {
      $index .= '[$new[\''.$column.'\']]';
    }

    $this->writeLine( '$row = array();' );
    $this->writeLine( 'self::bindAssoc( $stmt, $row );' );
    $this->writeLine();
    $this->writeLine( '$ret = array();' );
    $this->writeLine( 'while (($b = $stmt->fetch()))' );
    $this->writeLine( '{' );
    $this->writeLine( '$new = array();' );
    $this->writeLine( 'foreach( $row as $key => $value )' );
    $this->writeLine( '{' );
    $this->writeLine( '$new[$key] = $value;' );
    $this->writeLine( '}' );
    $this->writeLine( '$ret'.$index.'[] = $new;' );
    $this->writeLine( '}' );
    $this->writeLine();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * {@inheritdoc}
   */
  protected function writeRoutineFunctionLobReturnData()
  {
    $this->writeLine( 'if ($b===false) self::sqlError( \'mysqli_stmt::fetch\' );' );
    $this->writeLine();
    $this->writeLine( 'return $ret;' );
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
