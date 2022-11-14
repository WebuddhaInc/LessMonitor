<?php

namespace WebuddhaInc;

use \WebuddhaInc\Params;

class LessMonitor {

  public $params;
  private $_lessParser;
  private $_lessEnvironment;

  /**
   * [__construct description]
   * @param [type] $params [description]
   */
  public function __construct( $params ){
    $this->params = new Params( $params );
  }

  /**
   * [execute description]
   * @return [type] [description]
   */
  public function execute(){

    // Environment
      $base_path = $this->params->get('base_path', '');

    // Trigger Domains
      $trigger_domains = (array)$this->params->get('trigger_domains', array());
      if( !empty($trigger_domains) ){
        $domains_match = 0;
        $domains_valid = 0;
        foreach( $trigger_domains AS $trigger_domain ){
          $trigger_domain = trim($trigger_domain);
          if( !empty($trigger_domain) ){
            $domains_valid++;
            if( $_SERVER['SERVER_NAME'] == $trigger_domain ){
              $domains_match++;
            }
          }
        }
        if( $domains_valid && !$domains_match ){
          return;
        }
      }

    // Find Watch Paths
      $watch_paths = (array)$this->params->get('watch_paths', array());
      $watch_config = array();
      for($i=0;$i<count($watch_paths);$i++){
        $watch_path = trim(preg_replace('/[\r\n]/','',$watch_paths[$i]));
        if( strlen($watch_path) ){
          $abs_path  = $base_path . $watch_path;
          if( $abs_path != $base_path && is_dir($abs_path) ){
            $watch_config[ $watch_path ] = array(
              'abs_path' => $abs_path
              );
          }
        }
      }

    // Examine dependencies
      $lessDependent = array();
      $partialMTimes = array();
      foreach($watch_config AS $watch_path => $watch_path_config){
        $abs_path = $watch_path_config['abs_path'];
        $lessrc_files = self::_find_files( $abs_path, '/\.lessrc$/' );
        if( $lessrc_files ){
          foreach( $lessrc_files AS $lessrc_file ){
            $source_path = $lessrc_file['path'] . DIRECTORY_SEPARATOR;
            $source_file = $lessrc_file['name'];
            $lessrc_json = json_decode( file_get_contents($source_path . $source_file) );
            if( is_object($lessrc_json) ){
              $files  = isset($lessrc_json->files) ? $lessrc_json->files : array($lessrc_json);
              $shared = isset($lessrc_json->import) ? $lessrc_json->import : array();
              foreach( $files AS $file ){
                $dependentFile = $source_path . ($lessrc_file['name'] == '.lessrc' ? '*' : substr($lessrc_file['name'], 0, strlen($lessrc_file['name'])-2));
                if( isset($file->file) && is_string($file->file) ){
                  $dependentFile = $source_path . $file->file;
                }
                $importLookupList = ( isset($file->import) && is_array($file->import) ? $file->import : array() );
                $importLookupList = array_merge( $importLookupList, $shared );
                foreach( $importLookupList AS $import ){
                  $target_file = self::normalizePath( $source_path . $import );
                  $import_files = array( $target_file );
                  if( preg_match('/\*$/', $target_file) ){
                    $import_files = self::_find_files( preg_replace('/\*$/', '', $target_file), '/\.less$/' );
                    if( $import_files ){
                      for( $i=0; $i<count($import_files); $i++ ){
                        $import_files[$i] = (empty($import_files[$i]['path']) ? '' : $import_files[$i]['path'] . DIRECTORY_SEPARATOR) . $import_files[$i]['name'];
                      }
                    }
                  }
                  foreach( $import_files AS $import_file ){
                    if( is_readable($import_file) ){
                      if( empty($lessDependent[ $dependentFile ]) ){
                        $lessDependent[ $dependentFile ] = array();
                      }
                      if( empty($partialMTimes[$import_file]) ){
                        $partialMTimes[$import_file] = filemtime($import_file);
                      }
                      if( empty($lessDependent[ $dependentFile ][$import_file]) ){
                        $lessDependent[ $dependentFile ][ $import_file ] = $partialMTimes[$import_file];
                      }
                    }
                  }
                }
              }
            }
            else {
              throw new Exception('Error parsing contents of ' . $source_path . $source_file);
            }
          }
        }
      }

    // Look for Less Files & Process
      $lessProcessed = array();
      foreach($watch_config AS $watch_path => $watch_path_config){
        $abs_path = $watch_path_config['abs_path'];
        $less_files = self::_find_files( $abs_path, '/\.less$/' );
        if( $less_files ){
          $target_base = $abs_path;
          if( substr($target_base, strlen($target_base) - 5) == ('less'.DIRECTORY_SEPARATOR) ){
            $target_base = substr($target_base, 0, strlen($target_base) - 5) . 'css' . DIRECTORY_SEPARATOR;
          }
          foreach( $less_files AS $less_file ){
            $source_path = $less_file['path'] . DIRECTORY_SEPARATOR;
            $source_file = $less_file['name'];
            if( substr($source_file,0,1) != '_' ){
              $target_path = $target_base . substr($source_path, strlen($abs_path));
              if( substr($target_path, strlen($target_path) - 5) == ('less'.DIRECTORY_SEPARATOR) ){
                $target_path = substr($target_path, 0, strlen($target_path) - 5) . 'css' . DIRECTORY_SEPARATOR;
              }
              $target_file = preg_replace('/\.less$/','.css',$less_file['name']);
              if( is_dir($target_path) ){
                if( is_readable($target_path.$target_file) ){
                  $source_filemtime = filemtime($source_path.$source_file);
                  $target_filemtime = filemtime($target_path.$target_file);
                  if( $source_filemtime < $target_filemtime ){
                    $less_is_more = true;
                    $less_matches = (
                      isset($lessDependent[$source_path.$source_file])
                      ? $lessDependent[$source_path.$source_file]
                      : array()
                      );
                    if( empty($less_matches) || !$this->params->get('dependency_exclude', false) ){
                      $less_matches = array_merge($less_matches, (
                            isset($lessDependent[$source_path.'*'])
                            ? $lessDependent[$source_path.'*']
                            : array()
                            ));
                    }
                    if( !empty($less_matches) ){
                      foreach( $less_matches AS $dependencyFile => $dependencyFileTime ){
                        if( $target_filemtime < $dependencyFileTime ){
                          $less_is_more = false;
                          break;
                        }
                      }
                    }
                    if( $less_is_more ){
                      continue;
                    }
                  }
                }
                $lessProcessed[] = array($source_path.$source_file, $target_path.$target_file);
                $this->_compileLessFile(
                    array('path' => $source_path, 'file' => $source_file),
                    array('path' => $target_path, 'file' => $target_file)
                    );
              }
            }
          }
        }
      }

    // Debug
      if( !empty($debug) ){
        $this->_inspect( $watch_config, $lessDependent, $lessProcessed );
      }


  }

  /**
   * Normalize Path (remove dot notations)
   * @param  [type] $path [description]
   * @return [type]       [description]
   */
  public static function normalizePath($path){
    $result = preg_replace('/(\/\.\/\|\.\/)/','/',$path);
    // $regex  = '/\/[A-Za-z\s\.\,\-\_\+\=\[\]\{\}\:\;\"\'\<\>]+\/\.\.\//';
    $regex  = '/\/[^\/]+\/\.\.\//';
    do {
      $lastResult = $result;
      if( preg_match($regex,$result) ){
        $result = preg_replace($regex, '/', $result, 1);
      }
    } while( $result != $lastResult );
    return $result;
  }

  /**
   * Write a compiled LESS file to disk
   * @param  [type] $inFileInfo  [description]
   * @param  [type] $outFileInfo [description]
   * @return [type]              [description]
   */
  private function _compileLessFile( $inFileInfo, $outFileInfo = null ){

    // Input File
      $inFile = null;
      $inPath = null;
      if( isset($inFileInfo['path']) ){
        $inPath = $inFileInfo['path'];
        $inFile = $inFileInfo['file'];
      }
      else {
        $inFile = (string)$inFileInfo;
      }
      $inFullPath = ($inPath?$inPath:'') . $inFile;

    // Output File
      $outFile = null;
      $outPath = null;
      if( isset($outFileInfo['path']) ){
        $outPath = $outFileInfo['path'];
        $outFile = $outFileInfo['file'];
      }
      else if( !is_null($outFileInfo) ) {
        $outFile = (string)$outFileInfo;
      }
      if( is_null($outFile) ){
        $outFullPath = ($outPath?$outPath:'') . preg_replace('/\.less$/','',$inFile) . '.css';
      }
      else {
        $outFullPath = ($outPath?$outPath:'') . $outFile;
      }

    // Verify File
      if (!is_readable($inFullPath)) {
        throw new Exception('failed to find file '.$inFullPath);
      }

    // Load Parser
      if( empty($this->_lessParser) ){
        if( !class_exists('Less_Parser') ){
          require_once __DIR__ . '/../lessc/lib/Less/Autoloader.php';
          \Less_Autoloader::register();
        }
        $this->_lessEnvironment = new \Less_Environment();
        $this->_lessParser = new \Less_Parser( $this->_lessEnvironment );
      }

    // Parse & Store
      $lessParser = $this->_lessParser;
      if( isset($lessParser) ){
        // Prepare callback for symlink correction
          $importDirs = array();
          if(
            realpath($inPath) != $inPath
            && realpath($inPath) . DIRECTORY_SEPARATOR != $inPath
            ){
            $importDirs[$inPath] = function($fname) use ($inPath) {
              return array(Less_Environment::normalizePath($inPath.$fname), null);
            };
          }
        // Reset Parser
          $lessParser->Reset(
            array(
              /*
              NOT WORKING - PATH ERRORS
              'sourceMap'         => true,
              'sourceMapWriteTo'  => $outFullPath . '.map',
              'sourceMapBasepath' => dirname($inFullPath),
              */
              'import_dirs'       => $importDirs,
              'compress'          => $this->params->get('compress', false)
            ));
        // Parse & Write Output
          try {
            $lessParser->parseFile( $inFullPath );
          } catch(Exception $e) {
            die( $e->getMessage() );
          }
          file_put_contents( $outFullPath, $lessParser->getCss() );
      }
      else {
        throw new Exception('failed to load parser');
      }

  }

  /**
   * Scan folder for regex dir / filename match
   * @param  [type]  $path    [description]
   * @param  string  $regex   [description]
   * @param  boolean $recurse [description]
   * @param  boolean $folders [description]
   * @return [type]           [description]
   */
  private static function &_find_files($path,$regex='/.*/',$recurse=true,$folders=false){
    $cache_key = md5(json_encode(array($path, $regex, $recurse, $folders)));
    if( isset(self::$_find_files_cache[$cache_key]) ){
      return self::$_find_files_cache[$cache_key];
    }
    $path = preg_replace('/\/$/','',$path);
    $fileList = Array();
    if( is_null($regex) )
      $regex = '/.*/';
    if( is_dir($path) ){
      $dir = opendir($path);
      while (false !== ($file = readdir($dir))){
        $filePath = $path.'/'.$file;
        if(is_dir($filePath)){
          if( $folders && !preg_match('/^\.+$/',$file) && preg_match($regex,$file) )
            $fileList[] = Array(
              'name'    => $file,
              'path'    => $path,
              'size'    => null,
              'type'    => null,
              'is_dir'  => true
              );
          if( $recurse && !in_array($file,Array('.','..')) )
            $fileList = array_merge($fileList,self::_find_files($filePath,$regex));
        } elseif(preg_match($regex,$file)) {
          $fileList[] = Array(
            'name'    => $file,
            'path'    => $path,
            'size'    => filesize($filePath),
            'type'    => strtolower(preg_replace('/^.*\.(\w+)$/','$1',$file)),
            'is_dir'  => false
            );
        }
      }
      closedir($dir);
    }
    self::$_find_files_cache[$cache_key] = $fileList;
    return $fileList;
  }
  private static $_find_files_cache = array();

  /**
   * [_inspect description]
   * @return [type] [description]
   */
  private function _inspect(){
    echo '<pre>' . print_r( func_get_args(), true ) . '</pre>';
  }

}

