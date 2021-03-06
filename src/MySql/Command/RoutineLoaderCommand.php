<?php
//----------------------------------------------------------------------------------------------------------------------
namespace SetBased\Stratum\MySql\Command;

use SetBased\Exception\RuntimeException;
use SetBased\Stratum\Helper\SourceFinderHelper;
use SetBased\Stratum\MySql\MetadataDataLayer as DataLayer;
use SetBased\Stratum\MySql\RoutineLoaderHelper;
use SetBased\Stratum\NameMangler\NameMangler;
use SetBased\Stratum\Style\StratumStyle;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for loading stored routines into a MySQL instance from pseudo SQL files.
 */
class RoutineLoaderCommand extends MySqlCommand
{
  //--------------------------------------------------------------------------------------------------------------------
  /**
   * The default character set under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $characterSet;

  /**
   * The default collate under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $collate;

  /**
   * The path to the config file.
   *
   * @var string
   */
  private $configFilename;

  /**
   * Name of the class that contains all constants.
   *
   * @var string
   */
  private $constantClassName;

  /**
   * An array with source filenames that are not loaded into MySQL.
   *
   * @var array
   */
  private $errorFilenames = [];

  /**
   * Class name for mangling routine and parameter names.
   *
   * @var string
   */
  private $nameMangler;

  /**
   * The metadata of all stored routines. Note: this data is stored in the metadata file and is generated by PhpStratum.
   *
   * @var array
   */
  private $phpStratumMetadata;

  /**
   * The filename of the file with the metadata of all stored routines.
   *
   * @var string
   */
  private $phpStratumMetadataFilename;

  /**
   * Old metadata of all stored routines. Note: this data comes from information_schema.ROUTINES.
   *
   * @var array
   */
  private $rdbmsOldMetadata;

  /**
   * A map from placeholders to their actual values.
   *
   * @var array
   */
  private $replacePairs = [];

  /**
   * Pattern where of the sources files.
   *
   * @var string
   */
  private $sourcePattern;

  /**
   * All sources with stored routines. Each element is an array with the following keys:
   * <ul>
   * <li> path_name    The path the source file.
   * <li> routine_name The name of the routine (equals the basename of the path).
   * <li> method_name  The name of the method in the data layer for the wrapper method of the stored routine.
   * </ul>
   *
   * @var array[]
   */
  private $sources = [];

  /**
   * The SQL mode under which the stored routine will be loaded and run.
   *
   * @var string
   */
  private $sqlMode;

  //--------------------------------------------------------------------------------------------------------------------

  /**
   * @inheritdoc
   */
  protected function configure()
  {
    $this->setName('loader')
         ->setDescription('Generates the routine wrapper class')
         ->addArgument('config file', InputArgument::REQUIRED, 'The stratum configuration file')
         ->addArgument('sources', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Sources with stored routines');
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * @inheritdoc
   */
  protected function execute(InputInterface $input, OutputInterface $output)
  {
    $this->io = new StratumStyle($input, $output);

    $this->io->title('Loader');

    $this->configFilename = $input->getArgument('config file');
    $filenames            = $input->getArgument('sources');
    $settings             = $this->readConfigFile($this->configFilename);

    $this->connect($settings);

    if (empty($filenames))
    {
      $this->loadAll();
    }
    else
    {
      $this->loadList($filenames);
    }

    $this->logOverviewErrors();

    $this->disconnect();

    return ($this->errorFilenames) ? 1 : 0;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads parameters from the configuration file.
   *
   * @param string $configFilename
   *
   * @return array
   */
  protected function readConfigFile($configFilename)
  {
    $settings = parse_ini_file($configFilename, true);

    $this->phpStratumMetadataFilename = self::getSetting($settings, true, 'loader', 'metadata');
    $this->sourcePattern              = self::getSetting($settings, true, 'loader', 'sources');
    $this->sqlMode                    = self::getSetting($settings, true, 'loader', 'sql_mode');
    $this->characterSet               = self::getSetting($settings, true, 'loader', 'character_set');
    $this->collate                    = self::getSetting($settings, true, 'loader', 'collate');
    $this->constantClassName          = self::getSetting($settings, false, 'constants', 'class');
    $this->nameMangler                = self::getSetting($settings, false, 'wrapper', 'mangler_class');

    return $settings;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Detects stored routines that would result in duplicate wrapper method name.
   */
  private function detectNameConflicts()
  {
    // Get same method names from array
    list($sources_by_path, $sources_by_method) = $this->getDuplicates();

    // Add every not unique method name to myErrorFileNames
    foreach ($sources_by_path as $source)
    {
      $this->errorFilenames[] = $source['path_name'];
    }

    // Log the sources files with duplicate method names.
    foreach ($sources_by_method as $method => $sources)
    {
      $tmp = [];
      foreach ($sources as $source)
      {
        $tmp[] = $source['path_name'];
      }

      $this->io->error(sprintf("The following source files would result wrapper methods with equal name '%s'",
                               $method));
      $this->io->listing($tmp);
    }

    // Remove duplicates from mySources.
    foreach ($this->sources as $i => $source)
    {
      if (isset($sources_by_path[$source['path_name']]))
      {
        unset($this->sources[$i]);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Drops obsolete stored routines (i.e. stored routines that exits in the current schema but for which we don't have
   * a source file).
   */
  private function dropObsoleteRoutines()
  {
    // Make a lookup table from routine name to source.
    $lookup = [];
    foreach ($this->sources as $source)
    {
      $lookup[$source['routine_name']] = $source;
    }

    // Drop all routines not longer in sources.
    foreach ($this->rdbmsOldMetadata as $old_routine)
    {
      if (!isset($lookup[$old_routine['routine_name']]))
      {
        $this->io->logInfo('Dropping %s <dbo>%s</dbo>',
                           strtolower($old_routine['routine_type']),
                           $old_routine['routine_name']);

        DataLayer::dropRoutine($old_routine['routine_type'], $old_routine['routine_name']);
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Searches recursively for all source files.
   *
   */
  private function findSourceFiles()
  {
    $helper    = new SourceFinderHelper(dirname($this->configFilename));
    $filenames = $helper->findSources($this->sourcePattern);

    foreach ($filenames as $filename)
    {
      $routineName     = pathinfo($filename, PATHINFO_FILENAME);
      $this->sources[] = ['path_name'    => $filename,
                          'routine_name' => $routineName,
                          'method_name'  => $this->methodName($routineName)];
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Finds all source files that actually exists from a list of file names.
   *
   * @param string[] $filenames The list of file names.
   */
  private function findSourceFilesFromList($filenames)
  {
    foreach ($filenames as $filename)
    {
      if (!file_exists($filename))
      {
        $this->io->error(sprintf("File not exists: '%s'", $filename));
        $this->errorFilenames[] = $filename;
      }
      else
      {
        $routineName     = pathinfo($filename, PATHINFO_FILENAME);
        $this->sources[] = ['path_name'    => $filename,
                            'routine_name' => $routineName,
                            'method_name'  => $this->methodName($routineName)];
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Selects schema, table, column names and the column type from MySQL and saves them as replace pairs.
   */
  private function getColumnTypes()
  {
    $rows = DataLayer::getAllTableColumns();
    foreach ($rows as $row)
    {
      $key = '@'.$row['table_name'].'.'.$row['column_name'].'%type@';
      $key = strtoupper($key);

      $value = $row['column_type'];
      if (isset($row['character_set_name'])) $value .= ' character set '.$row['character_set_name'];

      $this->replacePairs[$key] = $value;
    }

    $this->io->text(sprintf('Selected %d column types for substitution', sizeof($rows)));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads constants set the PHP configuration file and  adds them to the replace pairs.
   */
  private function getConstants()
  {
    // If myTargetConfigFilename is not set return immediately.
    if (!isset($this->constantClassName)) return;

    $reflection = new \ReflectionClass($this->constantClassName);

    $constants = $reflection->getConstants();
    foreach ($constants as $name => $value)
    {
      if (!is_numeric($value)) $value = "'".$value."'";

      $this->replacePairs['@'.$name.'@'] = $value;
    }

    $this->io->text(sprintf('Read %d constants for substitution from <fso>%s</fso>',
                            sizeof($constants),
                            OutputFormatter::escape($reflection->getFileName())));
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Gets the SQL mode in the order as preferred by MySQL.
   */
  private function getCorrectSqlMode()
  {
    $this->sqlMode = DataLayer::getCorrectSqlMode($this->sqlMode);
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns all elements in {@link $sources} with duplicate method names.
   *
   * @return array[]
   */
  private function getDuplicates()
  {
    // First pass make lookup table by method_name.
    $lookup = [];
    foreach ($this->sources as $source)
    {
      if (isset($source['method_name']))
      {
        if (!isset($lookup[$source['method_name']]))
        {
          $lookup[$source['method_name']] = [];
        }

        $lookup[$source['method_name']][] = $source;
      }
    }

    // Second pass find duplicate sources.
    $duplicates_sources = [];
    $duplicates_methods = [];
    foreach ($this->sources as $source)
    {
      if (sizeof($lookup[$source['method_name']])>1)
      {
        $duplicates_sources[$source['path_name']]   = $source;
        $duplicates_methods[$source['method_name']] = $lookup[$source['method_name']];
      }
    }

    return [$duplicates_sources, $duplicates_methods];
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Retrieves information about all stored routines in the current schema.
   */
  private function getOldStoredRoutinesInfo()
  {
    $this->rdbmsOldMetadata = [];

    $routines = DataLayer::getRoutines();
    foreach ($routines as $routine)
    {
      $this->rdbmsOldMetadata[$routine['routine_name']] = $routine;
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines into MySQL.
   */
  private function loadAll()
  {
    $this->findSourceFiles();
    $this->detectNameConflicts();
    $this->getColumnTypes();
    $this->readStoredRoutineMetadata();
    $this->getConstants();
    $this->getOldStoredRoutinesInfo();
    $this->getCorrectSqlMode();

    $this->loadStoredRoutines();

    // Drop obsolete stored routines.
    $this->dropObsoleteRoutines();

    // Remove metadata of stored routines that have been removed.
    $this->removeObsoleteMetadata();

    $this->io->writeln('');

    // Write the metadata to file.
    $this->writeStoredRoutineMetadata();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines in a list into MySQL.
   *
   * @param string[] $fileNames The list of files to be loaded.
   */
  private function loadList($fileNames)
  {
    $this->findSourceFilesFromList($fileNames);
    $this->detectNameConflicts();
    $this->getColumnTypes();
    $this->readStoredRoutineMetadata();
    $this->getConstants();
    $this->getOldStoredRoutinesInfo();
    $this->getCorrectSqlMode();

    $this->loadStoredRoutines();

    // Write the metadata to file.
    $this->writeStoredRoutineMetadata();
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Loads all stored routines.
   */
  private function loadStoredRoutines()
  {
    // Log an empty line.
    $this->io->writeln('');

    // Sort the sources by routine name.
    usort($this->sources, function ($a, $b) {
      return strcmp($a['routine_name'], $b['routine_name']);
    });

    // Process all sources.
    foreach ($this->sources as $filename)
    {
      $routine_name = $filename['routine_name'];

      $helper = new RoutineLoaderHelper($this->io,
                                        $filename['path_name'],
                                        isset($this->phpStratumMetadata[$routine_name]) ? $this->phpStratumMetadata[$routine_name] : null,
                                        $this->replacePairs,
                                        isset($this->rdbmsOldMetadata[$routine_name]) ? $this->rdbmsOldMetadata[$routine_name] : null,
                                        $this->sqlMode,
                                        $this->characterSet,
                                        $this->collate);

      $meta_data = $helper->loadStoredRoutine();
      if ($meta_data===false)
      {
        // An error occurred during the loading of the stored routine.
        $this->errorFilenames[] = $filename['path_name'];
        unset($this->phpStratumMetadata[$routine_name]);
      }
      else
      {
        // Stored routine is successfully loaded.
        $this->phpStratumMetadata[$routine_name] = $meta_data;
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Logs the source files that were not successfully loaded into MySQL.
   */
  private function logOverviewErrors()
  {
    if (!empty($this->errorFilenames))
    {
      $this->io->warning('Routines in the files below are not loaded:');
      $this->io->listing($this->errorFilenames);
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Returns the method name in the wrapper for a stored routine. Returns null when name mangler is not set.
   *
   * @param string $routineName The name of the routine.
   *
   * @return null|string
   */
  private function methodName($routineName)
  {
    if ($this->nameMangler!==null)
    {
      /** @var NameMangler $mangler */
      $mangler = $this->nameMangler;

      return $mangler::getMethodName($routineName);
    }

    return null;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Reads the metadata of stored routines from the metadata file.
   */
  private function readStoredRoutineMetadata()
  {
    if (file_exists($this->phpStratumMetadataFilename))
    {
      $this->phpStratumMetadata = (array)json_decode(file_get_contents($this->phpStratumMetadataFilename), true);
      if (json_last_error()!=JSON_ERROR_NONE)
      {
        throw new RuntimeException("Error decoding JSON: '%s'.", json_last_error_msg());
      }
    }
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Removes obsolete entries from the metadata of all stored routines.
   */
  private function removeObsoleteMetadata()
  {
    // 1 pass through $mySources make new array with routine_name is key.
    $clean = [];
    foreach ($this->sources as $source)
    {
      $routine_name = $source['routine_name'];
      if (isset($this->phpStratumMetadata[$routine_name]))
      {
        $clean[$routine_name] = $this->phpStratumMetadata[$routine_name];
      }
    }

    $this->phpStratumMetadata = $clean;
  }

  //--------------------------------------------------------------------------------------------------------------------
  /**
   * Writes the metadata of all stored routines to the metadata file.
   */
  private function writeStoredRoutineMetadata()
  {
    $json_data = json_encode($this->phpStratumMetadata, JSON_PRETTY_PRINT);
    if (json_last_error()!=JSON_ERROR_NONE)
    {
      throw new RuntimeException("Error of encoding to JSON: '%s'.", json_last_error_msg());
    }

    // Save the metadata.
    $this->writeTwoPhases($this->phpStratumMetadataFilename, $json_data);
  }

  //--------------------------------------------------------------------------------------------------------------------
}

//----------------------------------------------------------------------------------------------------------------------
