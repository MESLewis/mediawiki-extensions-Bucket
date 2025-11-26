<?php

namespace MediaWiki\Extension\Bucket;

use MediaWiki\Extension\Bucket\Widgets\BucketTextInputWidget;
use MediaWiki\Html\TemplateParser;
use MediaWiki\SpecialPage\SpecialPage;
use OOUI;

class SpecialBucket extends SpecialPage {
	private TemplateParser $templateParser;

	public function __construct() {
		parent::__construct( 'Bucket' );
		$this->templateParser = new TemplateParser( __DIR__ . '/Templates' );
	}

	/**
	 * @return string
	 * @throws OOUI\Exception
	 */
	private function getQueryBuilder( string $bucket, string $select, string $where, int $limit, int $offset, string $orderBy, string $orderByDirection ): string {
		$inputs = [];
		// Bucket
		$inputs[] = new OOUI\FieldLayout(
			new BucketTextInputWidget(
				[
					'infusable' => true,
					'name' => 'bucket',
					'value' => $bucket,
					'id' => 'bucket-input',
				]
			),
			[
				'align' => 'right',
				'label' => $this->msg( 'bucket-view-bucket-name' ),
				'help' => $this->msg( 'bucket-view-help-bucket-name' )
			]
		);
		// Select
		$inputs[] = new OOUI\FieldLayout(
			new OOUI\TextInputWidget(
				[
					'name' => 'select',
					'value' => $select,
					'id' => 'bucket-select'
				]
			),
			[
				'align' => 'right',
				'label' => $this->msg( 'bucket-view-select' ),
				'help' => $this->msg( 'bucket-view-help-select' )
			]
		);
		// Where
		$inputs[] = new OOUI\FieldLayout(
			new OOUI\MultilineTextInputWidget(
				[
					'name' => 'where',
					'value' => $where,
					'id' => 'bucket-where'
				]
			),
			[
				'align' => 'right',
				'label' => $this->msg( 'bucket-view-where' ),
				'help' => $this->msg( 'bucket-view-help-where' )
			]
		);
		// Limit
		$inputs[] = new OOUI\FieldLayout(
			new OOUI\NumberInputWidget(
				[
					'name' => 'limit',
					'value' => $limit,
					'min' => 1,
					'max' => 5000,
					'id' => 'bucket-limit'
				]
			),
			[
				'align' => 'right',
				'label' => $this->msg( 'bucket-view-limit' ),
				'help' => $this->msg( 'bucket-view-help-limit' )
			]
		);
		// Offset
		$inputs[] = new OOUI\FieldLayout(
			new OOUI\NumberInputWidget(
				[
					'name' => 'offset',
					'value' => $offset,
					'min' => 0,
					'id' => 'bucket-offset'
				]
			),
			[
				'align' => 'right',
				'label' => $this->msg( 'bucket-view-offset' ),
				'help' => $this->msg( 'bucket-view-help-offset' )
			]
		);
		// Order by
		$dropdownWidget = new OOUI\DropdownInputWidget( [
				'name' => 'orderbydir',
				'options' => [
					[ 'data' => 'asc', 'label' => 'Ascending' ],
					[ 'data' => 'desc', 'label' => 'Descending' ]
				],
				'id' => 'bucket-orderby-direction',
			] );
		if ( $orderByDirection == 'desc' ) {
			$dropdownWidget->setValue( 'desc' );
		}
		$orderByDirectionWidget = new OOUI\FieldLayout(
			$dropdownWidget,
			[
				'classes' => [ 'bucket-orderby-direction' ]
			]
		);
		$orderByWidget = new OOUI\FieldLayout(
			new OOUI\TextInputWidget(
				[
					'name' => 'orderby',
					'value' => $orderBy,
					'id' => 'bucket-orderby',
				]
			)
		);
		$inputs[] = new OOUI\FieldLayout(
			new OOUI\ButtonGroupWidget( [
			'items' => [ $orderByWidget, $orderByDirectionWidget ],
			'classes' => [ 'bucket-orderby-group' ]
			] ),
			[
				'align' => 'right',
				'label' => $this->msg( 'bucket-view-orderby' ),
				'help' => $this->msg( 'bucket-view-help-orderby' ),
				'classes' => [ 'bucket-orderby' ]
			] );
		// Submit
		$inputs[] = new OOUI\FieldLayout(
			new OOUI\ButtonInputWidget(
				[
					'type' => 'submit',
					'label' => $this->msg( 'bucket-view-submit' ),
					'align' => 'center'

				] ),
				[
					'label' => ' '
				]
		);

		$form = new OOUI\FormLayout( [
			'items' => $inputs,
			'action' => $this->getPageTitle()->getLocalURL(),
			'method' => 'get'
		] );

		return $form . '<br>';
	}

	/**
	 * @param string|null $subPage
	 * @return void
	 * @throws OOUI\Exception
	 */
	public function execute( $subPage ) {
		$request = $this->getRequest();
		$out = $this->getOutput();
		$this->setHeaders();
		$out->enableOOUI();
		$out->addModuleStyles( 'ext.bucket.bucketpage.styles' );
		$out->addModuleStyles( 'ext.bucket.specialbucket.styles' );
		$out->addModules( 'mw.widgets.BucketInputWidget' );
		$out->setPageTitle( $out->msg( 'bucket' )->text() );
		$out->addHelpLink( 'https://meta.weirdgloop.org/Extension:Bucket/Bucket browse', true );

		$bucket = $request->getText( 'bucket', '' );
		$select = $request->getText( 'select', '*' );
		$where = $request->getText( 'where', '' );
		$limit = $request->getInt( 'limit', 20 );
		$offset = $request->getInt( 'offset', 0 );
		$orderBy = $request->getText( 'orderby', '' );
		$orderByDir = $request->getText( 'orderbydir', 'asc' );

		$out->addHTML( $this->getQueryBuilder( $bucket, $select, $where, $limit, $offset, $orderBy, $orderByDir ) );

		if ( $bucket === '' ) {
			return;
		}

		try {
			$bucketName = Bucket::getValidBucketName( $bucket );
		} catch ( SchemaException ) {
			$out->addWikiTextAsContent( BucketPageHelper::printError(
				$this->msg( 'bucket-query-bucket-invalid', $bucket )->parse() ) );
			return;
		}

		$dbw = BucketDatabase::getDB();
		$res = $dbw->newSelectQueryBuilder()
			->from( 'bucket_schemas' )
			->select( [ 'bucket_name', 'schema_json' ] )
			->where( [ 'bucket_name' => $bucketName ] )
			->caller( __METHOD__ )
			->fetchResultSet();
		$schemas = [];
		foreach ( $res as $row ) {
			$schemas[$row->bucket_name] = json_decode( $row->schema_json, true );
		}

		$fullResult = BucketPageHelper::runQuery( $request, $bucket, $select, $where, $limit, $offset, $orderBy, $orderByDir );
		$queryResult = [];

		if ( isset( $fullResult['error'] ) ) {
			$out->addWikiTextAsContent( BucketPageHelper::printError( $fullResult['error'] ) );
			return;
		} elseif ( isset( $fullResult['bucket'] ) ) {
			$queryResult = $fullResult['bucket'];
		}

		$resultCount = count( $fullResult['bucket'] );
		$endResult = $offset + $resultCount;

		$html = $this->templateParser->processTemplate(
			'BucketPageView',
			[
				'resultHeaderText' => $out->msg( 'bucket-page-result-counter' )
					->numParams(
						$resultCount,
						( $offset == 0 && $endResult == 0 ) ? $offset : $offset + 1,
						$endResult
					)->parse(),
				'paginationLinks' => BucketPageHelper::getPageLinks(
					$this->getFullTitle(), $limit, $offset, $request->getQueryValues(), ( $resultCount === $limit ) ),
				'resultTable' => BucketPageHelper::getResultTable(
					$this->templateParser, $schemas[$bucketName], $fullResult['fields'], $queryResult )
			]
		);
		$out->addHTML( $html );
	}

	/**
	 * @return string
	 */
	protected function getGroupName() {
		return 'bucket';
	}
}
