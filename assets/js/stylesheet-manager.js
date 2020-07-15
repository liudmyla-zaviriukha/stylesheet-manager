/**
 * Stylesheet Manager
 */

var smPanel;

jQuery(document).ready(function ($) {

	// Bail early if we're missing the sm or smData vars. We won't get
	// very far without them.
	if ( typeof sm === 'undefined' || typeof smData === 'undefined' ) {
		return;
	}

	smPanel = {

		// Management panel element
		el : $( '#sm-panel' ),

		// Menu element in the admin bar
		menu_el : $( '#wp-admin-bar-stylesheet-manager' ),

		// Add the management panel
		init : function() {

			// Build the initial assets panel
			this.el.html(
				'<div class="column-section">' +
					'<h3>Please select styles which you want to display as inline styles in head section</h3>' +
					'<h5>* In this case all @font-face will be cut from selected assets and inserted as link tag with preload attribute</h5>' +

					'<div class="section head styles"></div>' +
					'<div class="section footer styles"></div>' +

					'<h2>' + sm.strings.inline_printed_styles +	'</h2>' +
					'<div class="section dequeued styles"></div>' +
				'</div>'
			);

			// Add assets to each section
			var self = this;
			$.each(smData.assets, function(loc_key, loc){

				// check type on first level - reject scalar values from processing
				if ($.type(loc) != 'array' && $.type(loc) != 'object'){
					return;
				}

				$.each(loc, function(type_key, type){
					$.each(type, function(key, asset){
						if( asset.handle != 'admin-bar' &&
							asset.handle != 'dashicons' &&
							asset.handle != 'stylesheet-manager'
						)
							self.appendAsset(asset, loc_key, type_key );
					});
				});
			});

			// Register open/close clicks on the whole panel
			this.menu_el.click( function() {
				smPanel.toggle();
			});

			// Remove the emergency fallback panel
			this.menu_el.find( '.inactive' ).remove();

		},

		// Add an asset to the panel
		appendAsset : function( asset, loc, type ) {

			var html = '<div class="asset handle-' + asset.handle + ' ' + type + '" data-type="' + type + '" data-handle="' + asset.handle + '" data-location="' + loc + '">' +
				'<div class="header">' +
					'<div class="handle">' + asset.handle + '</div>' +
					'<div class="src">' + asset.src + '</div>' +
				'</div>' +
				'<div class="body">';

			// Add input field for quick URL selection
			if ( typeof asset.src !== 'undefined' && asset.src.length ) {
				html += '<div class="src_input"><input type="text" value="' + asset.src + '" readonly="readonly"></div>';
			}

			// Add notices
			html += this.getAssetNotices( asset );

			// Add dependencies
			if ( typeof asset.deps !== 'undefined' && asset.deps.length ) {
				html += '<p class="deps"><strong>' + sm.strings.deps + '</strong> ' + asset.deps.join( ', ' ) + '</p>';
			}

			// Add action links
			html += '<div class="links">';

			if ( loc === 'dequeued' ) {
				html += '<input name="enqueue" type="checkbox"  class="enqueue" checked>' +
					 	'<a href="#" class="enqueue">' + sm.strings.enqueue + '</a>';
			} else {
				html += '<input name="dequeue" type="checkbox" class="dequeue">' +
						'<a href="#" class="dequeue">' + sm.strings.dequeue + '</a>';
			}

			var url = this.getAssetURL( asset );
			if ( url !== false ) {
				html += '<a href="' + url + '" target="_blank" class="view">' + sm.strings.view + '</a>';
			}

			html += '</div>'; // .links
			html += '</div>'; // .body
			html += '</div>'; // .asset

			this.el.find( '.section.' + loc + '.' + type ).append( html );

			var cur = this.el.find( '.asset.handle-' + asset.handle.replace(/\./g, "\\.") + '.' + type );


			// Register click function to select all in disabled source input field
			this.enableSrcSelect( cur.find( '.src_input input' ) );

			// Register click function to dequeue/re-enqueue asset
			cur.find( '.links .dequeue, .links .enqueue' ).click( function(e) {
				e.stopPropagation();
				e.preventDefault();

				// Bail early if we've already sent a request
				if ( $(this).hasClass( 'sending' ) ) {
					return;
				}

				$(this).addClass( 'sending' );

				var asset = $(this).parents( '.asset' );

				smPanel.toggleQueueState( asset.data( 'handle' ), asset.data( 'location' ), asset.data( 'type' ), $(this).hasClass( 'dequeue' ) );
			});

		},

		// Get a notice if one exists for this asset
		getAssetNotices : function( asset ) {

			var notices = '';

			for ( var notice in smData.notices ) {
				for ( var handle in smData.notices[notice].handles ) {
					if ( smData.notices[notice].handles[handle] === asset.handle ) {
						notices += '<p class="notice ' + notice + '">' + smData.notices[notice].msg + '</p>';
					}
				}
			}

			if ( asset.src === false ) {
				notices += '<p class="notice no-src">' + sm.strings.no_src + '</p>';
			}

			return notices;
		},

		// Try to get a good URL for this asset. This is just kind of
		// guessing, really.
		getAssetURL : function( asset ) {
			// valid asset object ?
			if (!asset || !asset.src){
				return false;
			}

			var url = asset.src.toString();
			if ( url.substring( 0, 2 ) === '//' ) {
				link = 'http:' + asset.src;
			} else if ( url.substring( 0, 1 ) === '/' ) {
				url = sm.siteurl + asset.src;
			} else if ( url.substring( 0, 4 ) === 'http' ) {
				url = asset.src;
			}

			return url;
		},

		// Open/close the panel
		toggle : function() {

			if ( this.menu_el.hasClass( 'open' ) ) {
				this.el.slideUp();
				this.el.removeClass( 'open' );
				this.menu_el.removeClass( 'open' );
			} else {
				this.el.addClass( 'open' );
				this.menu_el.addClass( 'open' );
				this.el.slideDown();
			}
		},

		// Enable the auto-selection in the source field
		enableSrcSelect : function( el ) {

			// Don't bubble up to the open/close asset toggle
			el.click( function(e) {
				e.stopPropagation();
			});

			el.click( function() {
				this.select();
			});
		},

		// Send an Ajax request to dequeue or re-enqueue an asset
		toggleQueueState : function( handle, location, type, dequeue ) {

			var asset = this.el.find( '.asset.handle-' + handle.replace(/\./g, "\\.") + '.' + type );

			asset.find( '.body .notice.request' ).remove();
			asset.find( '.body' ).append( '<p class="notice request"><span class="spinner"></span>' + sm.strings.sending + '</p>' );

			var data = $.param({
				action: 'sm-modify-asset',
				nonce: sm.nonce,
				handle: handle,
				type: type,
				dequeue: dequeue,
				asset_data: smData.assets[location][type][handle]
			});

			var jqxhr = $.post( sm.ajaxurl, data, function( r ) {

				var notice = asset.find( '.notice.request' );

				if ( r.success ) {

					// If we got a successful return but no data,
					// something's gone wonky.
					if ( typeof r.data == 'undefined' ) {
						notice.addClass( 'error' ).text( sm.strings.unknown_error );
						console.log( r );

						return;
					}

					notice.slideUp( null, function() {
						$(this).remove();
					});

					if ( r.data.dequeue ) {
						asset.fadeOut( null, function() {
							$(this).remove();
						});

						smPanel.appendAsset( r.data.option[r.data.type][r.data.handle], 'dequeued', r.data.type );

						// Add this the array of dequeued assets so
						// the data can be retrieved if they want to
						// stop dequeuing it. Ideally we'd also remove
						// the asset data from the enqueued asset arrays
						// but this will do for now.
						if ( smData.assets.dequeued === false ) {
							smData.assets.dequeued = [];
						}
						if ( typeof smData.assets.dequeued[type] === 'undefined' ) {
							smData.assets.dequeued[type] = [];
						}
						smData.assets.dequeued[type][r.data.handle] = smData.assets[location][type][handle];

					} else {
						asset.addClass( 'requeued' ).find( '.body' ).empty().append( '<p class="notice requeued">' + sm.strings.requeued + '</p>' );
					}

				} else {

					if ( typeof r.data == 'undefined' || typeof r.data.msg == 'undefined' ) {
						notice.addClass( 'error' ).text( sm.strings.unknown_error );
					} else {
						notice.addClass( 'error' ).text( r.data.msg );
					}
				}

				asset.find( '.links .sending' ).removeClass( 'sending' );
			});

		}
	};

	smPanel.init();

});
