<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Stratum\Test\MySql;

/**
 * Test cases for stored routines with designation type singleton0 with LOBs.
 */
class Singleton0WithLobTest extends DataLayerTestCase
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Stored routine with designation type singleton0 must return null.
   */
  public function test1()
  {
    $ret = $this->dataLayer->tstTestSingleton0aWithLob(0, 'blob');
    $this->assertInternalType('null', $ret);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Stored routine with designation type singleton0 must return 1 value.
   */
  public function test2()
  {
    $ret = $this->dataLayer->tstTestSingleton0aWithLob(1, 'blob');
    self::assertEquals('1', $ret);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * An exception must be thrown when a stored routine with designation type singleton0 returns more than 1 values.
   *
   * @expectedException SetBased\Stratum\Exception\ResultException
   */
  public function test3()
  {
    $this->dataLayer->tstTestSingleton0aWithLob(2, 'blob');
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------

