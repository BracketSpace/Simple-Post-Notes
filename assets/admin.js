jQuery( function ( $ ) {

	if ( typeof inlineEditPost !== 'undefined' ) {

		var wpInlineEdit = inlineEditPost.edit;

		inlineEditPost.edit = function ( id ) {

			wpInlineEdit.apply( this, arguments );

			var postId = 0;

			if ( typeof ( id ) === 'object' ) {
				postId = parseInt( this.getId( id ), 10 );
			}

			if ( postId > 0 ) {
				var $editRow = $( '#edit-' + postId );
				// Raw note (with line breaks) printed in the column; empty when the post has no note.
				var note = $( '#spnote-raw-' + postId ).attr( 'data-note' ) || '';
				$editRow.find( 'textarea[name="spnote"]' ).val( note );
			}

		};

	}

	var bulkSubmitting = false;

	function showBulkError( $bulkRow ) {
		bulkSubmitting = false;

		$( '<p class="spnote-bulk-error notice notice-error inline"></p>' )
			.text( ( typeof spnotesAdmin !== 'undefined' && spnotesAdmin.bulkError ) || '' )
			.insertAfter( $bulkRow.find( 'textarea[name="spnote"]' ).closest( 'label' ) );
	}

	$( '#bulk_edit' ).on( 'click', function ( event ) {

		// Second pass: the note is saved, let the form submit normally.
		if ( bulkSubmitting ) {
			return;
		}

		var $button = $( this );
		var $bulkRow = $( '#bulk-edit' );
		var spnote = $bulkRow.find( 'textarea[name="spnote"]' ).val();

		// An empty bulk note never overwrites existing notes.
		if ( ! spnote || ! spnote.trim() ) {
			return;
		}

		var postIds = [];

		$bulkRow.find( '#bulk-titles-list .ntdelbutton' ).each( function () {
			var postId = parseInt( $( this ).attr( 'id' ).replace( /^_/, '' ), 10 );

			if ( postId > 0 ) {
				postIds.push( postId );
			}
		} );

		if ( ! postIds.length ) {
			return;
		}

		event.preventDefault();
		bulkSubmitting = true;

		$bulkRow.find( '.spnote-bulk-error' ).remove();

		$.ajax( {
			url   : ajaxurl,
			type  : 'POST',
			cache : false,
			data  : {
				action   : 'spnote_save_bulk_edit',
				post_ids : postIds,
				spnote   : spnote,
				nonce    : $bulkRow.find( 'input[name="spnotes_nonce"]' ).val()
			}
		} ).done( function ( response ) {
			if ( response && response.success === true ) {
				// Native click keeps the submit button's name/value in the request.
				$button[ 0 ].click();
				bulkSubmitting = false;
			} else {
				showBulkError( $bulkRow );
			}
		} ).fail( function () {
			showBulkError( $bulkRow );
		} );

	} );

} );
