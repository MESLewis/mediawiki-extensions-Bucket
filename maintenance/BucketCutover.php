<?php

namespace MediaWiki\Extension\Bucket;

use Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

/**
 * Performs a cutover for Bucket reads when ready.
 * This must be run for Bucket reads to see new data after editing a Bucket page.
 */
class BucketCutover extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'Bucket' );
		$this->addDescription( 'Performs a cutover for Bucket reads from the old table to a new populated table.' );
	}

	public function execute() {
		$dbw = $this->getDB( DB_PRIMARY );

		$res = $dbw->newSelectQueryBuilder()
					->from( 'bucket_schemas' )
					->select( [ 'table_name', 'table_version', 'schema_json' ] )
					->forUpdate()
					->caller( __METHOD__ )
					->fetchResultSet();
		$schemas = [];
		foreach ( $res as $row ) {
			if ( !array_key_exists( $row->table_name, $schemas ) ) {
				$schemas[$row->table_name] = [];
			}
			$schemas[$row->table_name][$row->table_version] = $row->table_version;
		}

		foreach ( $schemas as $name => $versions ) {
			if ( count( $versions ) <= 1 ) {
				continue;
			}
			$this->output( "Bucket $name has multiple versions." );
			$finalBucket = array_key_last( $versions );
			unset( $versions[$finalBucket] );

			$count = $dbw->newSelectQueryBuilder()
						->from( 'bucket_pages' )
						->select( 'COUNT(*)' )
						->where( [ 'table_name' => $name, 'table_version' => $versions ] )
						->forUpdate()
						->caller( __METHOD__ )
						->fetchField();
			if ( $count > 0 ) {
				$this->output( " There are $count entries waiting to be updated.\n" );
			} else {
				$this->output( " The earlier versions are unused. Dropping earlier versions.\n" );
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'bucket_schemas' )
					->where( [ 'table_name' => $name, 'table_version' => $versions ] )
					->caller( __METHOD__ )
					->execute();
				foreach ( $versions as $ver ) {
					$tableToDelete = 'bucket__' . $name . '__' . $ver;
					$this->output( "Dropping $tableToDelete \n" );
					$dbw->query( "DROP TABLE `$tableToDelete`;" );
					$error = $dbw->lastError();
					$this->output( $error . "\n" );
				}
			}
		}
	}
}

$maintClass = BucketCutover::class;
require_once RUN_MAINTENANCE_IF_MAIN;
