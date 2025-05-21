<?php

namespace MediaWiki\Extension\Bucket;

use LogicException;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\SelectQueryBuilder;

class Bucket {
	public const EXTENSION_DATA_KEY = 'bucket:puts';
	public const EXTENSION_PROPERTY_KEY = 'bucketputs';
	public const MAX_LIMIT = 5000;
	public const DEFAULT_LIMIT = 500;
	public const MESSAGE_BUCKET = 'bucket_message';

	private static $dataTypes = [
		'BOOLEAN' => 'BOOLEAN',
		'DOUBLE' => 'DOUBLE',
		'INTEGER' => 'INTEGER',
		'JSON' => 'JSON',
		'TEXT' => 'TEXT',
		'PAGE' => 'TEXT'
	];

	private static $requiredColumns = [
			'_page_id' => [ 'type' => 'INTEGER', 'index' => false, 'repeated' => false ],
			'_index' => [ 'type' => 'INTEGER', 'index' => false, 'repeated' => false ],
			'page_name' => [ 'type' => 'PAGE', 'index' => true, 'repeated' => false ],
			'page_name_sub' => [ 'type' => 'PAGE', 'index' => true, 'repeated' => false ],
	];

	private static $allSchemas = [];
	private static $allVersions = [];
	private static $WHERE_OPS = [
		'='  => true,
		'!=' => true,
		'>=' => true,
		'<=' => true,
		'>'  => true,
		'<'  => true,
	];

	public static function logMessage( string $bucket, string $property, string $type, string $message, &$logs ) {
		// TODO need to create the correct bucket on plugin install
		if ( !array_key_exists( self::MESSAGE_BUCKET, $logs ) ) {
			$logs[self::MESSAGE_BUCKET] = [];
		}
		if ( $bucket != '' ) {
			$bucket = 'Bucket:' . $bucket;
		}
		$logs[self::MESSAGE_BUCKET][] = [
			'sub' => '',
			'data' => [
				'bucket' => $bucket,
				'property' => $property,
				'type' => wfMessage( $type ),
				'message' => $message
			]
		];
	}

	/*
	Called when a page is saved containing a bucket.put
	*/
	public static function writePuts( int $pageId, string $titleText, array $puts, bool $writingLogs = false ) {
		$logs = [];
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );

		$res = $dbw->newSelectQueryBuilder()
				->from( 'bucket_pages' )
				->select( [ '_page_id', 'table_name', 'put_hash' ] )
				->forUpdate()
				->where( [ '_page_id' => $pageId ] )
				->caller( __METHOD__ )
				->fetchResultSet();
		$bucket_hash = [];
		foreach ( $res as $row ) {
			$bucket_hash[ $row->table_name ] = $row->put_hash;
		}

		// Combine existing written bucket list and new written bucket list.
		$relevantBuckets = array_merge( array_keys( $puts ), array_keys( $bucket_hash ) );
		$res = $dbw->newSelectQueryBuilder()
				->from( 'bucket_schemas' )
				->select( [ 'table_name', 'table_version', 'schema_json' ] )
				->lockInShareMode()
				->where( [ 'table_name' => $relevantBuckets ] )
				->orderBy( 'table_version', SelectQueryBuilder::SORT_ASC ) // We want $versions to end up having the highest version in it
				->caller( __METHOD__ )
				->fetchResultSet();
		$schemas = [];
		$versions = [];
		foreach ( $res as $row ) {
			$schemas[$row->table_name] = json_decode( $row->schema_json, true );
			$versions[$row->table_name] = $row->table_version;
		}

		foreach ( $puts as $tableName => $tableData ) {
			if ( $tableName == '' ) {
				self::logMessage( $tableName, '', 'bucket-general-error', wfMessage( 'bucket-no-bucket-defined-warning' ), $logs );
				continue;
			}

			$tableNameTmp = self::getValidFieldName( $tableName );
			if ( $tableNameTmp == false ) {
				self::logMessage( $tableName, '', 'bucket-general-warning', wfMessage( 'bucket-invalid-name-warning', $tableName ), $logs );
				continue;
			}
			if ( $tableNameTmp != $tableName ) {
				self::logMessage( $tableName, '', 'bucket-general-warning', wfMessage( 'bucket-capital-name-warning' ), $logs );
			}
			$tableName = $tableNameTmp;

			if ( !array_key_exists( $tableName, $schemas ) ) {
				self::logMessage( $tableName, '', 'bucket-general-error', wfMessage( 'bucket-no-exist-error' ), $logs );
				continue;
			}

			$tablePuts = [];
			$dbTableName = 'bucket__' . $tableName . '__' . $versions[$tableName];
			$res = $dbw->newSelectQueryBuilder()
				->from( $dbw->addIdentifierQuotes( $dbTableName ) )
				->select( '*' )
				->forUpdate()
				->where( [ '_page_id' => $pageId ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			$fields = [];
			$fieldNames = $res->getFieldNames();
			foreach ( $fieldNames as $fieldName ) {
				// TODO: match on type, not just existence
				$fields[ $fieldName ] = true;
			}
			foreach ( $tableData as $idx => $singleData ) {
				$sub = $singleData['sub'];
				$singleData = $singleData['data'];
				if ( gettype( $singleData ) != 'array' ) {
					self::logMessage( $tableName, '', 'bucket-general-error', wfMessage( 'bucket-put-syntax-error' ), $logs );
					continue;
				}
				foreach ( $singleData as $key => $value ) {
					if ( !isset( $fields[$key] ) || !$fields[$key] ) {
						self::logMessage( $tableName, $key, 'bucket-general-warning', wfMessage( 'bucket-put-key-missing-warning', $key, $tableName ), $logs );
					}
				}
				$singlePut = [];
				foreach ( $fields as $key => $_ ) {
					$value = isset( $singleData[$key] ) ? $singleData[$key] : null;
					# TODO JSON relies on forcing utf8 transmission in DatabaseMySQL.php line 829
					$singlePut[$dbw->addIdentifierQuotes( $key )] = self::castToDbType( $value, self::getDbType( $fieldName, $schemas[$tableName][$key] ) );
				}
				$singlePut[$dbw->addIdentifierQuotes( '_page_id' )] = $pageId;
				$singlePut[$dbw->addIdentifierQuotes( '_index' )] = $idx;
				$singlePut[$dbw->addIdentifierQuotes( 'page_name' )] = $titleText;
				$singlePut[$dbw->addIdentifierQuotes( 'page_name_sub' )] = $titleText;
				if ( isset( $sub ) && strlen( $sub ) > 0 ) {
					$singlePut[$dbw->addIdentifierQuotes( 'page_name_sub' )] = $titleText . '#' . $sub;
				}
				$tablePuts[$idx] = $singlePut;
			}

			# Check these puts against the hash of the last time we did puts.
			sort( $tablePuts );
			sort( $schemas[$tableName] );
			$newHash = hash( 'sha256', json_encode( $tablePuts ) . json_encode( $schemas[$tableName] ) . $versions[$tableName] );
			if ( isset( $bucket_hash[ $tableName ] ) && $bucket_hash[ $tableName ] == $newHash ) {
				unset( $bucket_hash[ $tableName ] );
				continue;
			}

			// Remove the bucket_hash entry so we can it as a list of removed buckets at the end.
			unset( $bucket_hash[ $tableName ] );

			// TODO: does behavior here depend on DBO_TRX?
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( $dbw->addIdentifierQuotes( $dbTableName ) )
				->where( [ '_page_id' => $pageId ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->newInsertQueryBuilder()
				->insert( $dbw->addIdentifierQuotes( $dbTableName ) )
				->rows( $tablePuts )
				->caller( __METHOD__ )
				->execute();
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'bucket_pages' )
				->where( [ '_page_id' => $pageId, 'table_name' => $tableName ] ) // Purposefully delete all table_versions
				->caller( __METHOD__ )
				->execute();
			$dbw->newInsertQueryBuilder()
				->insert( 'bucket_pages' )
				->rows( [ '_page_id' => $pageId, 'table_name' => $tableName, 'table_version' => $versions[$tableName], 'put_hash' => $newHash ] )
				->caller( __METHOD__ )
				->execute();
		}

		if ( !$writingLogs ) {
			// Clean up bucket_pages entries for buckets that are no longer written to on this page.
			$tablesToDelete = array_keys( array_filter( $bucket_hash ) );
			if ( count( $logs ) != 0 ) {
				unset( $tablesToDelete[self::MESSAGE_BUCKET] );
			} else {
				$tablesToDelete[] = self::MESSAGE_BUCKET;
			}

			if ( count( $tablesToDelete ) > 0 ) {
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( 'bucket_pages' )
					->where( [ '_page_id' => $pageId, 'table_name' => $tablesToDelete ] )
					->caller( __METHOD__ )
					->execute();
				foreach ( $tablesToDelete as $name ) {
					$dbw->newDeleteQueryBuilder()
						->deleteFrom( $dbw->addIdentifierQuotes( 'bucket__' . $name . '__' . $versions[$name] ) )
						->where( [ '_page_id' => $pageId ] )
						->caller( __METHOD__ )
						->execute();
				}
			}

			if ( count( $logs ) > 0 ) {
				self::writePuts( $pageId, $titleText, $logs, true );
			}
		}
	}

	/**
	 * Called for any page save that doesn't have bucket puts
	 */
	public static function clearOrphanedData( int $pageId ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );

		// Check if any buckets are storing data for this page
		$res = $dbw->newSelectQueryBuilder()
				->from( 'bucket_pages' )
				->select( [ 'table_name' ] )
				->forUpdate()
				->where( [ '_page_id' => $pageId ] )
				->groupBy( 'table_name' )
				->caller( __METHOD__ )
				->fetchResultSet();

		// If there is data associated with this page, delete it.
		if ( $res->count() > 0 ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'bucket_pages' )
				->where( [ '_page_id' => $pageId ] )
				->caller( __METHOD__ )
				->execute();
			$table = [];
			$versions = [];
			foreach ( $res as $row ) {
				$table[] = $row->table_name;
				$versions[$row->table_name] = $row->table_version;
			}

			foreach ( $table as $name ) {
				// Clear this pages data from the bucket
				$dbw->newDeleteQueryBuilder()
					->deleteFrom( $dbw->addIdentifierQuotes( 'bucket__' . $name . '__' . $versions[$name] ) )
					->where( [ '_page_id' => $pageId ] )
					->caller( __METHOD__ )
					->execute();
			}
		}
	}

	public static function getValidFieldName( string $fieldName ) {
		if ( preg_match( '/^[a-zA-Z0-9_ ]+$/', $fieldName ) ) {
			return str_replace( ' ', '_', strtolower( trim( $fieldName ) ) );
		}
		return false;
	}

	private static function getValidBucketName( string $bucketName ) {
		if ( ucfirst( $bucketName ) != ucfirst( strtolower( $bucketName ) ) ) {
			throw new SchemaException( wfMessage( 'bucket-capital-name-error' ) );
		}
		$bucketName = self::getValidFieldName( $bucketName );
		if ( !$bucketName ) {
			throw new SchemaException( wfMessage( 'bucket-invalid-name-warning', $bucketName ) );
		}
		return $bucketName;
	}

	public static function canCreateTable( string $bucketName ) {
		$bucketName = self::getValidBucketName( $bucketName );
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );
		$schema = $dbw->newSelectQueryBuilder()
			->from( 'bucket_schemas' )
			->where( [ 'table_name' => $bucketName ] )
			->forUpdate()
			->caller( __METHOD__ )
			->field( 'schema_json' )
			->fetchField();
		// TODO also check if bucket__$bucketName exists
		if ( !$schema ) {
			return true;
		} else {
			return false;
		}
	}

	public static function createOrModifyTable( string $bucketName, object $jsonSchema, int $parentId ) {
		$newSchema = array_merge( [], self::$requiredColumns );

		if ( empty( (array)$jsonSchema ) ) {
			throw new SchemaException( wfMessage( 'bucket-schema-no-columns-error' ) );
		}

		$bucketName = self::getValidBucketName( $bucketName );

		foreach ( $jsonSchema as $fieldName => $fieldData ) {
			if ( gettype( $fieldName ) !== 'string' ) {
				throw new SchemaException( wfMessage( 'bucket-schema-must-be-strings', $fieldName ) );
			}

			$lcFieldName = self::getValidFieldName( $fieldName );
			if ( !$lcFieldName ) {
				throw new SchemaException( wfMessage( 'bucket-schema-invalid-field-name', $fieldName ) );
			}

			$lcFieldName = strtolower( $fieldName );
			if ( isset( $newSchema[$lcFieldName] ) ) {
				throw new SchemaException( wfMessage( 'bucket-schema-duplicated-field-name', $fieldName ) );
			}

			if ( !isset( self::$dataTypes[$fieldData->type] ) ) {
				throw new SchemaException( wfMessage( 'bucket-schema-invalid-data-type', $fieldName, $fieldData->type ) );
			}

			$index = true;
			if ( isset( $fieldData->index ) ) {
				$index = boolval( $fieldData->index );
			}

			$repeated = false;
			if ( isset( $fieldData->repeated ) ) {
				$repeated = boolval( $fieldData->repeated );
			}

			$newSchema[$lcFieldName] = [ 'type' => $fieldData->type, 'index' => $index, 'repeated' => $repeated ];
		}
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );

		$dbw->onTransactionCommitOrIdle( function () use ( $dbw, $newSchema, $bucketName, $parentId ) {
			$row = $dbw->newSelectQueryBuilder()
				->table( 'bucket_schemas' )
				->select( [ 'table_version', 'schema_json' ] )
				->where( [ 'table_name' => $bucketName ] )
				->orderBy( 'table_version', SelectQueryBuilder::SORT_DESC )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchResultSet()
				->fetchObject();
			$tableVersion = $row->table_version;
			$oldSchema = $row->schema_json;
			if ( $tableVersion == null ) {
				$tableVersion = 0;
			} else {
				$tableVersion += 1;
			}

			if ( $oldSchema != null ) {
				$newSchemaString = json_encode( $newSchema );
				if ( $oldSchema == $newSchemaString ) {
					return; // If the schema didn't change we don't need a new table.
				}
			}

			$dbTableName = 'bucket__' . $bucketName . '__' . $tableVersion;
			$statement = self::getCreateTableStatement( $dbTableName, $newSchema );
			file_put_contents( MW_INSTALL_PATH . '/cook.txt', "CREATE TABLE STATEMENT $statement \n", FILE_APPEND );
			$dbw->query( $statement );

			$dbw->newInsertQueryBuilder()
				->table( 'bucket_schemas' )
				->rows( [
					'table_name' => $bucketName,
					'table_version' => $tableVersion,
					'schema_json' => json_encode( $newSchema )
				] )
				->caller( __METHOD__ )
				->execute();
		}, __METHOD__ );
	}

	public static function deleteTable( $bucketName ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );
		$bucketName = self::getValidBucketName( $bucketName );
		$tableVersion = $dbw->newSelectQueryBuilder()
			->table( 'bucket_schemas' )
			->select( 'table_version' )
			->where( [ 'table_name' => $bucketName ] )
			->caller( __METHOD__ )
			->fetchField();
		$tableName = 'bucket__' . $bucketName . '__' . $tableVersion;

		if ( self::canDeleteBucketPage( $bucketName ) ) {
			$dbw->newDeleteQueryBuilder()
				->table( 'bucket_schemas' )
				->where( [ 'table_name' => $bucketName ] )
				->caller( __METHOD__ )
				->execute();
			$dbw->query( "DROP TABLE IF EXISTS $tableName" );
		} else {
			// TODO: Throw error?
		}
	}

	public static function canDeleteBucketPage( $bucketName ) {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );
		$bucketName = self::getValidBucketName( $bucketName );
		$putCount = $dbw->newSelectQueryBuilder()
						->table( 'bucket_pages' )
						->lockInShareMode()
						->where( [ 'table_name' => $bucketName ] )
						->fetchRowCount();
		if ( $putCount > 0 ) {
			return false;
		}
		return true;
	}

	public static function isBucketWithPuts( $cleanBucketName, IDatabase $dbw ) {
		return $dbw->newSelectQueryBuilder()
			->table( 'bucket_pages' )
			->lockInShareMode()
			->where( [ 'table_name' => $cleanBucketName ] )
			->fetchRowCount() !== 0;
	}

	/**
	 * @param string $fieldName
	 * @param array $fieldData
	 * @return string
	 */
	private static function getDbType( string $fieldName, array $fieldData ) {
		if ( isset( self::$requiredColumns[$fieldName] ) ) {
			return self::$dataTypes[self::$requiredColumns[$fieldName]['type']];
		} else {
			if ( isset( $fieldData['repeated'] ) && strlen( $fieldData['repeated'] ) > 0 ) {
				return 'JSON';
			} else {
				return self::$dataTypes[self::$dataTypes[$fieldData['type']]];
			}
		}
		return 'TEXT';
	}

	/**
	 * @param string $fieldName
	 * @param array $fieldData
	 * @return string
	 */
	private static function getIndexStatement( string $fieldName, array $fieldData ) {
		switch ( self::getDbType( $fieldName, $fieldData ) ) {
			case 'JSON':
				$fieldData['repeated'] = false;
				$subType = self::getDbType( $fieldName, $fieldData );
				switch ( $subType ) {
					case 'TEXT':
						$subType = 'CHAR(255)';
						break;
					case 'INTEGER':
						$subType = 'DECIMAL';
						break;
					case 'DOUBLE': // CAST doesn't have a double type
						$subType = 'CHAR(255)';
						break;
					case 'BOOLEAN':
						$subType = 'CHAR(255)'; // CAST doesn't have a boolean option
						break;
				}
				return "INDEX `$fieldName`((CAST(`$fieldName` AS $subType ARRAY)))";
			case 'TEXT':
			case 'PAGE':
				return "INDEX `$fieldName`(`$fieldName`(255))";
			default:
				return "INDEX `$fieldName` (`$fieldName`)";
		}
	}

	private static function getCreateTableStatement( $dbTableName, $newSchema ) {
		$createTableFragments = [];

		foreach ( $newSchema as $fieldName => $fieldData ) {
			$dbType = self::getDbType( $fieldName, $fieldData );
			$createTableFragments[] = "`$fieldName` {$dbType}";
			if ( $fieldData['index'] ) {
				$createTableFragments[] = self::getIndexStatement( $fieldName, $fieldData );
			}
		}
		$createTableFragments[] = 'PRIMARY KEY (`_page_id`, `_index`)';

		return "CREATE TABLE $dbTableName (" . implode( ', ', $createTableFragments ) . ');';
	}

	public static function castToDbType( $value, $type ) {
		if ( $type === 'TEXT' || $type === 'PAGE' ) {
			if ( $value == '' ) {
				return null;
			} else {
				return $value;
			}
		} elseif ( $type === 'DOUBLE' ) {
			return floatval( $value );
		} elseif ( $type === 'INTEGER' ) {
			return intval( $value );
		} elseif ( $type === 'BOOLEAN' ) {
			return boolval( $value );
		} elseif ( $type === 'JSON' ) {
			if ( !is_array( $value ) ) {
				if ( $value == '' ) {
					return null;
				} else {
					return json_encode( [ $value ] ); // Wrap single values in an array for compatability
				}
			} else {
				// Remove empty strings
				$value = array_filter( $value, static function ( $v ) {
					return $v != '';
				} );
				if ( count( $value ) > 0 ) {
					return json_encode( LuaLibrary::convertFromLuaTable( $value ) );
				} else {
					return null;
				}
			}
		}
	}

	public static function cast( $value, $fieldData ) {
		$type = $fieldData['type'];
		if ( $fieldData['repeated'] ) {
			$ret = [];
			$fieldData['repeated'] = false;
			if ( $value == null ) {
				$value = '';
			}
			$jsonData = json_decode( $value, true );
			if ( !is_array( $jsonData ) ) { // If we are in a repeated field but only holding a scalar, make it an array anyway.
				$jsonData = [ $jsonData ];
			}
			foreach ( $jsonData as $subVal ) {
				$ret[] = self::cast( $subVal, $fieldData );
			}
			return $ret;
		} elseif ( $type === 'TEXT' || $type === 'PAGE' ) {
			return $value;
		} elseif ( $type === 'DOUBLE' ) {
			return floatval( $value );
		} elseif ( $type === 'INTEGER' ) {
			return intval( $value );
		} elseif ( $type === 'BOOLEAN' ) {
			return boolval( $value );
		}
	}

	public static function sanitizeColumnName( $column, $fieldNamesToTables, $schemas, $tableName = null ) {
		if ( !is_string( $column ) ) {
			throw new QueryException( wfMessage( 'bucket-query-column-interpret-error', $column ) );
		}
		// Category column names are specially handled
		if ( self::isCategory( $column ) ) {
			$tableName = 'category';
			$columnName = explode( ':', $column )[1];
			$tmp = self::getSelectDbName( $tableName );
			return [
				'fullName' => "`$tmp`.`$columnName`",
				'tableName' => $tableName,
				'columnName' => $columnName,
				'schema' => [
					'type' => 'BOOLEAN',
					'index' => false,
					'repeated' => false
				]
			];
		}
		$parts = explode( '.', $column );
		if ( $column === '' || count( $parts ) > 2 ) {
			throw new QueryException( wfMessage( 'bucket-query-column-name-invalid', $column ) );
		}
		$columnNameTemp = end( $parts );
		$columnName = self::getValidFieldName( $columnNameTemp );
		if ( !$columnName ) {
			throw new QueryException( wfMessage( 'bucket-query-column-name-invalid', $columnNameTemp ) );
		}
		if ( count( $parts ) === 1 ) {
			if ( !isset( $fieldNamesToTables[$columnName] ) ) {
				throw new QueryException( wfMessage( 'bucket-query-column-not-found', $columnName ) );
			}
			if ( $tableName === null ) {
				$tableOptions = $fieldNamesToTables[$columnName];
				if ( count( $tableOptions ) > 1 ) {
					throw new QueryException( wfMessage( 'bucket-query-column-ambiguous', $columnName ) );
				}
				$tableName = array_keys( $tableOptions )[0];
			}
		} elseif ( count( $parts ) === 2 ) {
			$columnTableName = self::getValidFieldName( $parts[0] );
			if ( !$columnTableName ) {
				throw new QueryException( wfMessage( 'bucket-invalid-name-warning', $parts[0] ) );
			}
			if ( $tableName !== null && $columnTableName !== $tableName ) {
				throw new QueryException( wfMessage( 'bucket-query-bucket-invalid', $parts[0] ) );
			}
			$tableName = $columnTableName;
		}
		if ( !isset( $schemas[$tableName] ) ) {
			throw new QueryException( wfMessage( 'bucket-query-bucket-not-found', $tableName ) );
		}
		if ( !isset( $schemas[$tableName][$columnName] ) ) {
			throw new QueryException( wfMessage( 'bucket-query-column-not-found-in-bucket', $columnName, $tableName ) );
		}
		$tmp = self::getSelectDbName( $tableName );
		return [
			'fullName' => "`$tmp`.`$columnName`",
			'tableName' => $tableName,
			'columnName' => $columnName,
			'schema' => $schemas[$tableName][$columnName]
		];
	}

	public static function isNot( $condition ) {
		return is_array( $condition )
		&& isset( $condition['op'] )
		&& $condition['op'] == 'NOT'
		&& isset( $condition['operand'] );
	}

	public static function isOrAnd( $condition ) {
		return is_array( $condition )
		&& isset( $condition['op'] )
		&& ( $condition['op'] === 'OR' || $condition['op'] === 'AND' )
		&& isset( $condition['operands'] )
		&& is_array( $condition['operands'] );
	}

	public static function isCategory( $columnName ) {
		return substr( strtolower( trim( $columnName ) ), 0, 9 ) == 'category:';
	}

	/**
	 *  $condition is an array of members:
	 * 		operands -> Array of $conditions
	 * 		(optional)op -> AND | OR | NOT
	 * 		unnamed -> scalar value or array of scalar values
	 */
	public static function getWhereCondition( $condition, $fieldNamesToTables, $schemas, $dbw, &$categoryJoins ) {
		if ( self::isOrAnd( $condition ) ) {
			if ( empty( $condition['operands'] ) ) {
				throw new QueryException( wfMessage( 'bucket-query-where-missing-cond', json_encode( $condition ) ) );
			}
			$children = [];
			foreach ( $condition['operands'] as $key => $operand ) {
				if ( $key != 'op' ) { // the key 'op' will never be a valid condition on its own.
					if ( !isset( $operand['op'] ) && isset( $condition['op'] ) && isset( $operand[0] ) && is_array( $operand[0] ) && count( $operand[0] ) > 0 ) {
						$operand['op'] = $condition['op']; // Set child op to parent
					}
					$children[] = self::getWhereCondition( $operand, $fieldNamesToTables, $schemas, $dbw, $categoryJoins );
				}
			}
			$children = implode( " {$condition['op']} ", $children );
			return "($children)";
		} elseif ( self::isNot( $condition ) ) {
			$child = self::getWhereCondition( $condition['operand'], $fieldNamesToTables, $schemas, $dbw, $categoryJoins );
			return "(NOT $child)";
		} elseif ( is_array( $condition ) && is_array( $condition[0] ) ) {
			// .where{{"a", ">", 0}, {"b", "=", "5"}})
			return self::getWhereCondition( [ 'op' => isset( $condition[ 'op' ] ) ? $condition[ 'op' ] : 'AND', 'operands' => $condition ], $fieldNamesToTables, $schemas, $dbw, $categoryJoins );
		} elseif ( is_array( $condition ) && !empty( $condition ) && !isset( $condition[0] ) ) {
			// .where({a = 1, b = 2})
			$operands = [];
			foreach ( $condition as $key => $value ) {
				$operands[] = [ $key, '=', $value ];
			}
			return self::getWhereCondition( [ 'op' => 'AND', 'operands' => $operands ], $fieldNamesToTables, $schemas, $dbw, $categoryJoins );
		} elseif ( is_array( $condition ) && isset( $condition[0] ) && isset( $condition[1] ) ) {
			if ( count( $condition ) === 2 ) {
				$condition = [ $condition[0], '=', $condition[1] ];
			}
			$columnNameData = self::sanitizeColumnName( $condition[0], $fieldNamesToTables, $schemas );
			if ( !isset( self::$WHERE_OPS[$condition[1]] ) ) {
				throw new QueryException( wfMessage( 'bucket-query-where-invalid-op', $condition[1] ) );
			}
			$op = $condition[1];
			$value = $condition[2];

			$columnName = $columnNameData['fullName'];
			$columnData = $fieldNamesToTables[$columnNameData['columnName']][$columnNameData['tableName']];
			if ( $value == '&&NULL&&' ) {
				if ( $op == '!=' ) {
					return "($columnName IS NOT NULL)";
				}
				// TODO if op is something other than equals throw warning?
				return "($columnName IS NULL)";
			} elseif ( $columnData['repeated'] == true ) {
				if ( !is_numeric( $value ) ) {
					$value = '"' . $dbw->strencode( $value ) . '"';
				}
				if ( $op == '=' ) {
					return "$value MEMBER OF($columnName)";
				}
				if ( $op == '!=' ) {
					return "NOT $value MEMBER OF($columnName)";
				}
				// > < >= <=
				//TODO this is very expensive
				$columnData['repeated'] = false; // Set repeated to false to get the underlying type
				$dbType = self::getDbType( $columnName, $columnData );
				// We have to reverse the direction of < > <= >= because SQL requires this condition to be $value $op $column
				//and user input is in order $column $op $value
				$op = strtr( $op, [ '<' => '>', '>' => '<' ] );
				return "($value $op ANY(SELECT json_col FROM JSON_TABLE($columnName, '$[*]' COLUMNS(json_col $dbType PATH '$')) AS json_tab))";
			} else {
				if ( is_numeric( $value ) ) {
					return "($columnName $op $value)";
				} elseif ( is_string( $value ) ) {
					// TODO: really don't like this
					$value = $dbw->strencode( $value );
					return "($columnName $op \"$value\")";
				}
			}
		} elseif ( is_string( $condition ) && self::isCategory( $condition ) || ( is_array( $condition ) && self::isCategory( $condition[0] ) ) ) {
			if ( is_array( $condition ) ) {
				$condition = $condition[0];
			}
			$categoryName = explode( ':', $condition )[1];
			$categoryJoins[$categoryName] = $condition;
			return "(`$condition`.cl_to IS NOT NULL)";
		}
		throw new QueryException( wfMessage( 'bucket-query-where-confused', json_encode( $condition ) ) );
	}

	public static function getSelectDbName( $bucketName ): string {
		return 'bucket__' . $bucketName . '__' . self::$allVersions[$bucketName];
	}

	public static function runSelect( $data ) {
		$SELECTS = [];
		$LEFT_JOINS = [];
		$TABLES = [];
		$WHERES = [];
		$OPTIONS = [];
		// check to see if any duplicates
		$tableNames = [];
		$categoryJoins = [];
		$bucketBacklinks = [];

		$primaryTableName = self::getValidFieldName( $data['tableName'] );
		if ( !$primaryTableName ) {
			throw new QueryException( wfMessage( 'bucket-invalid-name-warning', $data['tableName'] ) );
		}
		$tableNames[ $primaryTableName ] = true;

		foreach ( $data['joins'] as $join ) {
			$tableName = self::getValidFieldName( $join['tableName'] );
			if ( !$tableName ) {
				throw new QueryException( wfMessage( 'bucket-invalid-name-warning', $join['tableName'] ) );
			}
			if ( isset( $tableNames[$tableName] ) ) {
				throw new QueryException( wfMessage( 'bucket-select-duplicate-join', $tableName ) );
			}
			$tableNames[$tableName] = true;
			$join['tableName'] = $tableName;
		}

		$tableNamesList = array_keys( $tableNames );
		foreach ( $tableNames as $tableName => $val ) {
			if ( isset( self::$allSchemas[$tableName] ) && self::$allSchemas[$tableName]
			  && isset( self::$allVersions[$tableName] ) && self::$allVersions[$tableName] ) {
				unset( $tableNames[$tableName] );
			}
		}

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );
		$missingTableNames = array_keys( $tableNames );
		if ( !empty( $missingTableNames ) ) {
			$res = $dbw->newSelectQueryBuilder()
				->from( 'bucket_schemas' )
				->select( [ 'table_name', 'table_version', 'schema_json' ] )
				->lockInShareMode()
				->where( [ 'table_name' => $missingTableNames ] )
				->orderBy( 'table_version', SelectQueryBuilder::SORT_DESC ) // Order so that we end up with the lowest number being saved as our version, since we are reading
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $res as $row ) {
				self::$allSchemas[$row->table_name] = json_decode( $row->schema_json, true );
				self::$allVersions[$row->table_name] = $row->table_version;
			}
		}
		foreach ( $tableNamesList as $tableName ) {
			if ( !array_key_exists( $tableName, self::$allSchemas ) || !self::$allSchemas[$tableName] ) {
				throw new QueryException( wfMessage( 'bucket-no-exist', $tableName ) );
			} else {
				$bucketBacklinks[] = $tableName;
			}
		}

		$TABLES[self::getSelectDbName( $primaryTableName )] = self::getSelectDbName( $primaryTableName );

		$schemas = [];
		foreach ( $tableNamesList as $tableName ) {
			$schemas[$tableName] = self::$allSchemas[$tableName];
		}

		$fieldNamesToTables = [];
		foreach ( $schemas as $tableName => $schema ) {
			foreach ( $schema as $fieldName => $fieldData ) {
				if ( substr( $fieldName, 0, 1 ) !== '_' ) {
					if ( !isset( $fieldNamesToTables[$fieldName] ) ) {
						$fieldNamesToTables[$fieldName] = [];
					}
					$fieldNamesToTables[$fieldName][$tableName] = $fieldData;
				}
			}
		}

		$ungroupedColumns = [];
		foreach ( $data['selects'] as $selectColumn ) {
			if ( self::isCategory( $selectColumn ) ) {
				$SELECTS[$selectColumn] = "{$dbw->addIdentifierQuotes($selectColumn)}.`cl_to` IS NOT NULL";
				$categoryName = explode( ':', $selectColumn )[1];
				$categoryJoins[$categoryName] = $selectColumn;
				continue;
			} else {
				// TODO: don't like this
				$selectColumn = strtolower( trim( $selectColumn ) );
				$selectTableName = null;
				// If we don't have a period then we must be the primary column.
				if ( count( explode( '.', $selectColumn ) ) == 1 ) {
					$selectTableName = $primaryTableName;
				}

				$colData = self::sanitizeColumnName( $selectColumn, $fieldNamesToTables, $schemas, $selectTableName );

				if ( $colData['tableName'] != $primaryTableName ) {
					$SELECTS[$selectColumn] = 'JSON_ARRAY(' . $colData['fullName'] . ')';
				} else {
					$SELECTS[$selectColumn] = $colData['fullName'];
				}
			}
			$ungroupedColumns[$dbw->addIdentifierQuotes( $selectColumn )] = true;
		}

		if ( !empty( $data['wheres']['operands'] ) ) {
			$WHERES[] = self::getWhereCondition( $data['wheres'], $fieldNamesToTables, $schemas, $dbw, $categoryJoins );
		}

		if ( !empty( $categoryJoins ) ) {

			foreach ( $categoryJoins as $categoryName => $alias ) {
				$TABLES[$alias] = 'categorylinks';
				$tmp = self::getSelectDbName( $primaryTableName );
				$LEFT_JOINS[$alias] = [
					"`$alias`.cl_from = `$tmp`.`_page_id`", // Must be all in one string to avoid the table name being treated as a string value.
					"`$alias`.cl_to" => str_replace( ' ', '_', $categoryName )
				];
			}
		}

		foreach ( $data['joins'] as $join ) {
			if ( !is_array( $join['cond'] ) || !count( $join['cond'] ) == 2 ) {
				throw new QueryException( wfMessage( 'bucket-query-invalid-join', json_encode( $join ) ) );
			}
			$leftField = self::sanitizeColumnName( $join['cond'][0], $fieldNamesToTables, $schemas );
			$isLeftRepeated = $leftField['schema']['repeated'];
			$rightField = self::sanitizeColumnName( $join['cond'][1], $fieldNamesToTables, $schemas );
			$isRightRepeated = $rightField['schema']['repeated'];

			if ( $isLeftRepeated && $isRightRepeated ) {
				throw new QueryException( wfMessage( 'bucket-invalid-join-two-repeated', $leftField['fullName'], $rightField['fullName'] ) );
			}

			if ( $isLeftRepeated || $isRightRepeated ) {
				// Make the left field the repeated one just for consistency.
				if ( $isRightRepeated ) {
					$tmp = $leftField;
					$isTmp = $isLeftRepeated;
					$leftField = $rightField;
					$isLeftRepeated = $isRightRepeated;
					$rightField = $tmp;
					$isRightRepeated = $isTmp;
				}

				$LEFT_JOINS[self::getSelectDbName( $join['tableName'] )] = [
					"{$rightField['fullName']} MEMBER OF({$leftField['fullName']})"
				];
			} else {
				$LEFT_JOINS[self::getSelectDbName( $join['tableName'] )] = [
					"{$leftField['fullName']} = {$rightField['fullName']}"
				];
			}
			$TABLES[self::getSelectDbName( $join['tableName'] )] = self::getSelectDbName( $join['tableName'] );
		}

		$OPTIONS['GROUP BY'] = array_keys( $ungroupedColumns );

		$OPTIONS['LIMIT'] = self::DEFAULT_LIMIT;
		if ( isset( $data['limit'] ) && is_int( $data['limit'] ) && $data['limit'] >= 0 ) {
			$OPTIONS['LIMIT'] = min( $data['limit'], self::MAX_LIMIT );
		}

		$OPTIONS['OFFSET'] = 0;
		if ( isset( $data['offset'] ) && is_int( $data['offset'] ) && $data['offset'] >= 0 ) {
			$OPTIONS['OFFSET'] = $data['offset'];
		}

		$rows = [];
		$tmp = $dbw->newSelectQueryBuilder()
			->from( self::getSelectDbName( $primaryTableName ) )
			->select( $SELECTS )
			->where( $WHERES )
			->options( $OPTIONS )
			->caller( __METHOD__ )
			->setMaxExecutionTime( 500 );
		// TODO should probably be all in a single join call? IDK.
		foreach ( $LEFT_JOINS as $alias => $conds ) {
			$tmp->leftJoin( $TABLES[$alias], $alias, $conds );
		}
		if ( isset( $data['orderBy'] ) ) {
			$orderName = self::sanitizeColumnName( $data['orderBy']['fieldName'], $fieldNamesToTables, $schemas )['fullName'];
			if ( $orderName != false ) {
				$tmp->orderBy( $orderName, $data['orderBy']['direction'] );
			} else {
				// TODO throw warning
			}
		}
		file_put_contents( MW_INSTALL_PATH . '/cook.txt', 'Query: ' . print_r( $tmp->getSQL(), true ) . "\n", FILE_APPEND );
		$res = $tmp->fetchResultSet();
		foreach ( $res as $row ) {
			$row = (array)$row;
			foreach ( $row as $columnName => $value ) {
				$defaultTableName = null;
				// If we don't have a period in the column name it must be a primary table column.
				if ( count( explode( '.', $columnName ) ) == 1 ) {
					$defaultTableName = $primaryTableName;
				}
				$schema = self::sanitizeColumnName( $columnName, $fieldNamesToTables, $schemas, $defaultTableName )['schema'];
				$row[$columnName] = self::cast( $value, $schema );
			}
			$rows[] = $row;
		}
		return [ $bucketBacklinks, $rows ];
	}
}

class SchemaException extends LogicException {
	function __construct( $msg ) {
		file_put_contents( MW_INSTALL_PATH . '/cook.txt', 'SCHEMA EXCEPTION ' . print_r( $msg, true ) . "\n", FILE_APPEND );
		parent::__construct( $msg );
	}
}

class QueryException extends LogicException {
	function __construct( $msg ) {
		file_put_contents( MW_INSTALL_PATH . '/cook.txt', 'QUERY EXCEPTION ' . print_r( $msg, true ) . "\n", FILE_APPEND );
		parent::__construct( $msg );
	}
}
