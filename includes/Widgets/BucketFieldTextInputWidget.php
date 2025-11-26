<?php

namespace MediaWiki\Extension\Bucket\Widgets;

use OOUI\TextInputWidget;

class BucketFieldTextInputWidget extends TextInputWidget {

	public function __construct( $params ) {
		return parent::__construct( $params );
	}

	protected function getJavaScriptClassName() {
		return 'mw.widgets.BucketFieldInputWidget';
	}
}
