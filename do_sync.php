<?php
/**
 * STATION SYNCHRONISATION
 * do_sync.php
 *
 * Todo
 * - mikali clientin versio-taulu on tyhja, ala kaadu
 */

set_time_limit (1800);
set_magic_quotes_runtime(0);

require_once('check.php');
require_once('options/database.php');
require_once('options/general.php');
require_once('classes/class.mysql.php');
require_once('classes/class.project.php');
require_once('classes/class.xml_parser.php');
require_once('lib/convert_functions.php');
require_once('lib/file_functions.php');

class Station_Sync {
  /**
   * Constructor.
   *
   * Database options could be set also in general.php.
   *
   * @param dbOptions from database.php
   */
  function __construct($dbOptions) {
    $this->syncFields = general::Get('syncFields');
    $this->syncOptions = general::Get('syncOptions');
    $this->dbOptions = $dbOptions;
    $this->generalOptions = general::Get('generalOptions');

    $this->language = $this->generalOptions['language'];
    putenv("LANG=".$this->language);
    setlocale(LC_ALL,$this->language);

    $this->domain = 'messages';
    bindtextdomain($this->domain, CheckRequire('languages'));
    textdomain($this->domain);

    $this->dbConnection = new kMySQL();
    $this->xmlParser = new kXMLParser();

    if ( ($msg = $this->checkConnection()) != True) {
      logd('ERROR IN checkConnection','', $this);
      exit;
    }
    if ( $this->syncOptions['sync_debug'] == 1 ) {
      logd('Station ALIVE!');
    }
  }

  /**
   * Destructor.
   *
   * Unset the xmlParser
   */
  function __destruct () {
    if ( $this->syncOptions['sync_debug'] == 1 ) {
       logd("Station DIES\n");
    }
    unset($this->xmlParser);
  }

  /**
   * checkConnection.
   *
   * Open connection to the server
   */
  function checkConnection() {
    if ( !is_object($this->dbConnection) ) {
      $tmp = _("Can't create database connection.");
      $this->sendMail($tmp);
      return $tmp;
    }

    $this->dbConnection->OpenConnection( $this->dbOptions['server'], $this->dbOptions['username'], $this->dbOptions['password'] );

    if ( !$this->dbConnection->SelectDatabase( $this->dbOptions['database'] ) ) {
      $tmp = _("Unable to select database.");
      $this->sendMail($tmp);
      return $tmp;
    }
    return True;
  }

  function sendMail($msg, $log=1) {
    if ($log) logd('sendMail:'.$msg,'',$this);
    mail( $this->syncOptions['error_mail'], "Problems with Station", $this->syncOptions['station_name'].": ".$msg );
    print $msg . "\n\n";
  }

  /**
   * GetModificationTimes
   * @param syncTable
   * Get id and last modification times
   */
  function GetModificationTimes( $syncTable ) {
    if ( !isset( $this->syncFields[$syncTable] ) )
      return;

    if( ( $modTimes = $this->dbConnection->FetchQueryAssocAll( "SELECT id, sync_modified+0 AS sm FROM ".$syncTable ) ) !== false ) {
      $checkXML = "<".$syncTable.">";

      if ( !empty( $modTimes ) ) {
        foreach( $modTimes as $row )
        {
          $checkXML .="<row";

          foreach( $row as $field => $value )
            $checkXML .= " ".$field."=\"".$value."\"";

          $checkXML .= "/>";
        }
      }
      $checkXML .= "</".$syncTable.">";
      return $checkXML;
    }
    return false;
  }

  /**
   * GetProjectChecksums
   *
   */
  function GetProjectChecksums() {
    if ( !isset( $this->syncFields['project'] ) ) return;

    if ( empty( $this->syncFields['project'] ) )
      $checksumFields = "project.*";
    else
    {
      $checksumFields = "";

      foreach( $this->syncFields['project'] as $key => $value )
      {
        if( trim( $checksumFields ) != '' )
          $checksumFields .= ', ';

        $checksumFields .= 'project.'.$value;
      }
    }

    $query  = "SELECT project.id, SHA1( CONCAT_WS( '', ".$checksumFields.", project_tree.lft, project_tree.rght ) ) AS sc ";
    $query .= "FROM project, project_tree where project_tree.id = project.tree_id";

    if( ( $checksums = $this->dbConnection->FetchQueryAssocAll( $query ) ) !== false )
    {
      $checkXML = "<project>";

      if( !empty( $checksums ) )
      {
        foreach( $checksums as $row )
        {
          $checkXML .="<row";

          foreach( $row as $field => $value )
            $checkXML .= " ".$field."=\"".$value."\"";

          $checkXML .= "/>";
        }
      }

      $checkXML .= "</project>";

      return $checkXML;
    }

    return false;
  }

  /**
   * GetStamps
   */
  function GetStamps()
  {
    if( !isset( $this->syncFields['stamp'] ) ) return;

    if( empty( $this->syncFields['stamp'] ) )
      $stampFields = "*";
    else
      $stampFields = implode( ', ', array_keys( $this->syncFields['stamp'] ) );

    if( ( $last_modified = $this->dbConnection->FetchQueryAssoc( "SELECT MAX(modified) AS last_modified FROM stamp WHERE server_row_id IS NOT NULL" ) ) !== false )
    {
      if( isset( $last_modified['last_modified'] ) )
        $last_modified = $last_modified['last_modified'];
      else
        $last_modified = 0;

      if( ( $last_server_row_id = $this->dbConnection->FetchQueryAssoc( "SELECT MAX(server_row_id) AS last_server_row_id FROM stamp" ) ) !== false )
        if( isset( $last_server_row_id['last_server_row_id'] ) )
          $last_server_row_id = $last_server_row_id['last_server_row_id'];
        else
          $last_server_row_id = 0;

      $checkXML = "<stamp lm=\"".$last_modified."\" lsr=\"".$last_server_row_id."\">";

      if( ( $stamps = $this->dbConnection->FetchQueryAssocAll( "SELECT ".$stampFields." FROM stamp WHERE server_row_id IS NULL OR action != '' ORDER BY id" ) ) !== false )
      {
        foreach( $stamps as $row )
        {
          $checkXML .= "<row ";

          foreach( $row as $field => $value )
            $checkXML .= " ".$field."=\"".$value."\"";

          $checkXML .= "/>";

        }

        $checkXML .= "</stamp>";
	$checkXML = str_replace(array('&'),array('%26'),$checkXML);

	//$this->dbConnection->UpdateRows('stamp', array('action'=>''), array('id'=>$row['id']));
      }

      return $checkXML;
    }

    return false;
  }

  /**
   * setCurl.
   *
   * $this->cHandler is curl object which handles the data transfers.
   */
  function setCurl() {
    $this->cHandler = curl_init( $this->syncOptions['url'] );

    curl_setopt( $this->cHandler, CURLOPT_ENCODING, "gzip" );
    curl_setopt( $this->cHandler, CURLOPT_FOLLOWLOCATION, true );
    curl_setopt( $this->cHandler, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $this->cHandler, CURLOPT_SSL_VERIFYHOST, false );
    curl_setopt( $this->cHandler, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt( $this->cHandler, CURLOPT_VERBOSE, true );

    /* First we need table version information */
    curl_setopt( $this->cHandler, CURLOPT_POSTFIELDS, "query=version" );

    $this->cData = curl_exec($this->cHandler);

    if ( $this->syncOptions['sync_debug'] == 1 ) {
	logd('Version from server: '.var_export($this->cData,true));
    }

    if ( curl_errno($this->cHandler) ) {
      $tmp = _("CURL Error") . curl_error($this->cHandler);
      $this->sendMail($tmp);
      exit;
    }
  }

  /**
   * Parse xml data retrieved from server and from client. Find tables which are modified.
   * checkableTables:
   * array (
   *   0 => 'contract',
   *   1 => 'message',
   *   2 => 'stamp',
   * )
   */
  function getCheckableTables() {
    $serverVersionData = $this->xmlParser->parse( strtr( $this->cData, array( '&' => '<![CDATA[&]]>' ) ) );

    if ( !isset( $serverVersionData[0]['name'] ) ) { logd('ERROR - no serverversiondata 1'); return; }
    if ( !$serverVersionData[0]['name'] == 'version' ) { logd('ERROR - no serverversiondata 2'); return; }
    if ( !isset( $serverVersionData[0]['children'][0]['attributes'] ) ) { logd('ERROR - no serverversiondata 3'); return; }
    if ( !is_array( $serverVersionData[0]['children'][0]['attributes'] ) ) { logd('ERROR - no serverversiondata 4'); return; }

    $serverVersionData =  $serverVersionData[0]['children'][0]['attributes'];
    $clientVersionData = $this->dbConnection->FetchQueryAssoc( "SELECT * FROM version" );

    //logd('ServerVersionData:'.var_export($serverVersionData, true).' clientVersionData:'.var_export($clientVersionData,true));

    if ( !$clientVersionData ) { logd('ERROR - no serverversiondata 5'); return; }
    if ( empty( $clientVersionData )) { logd('ERROR - no serverversiondata 6'); return; }

    $this->checkableTables = array();

    foreach( $clientVersionData as $table => $version ) {
	if ( !isset( $serverVersionData[$table] ) ) {
	  logd('ERROR - no serverversiondata 7');
	  continue;
	}
	if ( $serverVersionData[$table] == $version ) {
          if ( $this->syncOptions['sync_debug'] == 1 ) {
	    logd('No sync needed - '.$table);
          }
	  continue;
	}
	$table = substr( $table, 0, strpos( $table, '_table' ) );

        if ( $this->syncOptions['sync_debug'] == 1 ) {
	  logd('Sync needed - '.$table);
        }
	if ( ( trim( $table ) != '' ) ) {
	  $this->checkableTables[] = $table;
	}
    }

    if ( !isset( $this->checkableTables ) ) {
      $tmp = _("Can't get version information.");
      $this->sendMail($tmp);
      exit();
    }

    /* We have to always check stamp information */
    if ( !in_array( 'stamp', $this->checkableTables ) ) $this->checkableTables[] = 'stamp';

    //logd('checkableTables:'.var_export($this->checkableTables,true));

  }

  /**
   * getData.
   *
   * <ul>
   * <li>get Data from all checkable tables</li>
   * <li>insert Data to this->cData???? why use same variable as for server version data?</li>
   * </ul>
   */
  function getData() {
    if (empty($this->checkableTables)) {
      logd('ERROR - no serverversiondata 9'); return;
    }
    if ( ( $currentSyncTimestamp = $this->dbConnection->FetchQueryAssoc( "SELECT value FROM general_setting WHERE name = 'sync_completed'" ) ) == false ) {
      $tmp = _("Sync timestamp not found.");
      $this->sendMail($tmp);
      logd('ERROR - no serverversiondata 10');
      return;
    }
    if( !isset( $currentSyncTimestamp['value'] ) ) {
      logd('ERROR - no serverversiondata 11'); return;
    }

    $newSyncTimestamp = date( 'YmdGis' );
    $currentSyncTimestamp = $currentSyncTimestamp['value'];

    if ( ( $this->dbConnection->Query( "UPDATE general_setting SET value = '0' WHERE name = 'sync_completed'" ) ) == false ) {
      logd('ERROR - no serverversiondata (250)'); return;
    }

    while( !is_null( $checkableTable = array_shift( $this->checkableTables ) ) ) {
      if( ( !isset( $this->syncFields[$checkableTable] ) ) && ( $checkableTable != 'hourbank' ) )
	continue;

      switch( $checkableTable ) {
      case 'hourbank' :
	$checkXML = "";
	break;
      case 'project'  :
	$checkXML = $this->GetProjectChecksums();
	break;
      case 'stamp'    :
	$checkXML = $this->GetStamps();
	break;
      default         :
	$checkXML = $this->GetModificationTimes( $checkableTable );
	break;
      }

      if( ( $checkXML !== false ) && ( trim( $checkXML ) != '' ) )
	$value = "query=".$checkableTable."&check_xml=".urlsafe_b64encode( gzcompress( $checkXML , 9 ) );
      else
	$value = "query=".$checkableTable;

      /**
       *    Get data from server.
       */
      curl_setopt( $this->cHandler, CURLOPT_POSTFIELDS, $value);
      $this->cData = curl_exec($this->cHandler);

      if ( $this->syncOptions['sync_debug'] == 1 ) {
	 logd('Data from server:'.var_export($this->cData,true));
      }

      if( curl_errno($this->cHandler) ) {
	$tmp = _("CURL Error").": ".curl_error($this->cHandler);
	$this->sendMail($tmp);
	exit();
      }

      // handleData uses this->cData
      $this->handleData($checkableTable);
    }

    if ( ( $this->dbConnection->Query( "UPDATE general_setting SET value = '1' WHERE name = 'sync_completed'" ) ) === false ) {
      $tmp = _("Can't mark update process completed.");
      $this->sendMail($tmp);
      exit();
    }
    else if ( ( $this->dbConnection->Query( "UPDATE general_setting SET value = '".$newSyncTimestamp."' WHERE name = 'sync_time'" ) ) === false ) {
      $tmp = _("Can't save update process timestamp.");
      $this->sendMail($tmp);
      exit();
    }
  }

  /**
   * @param checkableTable table which we are handling.
   */
  function handleData( $checkableTable ) {
    $xmlParser = new kXMLParser();
    $parsedData = $xmlParser->parse($this->cData);
    var_dump($this->cData);
    unset($xmlParser);

    if ( $parsedData === false ) {
      logd('ParseError (300) data:'.$this->cData.' table:'.$checkableTable); return;
    }
    if( empty( $parsedData ) ) {
      logd('ParseError (400) data:'.$this->cData.' table:'.$checkableTable); return;
    }

    foreach( $parsedData as $category ) {
      switch( $category['name'] ) {
      case 'hourbank' :
	$this->manageHourbank($category, $checkableTable);
	break;
      case 'message' :
	$this->manageMessage($category, $checkableTable);
	break;
      case 'project' :
	$this->manageProject($category, $checkableTable);
	break;
      case 'stamp' :
	$this->manageStamp($category, $checkableTable);
	break;
      case 'contract' :
      case 'employee' :
      case 'resource' :
      case 'project_buyorders' :
      case 'tag'      :
	$this->manageTags($category, $checkableTable);
	break;
      }
    }
  }

  function manageTags($category, $checkableTable) {

    if( ( !isset( $category['attributes']['version'] ) )) {
      logd('managetags (100)','',$this);
      return;
    }
    if( !is_numeric( $category['attributes']['version'] ) ) {
      logd('managetags (200)','',$this);
      return;
    }

    $tableVersion = $category['attributes']['version'];
    $success = true;

    if ( !isset( $category['children']) ) {
      logd('managetags (300)','',$this);
      return;
    }
    if ( !is_array( $category['children'] ) ) {
      logd('managetags (400)','',$this);
      return;
    }

    if ( ( $category['name'] == 'employee' ) && ( !empty( $category['children'] ) ) && ( !in_array( 'hourbank', $this->checkableTables ) ) )
      $this->checkableTables[] = 'hourbank';

    foreach( $category['children'] as $row ) {
      if ( ( isset( $row['attributes'] ) ) && ( is_array( $row['attributes'] ) ) ) {
	foreach( $row['attributes'] as $attribute => $value )
	  if( $value == "_NULL" )
	    $row['attributes'][$attribute] = null;

	if( isset( $row['attributes']['sm'] ) ) {
	  if( ( isset( $row['attributes']['id'] ) ) && ( intval( $row['attributes']['id'] ) > 0 ) && ( $row['attributes']['sm'] == 'del' ) ) {
	    if( ( $this->dbConnection->DeleteRows( $checkableTable, array( 'id' => $row['attributes']['id'] ) ) ) === false )
	      $success = false;
	    continue;
	  }
	  else {
	    $row['attributes']['sync_modified'] = $row['attributes']['sm'];
	    unset( $row['attributes']['sm'] );
	  }
	}
	if( ( $this->dbConnection->AddOrUpdateRow( $checkableTable, $row['attributes'] ) ) === false )
	  $success = false;
      }
    }
    if( $success )
      $this->dbConnection->Query( "UPDATE version SET ".$checkableTable."_table = '".$tableVersion."'" );
  }

  /**
   * This method is called from handleData.
   */
  function manageHourbank($category, $checkableTable) {
    $success = true;
    if ( ( !isset( $category['children']) )) {
      logd('manageHourbank (100)','',$this);
      return;
    }
    if ( !is_array( $category['children'] )) {
      logd('manageHourbank (200)','',$this);
      return;
    }

    foreach( $category['children'] as $row ) {
      if ( ( !isset( $row['attributes'] ) )) {
	logd('manageHourbank (300)','',$this);
	continue;
      }
      if ( !is_array( $row['attributes'] ) ) {
	logd('manageHourbank (400)','',$this);
	continue;
      }
      foreach( $row['attributes'] as $attribute => $value )
	if( $value == "_NULL" ) $row['attributes'][$attribute] = null;

      if ( !isset( $row['attributes']['id'] ) ) {
	logd('manageHourbank (500)','',$this);
	continue;
      }
      if ( empty( $row['attributes']['id'] ) ) {
	logd('manageHourbank (600)','',$this);
	continue;
      }
      if ( !isset( $row['attributes']['hb'] ) ) {
	logd('manageHourbank (700)','',$this);
	continue;
      }
      if ( ( $checkEmployee = $this->dbConnection->FetchQueryAssoc( "SELECT * FROM employee WHERE id = '".$row['attributes']['id']."'" ) ) === false ) {
	logd('manageHourbank (800)','',$this);
	continue;
      }
      if ( empty( $checkEmployee ) ) {
	logd('manageHourbank (900)','',$this);
	continue;
      }

      $query = "UPDATE employee set hourbank = '".$row['attributes']['hb']."', sync_modified = sync_modified WHERE id = '".$row['attributes']['id']."'";
      $this->dbConnection->Query($query);
    }
  }

  /**
   * this method is called from handleData.
   */
  function manageMessage($category, $checkableTable) {
    if ( !isset( $category['attributes']['version'] ) ) {
      logd('manageMessage (100)','',$this); return;
    }
    if ( !is_numeric( $category['attributes']['version'] ) ) {
      logd('manageMessage (200)','',$this); return;
    }

    $tableVersion = $category['attributes']['version'];
    $success = true;

    if ( !isset( $category['children']) ) {
      logd('manageMessage (300)','',$this); return;
    }
    if ( !is_array( $category['children'] ) ) {
      logd('manageMessage (400)','',$this); return;
    }

    foreach( $category['children'] as $row ) {
      if ( ( !isset( $row['attributes'] ) )) {
	logd('manageMessage (500)','',$this); continue;
      }
      if ( !is_array( $row['attributes'] ) ) {
	logd('manageMessage (600)','',$this); continue;
      }

      $receivers = array();

      foreach( $row['attributes'] as $attribute => $value )
	if( $value == "_NULL" )
	  $row['attributes'][$attribute] = null;

      if( isset( $row['attributes']['receivers'] ) )
	{
	  $receivers = explode( ";", $row['attributes']['receivers'] );
	  unset( $row['attributes']['receivers'] );
	}

      if( isset( $row['attributes']['sm'] ) )
	{
	  if( ( isset( $row['attributes']['id'] ) ) && ( intval( $row['attributes']['id'] ) > 0 ) && ( $row['attributes']['sm'] == 'del' ) )
	    {
	      if( ( $this->dbConnection->DeleteRows( 'message_receiver', array( 'message_id' => $row['attributes']['id'] ) ) ) === false )
		$success = false;
	      elseif( ( $this->dbConnection->DeleteRows( $checkableTable, array( 'id' => $row['attributes']['id'] ) ) ) === false )
		$success = false;

	      continue;
	    }
	  else
	    {
	      $row['attributes']['sync_modified'] = $row['attributes']['sm'];
	      unset( $row['attributes']['sm'] );
	    }
	}

      if ( ( $this->dbConnection->AddOrUpdateRow( $checkableTable, $row['attributes'] ) ) === false )
	$success = false;
      else
	{
	  if( ( is_array( $receivers ) ) && ( !empty( $receivers ) ) )
	    {
	      if( ( $this->dbConnection->DeleteRows( 'message_receiver', array( 'message_id' => $row['attributes']['id'] ) ) ) === false )
		$success = false;
	      else
		{
		  foreach( $receivers as $receiver )
		    {
		      $receiver = array( 'message_id'   => $row['attributes']['id'],
					 'employee_id'  => $receiver );

		      if( ( $this->dbConnection->AddOrUpdateRow( 'message_receiver', $receiver ) ) === false )
			$success = false;
		    }
		}
	    }
	}
    }

    if( $success )
      $this->dbConnection->Query( "UPDATE version SET ".$checkableTable."_table = '".$tableVersion."'" );
  }

  /**
   * This method is called from handleData.
   */
  function manageProject($category, $checkableTable) {
    if ( !isset( $category['attributes']['version'] ) ) {
      logd('manageProjects (100)','',$this);
      return;
    }

    if ( !is_numeric( $category['attributes']['version'] ) ) {
      logd('manageProjects (200)','',$this);
      return;
    }

    $tableVersion = $category['attributes']['version'];
    $success = true;

    if ( !isset( $category['children']) ) {
      logd('manageProjects (300)','',$this);
      return;
    }
    if ( !is_array( $category['children'] )) {
      logd('manageProjects (400)','',$this);
      return;
    }

    $projectClass = new PromidProject( $this->dbConnection );

    foreach( $category['children'] as $row ) {
      if ( ( !isset( $row['attributes'] ) )) {
	logd('manageProjects (500)','',$this); continue;
      }
      if ( !is_array( $row['attributes'] ) ) {
	logd('manageProjects (600)','',$this); continue;
      }

      foreach( $row['attributes'] as $attribute => $value )
	if( $value == "_NULL" )
	  $row['attributes'][$attribute] = null;

      if( isset( $row['attributes']['sc'] ) ) {
	if( ( isset( $row['attributes']['id'] ) ) && ( intval( $row['attributes']['id'] ) > 0 ) && ( $row['attributes']['sc'] == 'del' ) ) {
	  if( !$projectClass->RemoveProject( $row['attributes']['id'] ) )
	    $success = false;
	}
	continue;
      }

      $treeBranch = array( 'id'   => $row['attributes']['tree_id'],
			   'lft'  => $row['attributes']['lft'],
			   'rght' => $row['attributes']['rght'] );

      unset( $row['attributes']['lft'], $row['attributes']['rght'] );

      if( ( $this->dbConnection->AddOrUpdateRow( "project", $row['attributes'] ) ) === false )
	$success = false;
      elseif( ( $this->dbConnection->AddOrUpdateRow( "project_tree", $treeBranch ) ) === false )
	$success = false;
    }

    unset( $projectClass );

    if( $success )
      $this->dbConnection->Query( "UPDATE version SET ".$checkableTable."_table = '".$tableVersion."'" );
  }

  /**
   * This method is called from handleData
   */
  function manageStamp($category, $checkableTable) {
    if ( !isset( $category['attributes']['version'] ) ) {
      logd('manageStamp (100)','',$this);
      return;
    }
    if ( !is_numeric( $category['attributes']['version'] ) ) {
      logd('manageStamp (200)','',$this);
      return;
    }

    $tableVersion = $category['attributes']['version'];
    $success = true;

    if( ( isset( $category['attributes']['act'] ) ) && ( $category['attributes']['act'] == 'ask' ) ) {
      $success = false;
      array_unshift( $this->checkableTables, 'stamp' );
    }

    if ( !isset( $category['children']) ) {
      logd('manageStamp (300)','',$this);
      return;
    }
    if ( !is_array( $category['children'] )) {
      logd('manageStamp (400)','',$this);
      return;
    }

    if( ( !empty( $category['children'] ) ) && ( !in_array( 'hourbank', $this->checkableTables ) ) )
      $this->checkableTables[] = 'hourbank';

    foreach( $category['children'] as $row ) {
      if( ( isset( $row['attributes'] ) ) && ( is_array( $row['attributes'] ) ) )
	{
	  foreach( $row['attributes'] as $attribute => $value )
	    if( $value == "_NULL" )
	      $row['attributes'][$attribute] = null;

	  if( isset( $row['attributes']['act'] ) )
	    {
	      if( ( isset( $row['attributes']['server_row_id'] ) ) && ( intval( $row['attributes']['server_row_id'] ) > 0 ) && ( $row['attributes']['act'] == 'del' ) )
		{
		  if( ( $checkStamp = $this->dbConnection->FetchQueryAssoc( "SELECT * FROM stamp WHERE server_row_id = '".$row['attributes']['server_row_id']."'" ) ) !== false )
		    {
		      if( ( !empty( $checkStamp ) ) && ( ( $this->dbConnection->DeleteRows( $checkableTable, array( 'server_row_id' => $row['attributes']['server_row_id'] ) ) ) === false ) )
			$success = false;
		    }
		  else
		    $success = false;
		}

	      continue;
	    }

	  if( ( $this->dbConnection->AddOrUpdateRow( $checkableTable, $row['attributes'] ) ) === false )
	    $success = false;
	}
    }

    if( $success )
      $this->dbConnection->Query( "UPDATE version SET ".$checkableTable."_table = '".$tableVersion."'" );

  }
}

$station = new Station_sync($dbOptions);
$station->setCurl();
$station->getCheckableTables();
$station->getData();


?>
