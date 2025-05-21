<?php

namespace MediaWiki\Extension\Bucket;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\MalformedTitleException;

class LuaLibrary extends LibraryBase {
	public function register() {
		$lib = [
			'put' => [ $this, 'bucketPut' ],
			'get' => [ $this, 'bucketGet' ],
			'run' => [ $this, 'bucketRun' ],
		];
		return $this->getEngine()->registerInterface( __DIR__ . '/mw.ext.bucket.lua', $lib, [] );
	}

	public function bucketPut( $builder, $data ): void {
		$parserOutput = $this->getParser()->getOutput();
		$bucketPuts = $parserOutput->getExtensionData( Bucket::EXTENSION_DATA_KEY ) ?? [];
		$table_name = '';
		if ( array_key_exists( 'tableName', $builder ) ) {
			$table_name = $builder['tableName'];
		}
		$sub = $builder['subversion'];
		if ( !array_key_exists( $table_name, $bucketPuts ) ) {
			try {
				// Add the Bucket page as a "template" used on this page. This will get us linksUpdate scheduled for free when the Bucket page changes.
				$title = MediaWikiServices::getInstance()->getTitleParser()->parseTitle( $table_name, NS_BUCKET );
				$bucketPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromLinkTarget( $title );
				$parserOutput->addTemplate( $title, $bucketPage->getId(), $bucketPage->getRevisionRecord()->getId() );
			} catch ( MalformedTitleException $e ) {
				// Just ignore it, an error will be logged later
			}
			$bucketPuts[ $table_name ] = [];
		}
		$bucketPuts[ $table_name ][] = [ 'sub' => $sub, 'data' => $data ];
		$parserOutput->setExtensionData( Bucket::EXTENSION_DATA_KEY, $bucketPuts );
	}

	public function bucketRun( $data ): array {
		try {
			$data = self::convertFromLuaTable( $data );
			$ret = Bucket::runSelect( $data );
			// Create links to the Buckets we are reading from
			$backlinks = $ret[0];
			$parserOutput = $this->getParser()->getOutput();
			foreach ( $backlinks as $table_name ) {
				try {
					$title = MediaWikiServices::getInstance()->getTitleParser()->parseTitle( $table_name, NS_BUCKET );
					$parserOutput->addLink( $title );
				} catch ( MalformedTitleException $e ) {
					// Ignore it
				}
			}
			$rows = $ret[1];
			return [ self::convertToLuaTable( $rows ) ];
		} catch ( QueryException $e ) { // TODO also catch db exceptions?
			return [ 'error' => $e->getMessage() ];
		}
	}

	// Go from 0-index to 1-index.
	public function convertToLuaTable( $arr ) {
		if ( is_array( $arr ) ) {
			$luaTable = [];
			foreach ( $arr as $key => $value ) {
				if ( is_int( $key ) || is_string( $key ) ) {
					$new_key = is_int( $key ) ? $key + 1 : $key;
					$luaTable[$new_key] = self::convertToLuaTable( $value );
				}
			}
			return $luaTable;
		}
		return $arr;
	}

	// Go from 1-index to 0-index.
	public static function convertFromLuaTable( $arr ) {
		if ( is_array( $arr ) ) {
			$luaTable = [];
			foreach ( $arr as $key => $value ) {
				if ( is_int( $key ) || is_string( $key ) ) {
					$new_key = is_int( $key ) ? $key - 1 : $key;
					$luaTable[$new_key] = self::convertFromLuaTable( $value );
				}
			}
			return $luaTable;
		}
		return $arr;
	}
}
