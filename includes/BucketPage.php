<?php

namespace Mediawiki\Extension\Bucket;

use Article;
use MediaWiki\Extension\Bucket\Bucket;
use MediaWiki\Extension\Bucket\BucketPageHelper;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use Mediawiki\Title\Title;
use MediaWiki\Title\TitleValue;
use Wikimedia\Rdbms\SelectQueryBuilder;

class BucketPage extends Article {

	public function __construct( Title $title ) {
		parent::__construct( $title );
	}

	public function view() {
		parent::view();
		$context = $this->getContext();
		$out = $this->getContext()->getOutput();
		$out->enableOOUI();
		$out->addModuleStyles( 'ext.bucket.bucketpage.css' );
		$out->disableClientCache(); // DEBUG
		$title = $this->getTitle();
		$out->setPageTitle( $title );

		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef( DB_PRIMARY );
		$linkRenderer = MediaWikiServices::getInstance()->getLinkRenderer();

		$table_name = Bucket::getValidFieldName( $title->getRootText() );

		$duringMigration = false;

		$res = $dbw->newSelectQueryBuilder()
					->from( 'bucket_schemas' )
					->select( [ 'table_name', 'table_version', 'schema_json' ] )
					->where( [ 'table_name' => $table_name ] )
					->orderBy( 'table_version', SelectQueryBuilder::SORT_DESC )
					->caller( __METHOD__ )
					->fetchResultSet();
		$schemas = [];
		$versions = [];
		foreach ( $res as $row ) {
			if ( array_key_exists( $row->table_name, $schemas ) ) {
				$duringMigration = true;
			}
			$schemas[$row->table_name] = json_decode( $row->schema_json, true );
			$versions[$row->table_name] = $row->table_version;
		}

		$select = $context->getRequest()->getText( 'select', '*' );
		$where = $context->getRequest()->getText( 'where', '' );
		$limit = $context->getRequest()->getInt( 'limit', 20 );
		$offset = $context->getRequest()->getInt( 'offset', 0 );

		$fullResult = BucketPageHelper::runQuery( $this->getContext()->getRequest(), $table_name, $select, $where, $limit, $offset );

		if ( isset( $fullResult['error'] ) ) {
			$out->addHTML( $fullResult['error'] );
			return;
		}

		$queryResult = [];
		if ( isset( $fullResult['bucket'] ) ) {
			$queryResult = $fullResult['bucket'];
		}

		if ( $duringMigration ) {
			$out->addHTML( HTML::warningBox( 'Bucket change in progress. Data below will not be updated until the change is complete.' ) );
		}

		$resultCount = count( $queryResult );
		$endResult = $offset + $resultCount;

		$maxCount = $dbw->newSelectQueryBuilder()
			->from( Bucket::getBucketTableName( $table_name, $versions[$table_name] ) )
			->fetchRowCount();
		$out->addWikiTextAsContent( 'Bucket entries: ' . $maxCount );

		$out->addHTML( wfMessage( 'bucket-page-result-counter', $resultCount, $offset, $endResult ) );

		$specialQueryValues = $context->getRequest()->getQueryValues();
		unset( $specialQueryValues['action'] );
		unset( $specialQueryValues['title'] );
		$specialQueryValues['bucket'] = $table_name;
		$out->addHTML( ' ' );
		$out->addHTML( $linkRenderer->makeKnownLink( new TitleValue( NS_SPECIAL, 'Bucket' ), wfMessage( 'bucket-page-dive-into' ), [], $specialQueryValues ) );
		$out->addHTML( '<br>' );

		$pageLinks = BucketPageHelper::getPageLinks( $title, $limit, $offset, $context->getRequest()->getQueryValues(), ( $resultCount == $limit ) );

		$out->addHTML( $pageLinks );
		$out->addWikiTextAsContent( BucketPageHelper::getResultTable( $schemas[$table_name], $fullResult['columns'], $queryResult ) );
		$out->addHTML( $pageLinks );
	}
}
