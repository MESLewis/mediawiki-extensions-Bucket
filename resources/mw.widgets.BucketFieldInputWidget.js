/*
 * Based on mw.widgets.UserInputWidget.js
 */
( function () {
    /**
	 * @classdesc Bucket field input widget
	 *
	 * @class
	 * @extends OO.ui.TextInputWidget
	 * @mixes OO.ui.mixin.LookupElement
	 *
	 * @constructor
	 * @description Create a mw.widgets.BucketFieldInputWidget object.
	 * @param {Object} [config] Configuration options
	 * @param {mw.Api} [config.api] API object to use, creates a default mw.Api instance if not specified
	 */
    mw.widgets.BucketFieldInputWidget = function ( config ) {
        // Config initialization
        config = config || {};
        config.allowSuggestionsWhenEmpty = true;

        // Parent constructor
        mw.widgets.BucketFieldInputWidget.super.call( this, Object.assign( {}, config, { autocomplete: false }));

        // Mixin constructor
        OO.ui.mixin.LookupElement.call( this, config);

        // Properties
        this.api = config.api || new mw.Api();
    }

    // Activate mixins
    OO.inheritClass( mw.widgets.BucketFieldInputWidget, OO.ui.TextInputWidget );
    OO.mixinClass( mw.widgets.BucketFieldInputWidget, OO.ui.mixin.LookupElement);

	/**
	 * Handle menu item 'choose' event, updating the text input value to the value of the clicked item.
	 *
	 * @param {OO.ui.MenuOptionWidget} item Selected item
	 */
    mw.widgets.BucketFieldInputWidget.prototype.onLookupMenuChoose = function (item) {
        this.closeLookupMenu();
        this.setLookupsDisabled(true);

        var value = this.getValue();
        value = value.substring(0, value.lastIndexOf(", "));
        if (value == '*' || value == '') {
            value = '';
        } else {
            value = value + ', ';
        }
        this.setValue(value + item.getData() + ', ');
        this.setLookupsDisabled(false);
    }

	/**
	 * @inheritdoc
	 */
    mw.widgets.BucketFieldInputWidget.prototype.focus = function() {
        this.setLookupsDisabled(true);

        const retval = mw.widgets.BucketFieldInputWidget.super.prototype.focus.apply(this, arguments);

        this.setLookupsDisabled(false);
        return retval;
    }

    /**
	 * @inheritdoc
	 */
    mw.widgets.BucketFieldInputWidget.prototype.getLookupRequest = function () {
        var bucket = "Bucket:" + $("#bucket-input > input")[0].value;
        return this.api.get( {
            action: 'parse',
            page: bucket,
            prop: 'wikitext'
        });
    }

	/**
	 * Get lookup cache item from server response data.
	 *
	 * @method
	 * @param {any} response Response from server
	 * @return {Object}
	 */
    mw.widgets.BucketFieldInputWidget.prototype.getLookupCacheDataFromResponse = function (response) {
        return JSON.parse(response.parse.wikitext['*']) || {};
    }

	/**
	 * Get list of menu items from a server response.
	 *
	 * @param {Object} data Query result
	 * @return {OO.ui.MenuOptionWidget[]} Menu items
	 */
    mw.widgets.BucketFieldInputWidget.prototype.getLookupMenuOptionsFromData = function ( data) {
        const items = [];

        var value = this.value.split(" ").slice(-1);
        var existing = this.value.split(", ");
        data.page_name = 'page_name';
        data.page_name_sub = 'page_name_sub';
        for ( var key in data) {
            if (key.indexOf(value) !== -1 && !existing.includes(key)) {
                items.push(new OO.ui.MenuOptionWidget({
                    label: key,
                    data: key
                }));
            }
        }
        return items;
    }

    //Attach JS to input form
    const $bucketInputSelector = $('#bucket-select');
    if ($bucketInputSelector.length) {
        OO.ui.infuse($bucketInputSelector);
    }
}());