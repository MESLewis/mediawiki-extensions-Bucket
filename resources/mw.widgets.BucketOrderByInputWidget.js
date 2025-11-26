/*
 * Based on mw.widgets.UserInputWidget.js
 */
( function () {
    /**
	 * @classdesc Bucket orderBy input widget
	 *
	 * @class
	 * @extends OO.ui.TextInputWidget
	 * @mixes OO.ui.mixin.LookupElement
	 *
	 * @constructor
	 * @description Create a mw.widgets.BucketOrderByInputWidget object.
	 * @param {Object} [config] Configuration options
	 * @param {mw.Api} [config.api] API object to use, creates a default mw.Api instance if not specified
	 */
    mw.widgets.BucketOrderByInputWidget = function ( config ) {
        // Config initialization
        config = config || {};
        config.allowSuggestionsWhenEmpty = true;

        // Parent constructor
        mw.widgets.BucketOrderByInputWidget.super.call( this, Object.assign( {}, config, { 
            autocomplete: false
        }));

        // Mixin constructor
        OO.ui.mixin.LookupElement.call( this, config);

        // Properties
        this.api = config.api || new mw.Api();
        
		this.lookupMenu.$element.addClass( 'bucket-widget-menu' );
    }

    // Activate mixins
    OO.inheritClass( mw.widgets.BucketOrderByInputWidget, OO.ui.TextInputWidget );
    OO.mixinClass( mw.widgets.BucketOrderByInputWidget, OO.ui.mixin.LookupElement);

	/**
	 * Handle menu item 'choose' event, updating the text input value to the value of the clicked item.
	 *
	 * @param {OO.ui.MenuOptionWidget} item Selected item
	 */
    mw.widgets.BucketOrderByInputWidget.prototype.onLookupMenuChoose = function (item) {
        this.closeLookupMenu();
        this.setLookupsDisabled( true);
        this.setValue(item.getData());
        this.setLookupsDisabled(false);
    }

	/**
	 * @inheritdoc
	 */
    mw.widgets.BucketOrderByInputWidget.prototype.focus = function() {
        this.setLookupsDisabled(true);

        const retval = mw.widgets.BucketOrderByInputWidget.super.prototype.focus.apply(this, arguments);

        this.setLookupsDisabled(false);
        return retval;
    }

    /**
	 * @inheritdoc
	 */
    mw.widgets.BucketOrderByInputWidget.prototype.getLookupRequest = function () {
        return $.Deferred().resolve().promise();
    }

	/**
	 * Get lookup cache item from server response data.
	 *
	 * @method
	 * @param {any} response Response from server
	 * @return {Object}
	 */
    mw.widgets.BucketOrderByInputWidget.prototype.getLookupCacheDataFromResponse = function (response) {
        return {};
    }

	/**
	 * Get list of menu items from a server response.
	 *
	 * @param {Object} data Query result
	 * @return {OO.ui.MenuOptionWidget[]} Menu items
	 */
    mw.widgets.BucketOrderByInputWidget.prototype.getLookupMenuOptionsFromData = function ( data) {
        const items = [];

        var values = $("#bucket-select > input")[0].value.split(", ");
        for ( var key in values) {
            if ( values[key].indexOf(this.value) !== -1 && values[key].length > 0) {
                items.push(new OO.ui.MenuOptionWidget({
                    label: values[key],
                    data: values[key]
                }));
            }
        }
        return items;
    }

    //Attach JS to input form
    const $bucketInputSelector = $('#bucket-orderby');
    if ($bucketInputSelector.length) {
        OO.ui.infuse($bucketInputSelector);
    }
}());