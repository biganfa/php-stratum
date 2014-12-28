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
namespace SetBased\DataLayer\Generator;

use SetBased\DataLayer\Generator\MySqlRoutineWrapper;
use SetBased\DataLayer\StaticDataLayer as DataLayer;

//----------------------------------------------------------------------------------------------------------------------
/**
 * Class for generating a class with wrapper methods for calling stored routines in a MySQL database.
 */
class MySqlRoutineWrapperGenerator
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The generated PHP code.
   *
   * @var string
   */
  private $myCode = '';

  /**
   * The filename of the configuration file.
   *
   * @var string
   */
  private $myConfigurationFilename;

  /**
   * The schema name.
   *
   * @var string
   */
  private $myDatabase;

  /**
   * Host name or address.
   *
   * @var string
   */
  private $myHostName;

  /**
   * If true BLOBs and CLOBs must be treated as strings.
   *
   * @var bool
   */
  private $myLobAsStringFlag;

  /**
   * The filename of the file with the metadata of all stored procedures.
   *
   * @var string
   */
  private $myMetadataFilename;

  /**
   * The class name (including namespace) of the parent class of the routine wrapper.
   *
   * @var string
   */
  private $myParentClassName;

  /**
   * The password.
   *
   * @var string
   */
  private $myPassword;

  /**
   * The user name.
   *
   * @var string
   */
  private $myUserName;

  /**
   * The class name (including namespace) of the routine wrapper.
   *
   * @var string
   */
  private $myWrapperClassName;

  /**
   * The filename where the generated wrapper class must be stored
   *
   * @var string
   */
  private $myWrapperFilename;

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The "main" of the wrapper generator.
   *
   * @param $theConfigurationFilename string The name of the configuration file.
   *
   * @return int Returns 0 on success, 1 if one or more errors occured.
   */
  public function run( $theConfigurationFilename )
  {
    $this->myConfigurationFilename = $theConfigurationFilename;

    $this->readConfigurationFile();

    DataLayer::connect( $this->myHostName, $this->myUserName, $this->myPassword, $this->myDatabase );

    $routines = $this->readRoutineMetaData();

    // Write the header of the wrapper class.
    $this->writeClassHeader();

    if (is_array( $routines ))
    {
      // Write methods for each stored routine.
      foreach ($routines as $routine)
      {
        // If routine type is hidden don't create routine wrapper.
        if ($routine['type']!='hidden')
        {
          $this->writeRoutineFunction( $routine );
        }
      }
    }
    else
    {
      echo "No files with stored routines found.\n";
    }


    // Write the trailer of the wrapper class.
    $this->writeClassTrailer();


    $write_wrapper_file_flag = true;
    if (file_exists( $this->myWrapperFilename ))
    {
      $old_code = file_get_contents( $this->myWrapperFilename );
      if ($old_code===false) set_assert_failed( "Error reading file '%s'.", $this->myWrapperFilename );
      if ($this->myCode==$old_code) $write_wrapper_file_flag = false;
    }

    if ($write_wrapper_file_flag)
    {
      $bytes = file_put_contents( $this->myWrapperFilename, $this->myCode );
      if ($bytes===false) set_assert_failed( "Error writing file '%s'.", $this->myWrapperFilename );
      echo "Created: '", $this->myWrapperFilename, "'.\n";
    }

    DataLayer::disconnect();

    return 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the value of a setting.
   *
   * @param array  $theSettings    The settings.
   * @param string $theSectionName The section name of the requested setting.
   * @param string $theSettingName The name of the requested setting.
   *
   * @return string The value of a setting.
   */
  private function getSetting( $theSettings, $theSectionName, $theSettingName )
  {
    // Test if the section exists.
    if (!array_key_exists( $theSectionName, $theSettings ))
    {
      set_assert_failed( "Section '%s' not found in configuration file '%s'.",
                         $theSectionName,
                         $this->myConfigurationFilename );
    }

    // Test if the setting in the section exists.
    if (!array_key_exists( $theSettingName, $theSettings[$theSectionName] ))
    {
      set_assert_failed( "Setting '%s' not found in section '%s' configuration file '%s'.",
                         $theSettingName,
                         $theSectionName,
                         $this->myConfigurationFilename );
    }

    return $theSettings[$theSectionName][$theSettingName];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads parameters from the configuration file.
   *
   * @see $myConfigurationFilename The property with the filename of the configuration file.
   */
  private function readConfigurationFile()
  {
    // Read the configuration file.
    $settings = parse_ini_file( $this->myConfigurationFilename, true );
    if ($settings===false) set_assert_failed( "Unable open configuration file '%s'.", $this->myConfigurationFilename );

    // Set default values.
    if (!isset($settings['wrapper']['lob_as_string']))
    {
      $settings['wrapper']['lob_as_string'] = false;
    }

    $this->myHostName = $this->getSetting( $settings, 'database', 'host_name' );
    $this->myUserName = $this->getSetting( $settings, 'database', 'user_name' );
    $this->myPassword = $this->getSetting( $settings, 'database', 'password' );
    $this->myDatabase = $this->getSetting( $settings, 'database', 'database_name' );

    $this->myParentClassName  = $this->getSetting( $settings, 'wrapper', 'parent_class' );
    $this->myWrapperClassName = $this->getSetting( $settings, 'wrapper', 'wrapper_class' );
    $this->myWrapperFilename  = $this->getSetting( $settings, 'wrapper', 'wrapper_file' );
    $this->myMetadataFilename = $this->getSetting( $settings, 'wrapper', 'metadata' );
    $this->myLobAsStringFlag  = ($this->getSetting( $settings, 'wrapper', 'lob_as_string' )) ? true : false;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the metadata of stored routines.
   *
   * @see $myMetadataFilename The property with filename of the file with the metadata.
   *
   * @return array
   */
  private function readRoutineMetaData()
  {
    $theFilename = $this->myMetadataFilename;

    $handle = fopen( $theFilename, 'r' );
    if ($handle===null) set_assert_failed( "Unable to open file '%s'.", $theFilename );

    $routines = '';

    // Skip header row.
    fgetcsv( $handle, 0, ',' );
    $line_number = 1;

    while (($row = fgetcsv( $handle, 0, ',' ))!==false)
    {
      $line_number++;

      // Test the number of fields in the row.
      $n = count( $row );
      if ($n!=10)
      {
        set_assert_failed( "Error at line %d in file '%s'. Expecting %d fields but found %d fields.",
                           $line_number,
                           $theFilename,
                           10,
                           $n );
      }

      $routines[$line_number]['routine_name'] = $row[0];
      $routines[$line_number]['type']         = $row[1];
      $routines[$line_number]['table_name']   = $row[2];

      $routines[$line_number]['argument_names'] = ($row[3]) ? explode( ',', $row[3] ) : array();
      $routines[$line_number]['argument_types'] = ($row[4]) ? explode( ',', $row[4] ) : array();
      $routines[$line_number]['columns']        = ($row[5]) ? explode( ',', $row[5] ) : array();
      $routines[$line_number]['fields']         = ($row[6]) ? explode( ',', $row[6] ) : array();
      $routines[$line_number]['column_types']   = ($row[7]) ? explode( ',', $row[7] ) : array();
    }
    if (!feof( $handle )) set_assert_failed( "Did not reach eof of '%s'.", $theFilename );

    $err = fclose( $handle );
    if ($err===false) set_assert_failed( "Error closing file '%s'.", $theFilename );

    return $routines;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Generate a class header for stored routine wrapper.
   */
  private function writeClassHeader()
  {
    $p = strrpos( $this->myWrapperClassName, '\\' );
    if ($p!==false)
    {
      $namespace  = ltrim( substr( $this->myWrapperClassName, 0, $p ), '\\' );
      $class_name = substr( $this->myWrapperClassName, $p + 1 );
    }
    else
    {
      $namespace  = null;
      $class_name = $this->myWrapperClassName;
    }

    $this->myCode .= "<?php\n";
    $this->myCode .= '//'.str_repeat( '-', MySqlRoutineWrapper::C_PAGE_WIDTH - 2 )."\n";
    if ($namespace)
    {
      $this->myCode .= "namespace ${namespace};\n";
      $this->myCode .= "\n";
      $this->myCode .= '//'.str_repeat( '-', MySqlRoutineWrapper::C_PAGE_WIDTH - 2 )."\n";
    }
    $this->myCode .= 'class '.$class_name.' extends '.$this->myParentClassName."\n";
    $this->myCode .= "{\n";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Generate a class trailer for stored routine wrapper.
   */
  private function writeClassTrailer()
  {
    $this->myCode .= '  //'.str_repeat( '-', MySqlRoutineWrapper::C_PAGE_WIDTH - 4 )."\n";
    $this->myCode .= "}\n";
    $this->myCode .= "\n";
    $this->myCode .= '//'.str_repeat( '-', MySqlRoutineWrapper::C_PAGE_WIDTH - 2 )."\n";
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Generates a complete wrapper method for a stored routine.
   *
   * @param array $theRoutine The metadata of the stored routine.
   */
  private function writeRoutineFunction( $theRoutine )
  {
    $wrapper = MySqlRoutineWrapper::createRoutineWrapper( $theRoutine, $this->myLobAsStringFlag );
    $this->myCode .= $wrapper->writeRoutineFunction( $theRoutine );
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
