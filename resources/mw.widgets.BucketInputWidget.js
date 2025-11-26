/*
 * Based on mw.widgets.UserInputWidget.js
 */
( function () {

    console.log("bucket input started");
    /**
	 * @classdesc Bucket name input widget
	 *
	 * @class
	 * @extends OO.ui.TextInputWidget
	 * @mixes OO.ui.mixin.LookupElement
	 *
	 * @constructor
	 * @description Create a mw.widgets.UserInputWidget object.
	 * @param {Object} [config] Configuration options
	 * @param {mw.Api} [config.api] API object to use, creates a default mw.Api instance if not specified
	 */
    mw.widgets.BucketInputWidget = function ( config ) {
        // Config initialization
        config = config || {};

        // Parent constructor
        mw.widgets.BucketInputWidget.super.call( this, Object.assign( {}, config, { autocomplete: false }));

        // Mixin constructor
        OO.ui.mixin.LookupElement.call( this, config);

        // Properties
        this.api = config.api || new mw.Api();

        // Initialization
        // TODO these classes aren't needed?
        this.$element.addClass('mw-widget-bucketInputWidget');
        this.lookupMenu.$element.addClass('mw-widget-bucketInputWidget-menu');
    }

    // Activate mixins
    OO.inheritClass( mw.widgets.BucketInputWidget, OO.ui.TextInputWidget );
    OO.mixinClass( mw.widgets.BucketInputWidget, OO.ui.mixin.LookupElement);

	/**
	 * Handle menu item 'choose' event, updating the text input value to the value of the clicked item.
	 *
	 * @param {OO.ui.MenuOptionWidget} item Selected item
	 */
    mw.widgets.BucketInputWidget.prototype.onLookupMenuChoose = function (item) {
        this.closeLookupMenu();
        console.log(this);
        this.setLookupsDisabled( true);
        this.setValue(item.getData());
        this.setLookupsDisabled(false);
    }

	/**
	 * @inheritdoc
	 */
    mw.widgets.BucketInputWidget.prototype.focus = function() {
        this.setLookupsDisabled(true);

        const retval = mw.widgets.BucketInputWidget.super.prototype.focus.apply(this, arguments);

        this.setLookupsDisabled(false);
        return retval;
    }

    /**
	 * @inheritdoc
	 */
    mw.widgets.BucketInputWidget.prototype.getLookupRequest = function () {
        console.log('bucket lookup request');
        return this.api.get( {
            action: 'query',
            list: 'allpages',
            apnamespace: 9592,
            apprefix: this.value,
            aplimit: 10
        });
    }

	/**
	 * Get lookup cache item from server response data.
	 *
	 * @method
	 * @param {any} response Response from server
	 * @return {Object}
	 */
    mw.widgets.BucketInputWidget.prototype.getLookupCacheDataFromResponse = function (response) {
        return response.query.allpages || {};
    }

	/**
	 * Get list of menu items from a server response.
	 *
	 * @param {Object} data Query result
	 * @return {OO.ui.MenuOptionWidget[]} Menu items
	 */
    mw.widgets.BucketInputWidget.prototype.getLookupMenuOptionsFromData = function ( data) {
        console.log('bucket get options from data');
        const items = [];

        for ( let i = 0, len = data.length; i < len; i++) {
            title = data[i].title;
            //Format page title into bucket name
            cleanTitle = title.split(':')[1].toLowerCase().replace(/ /g,"_");
            items.push(new OO.ui.MenuOptionWidget( {
                label: title,
                data: cleanTitle
            }));
        }
        return items;
    }

    //Attach JS to input form
    const $bucketInputSelector = $('#bucket-input');
    if ($bucketInputSelector.length) {
        OO.ui.infuse($bucketInputSelector);
    }
}());