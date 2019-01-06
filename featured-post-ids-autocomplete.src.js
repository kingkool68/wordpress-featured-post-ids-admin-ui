jQuery(document).ready(function($) {

	var $spinner = $('.js-featured-post-ids-spinner');

	function parseHtmlEnteties(str) {
		return str.replace(/&#([0-9]{1,6});/gi, function(match, numStr) {
			var num = parseInt(numStr, 10); // read num as normal number
			return String.fromCharCode(num);
		});
	}

	function getSource(request, callback) {
		var excluded_ids = [];
		$('#featured-post-ids-results input').each(function(item) {
			excluded_ids.push( $(this).val() );
		});
		$.post(
			ajaxurl,
			{
				q: request.term,
				excluded_ids: excluded_ids.join(','),
				taxonomy: $('#featured-post-ids-taxonomy').val(),
				term_id: $('#featured-post-ids-term-id').val(),
				nonce: $('#featured-post-ids-nonce').val(),
				action: 'featured-post-ids-search'
			},
			function(resp) {
				if ( resp.success ) {
					var regex = new RegExp( '(' + request.term + ')', 'gi' );
					resp.data.map(function(item) {
						item.label = parseHtmlEnteties(item.label);
						item.label = item.label.replace(regex, "<strong>$1</strong>");
						return item;
					});
				}
				callback(resp.data);
			}
		);
	}

	function selectAutocompleteItem(e, ui) {
		if ( ui.item.html ) {
			$('#featured-post-ids-results').append( ui.item.html );
		}
		e.preventDefault();
		return false;
	}

	function renderAutocompleteItem(ul, item) {
		return $('<li></li>')
			.data('item.autocomplete', item)
			.append(item.label)
			.appendTo(ul);
	}

	function handleDeleteItem(e) {
		$currentTarget = $(e.currentTarget);
		itemText = $currentTarget.parent().text();
		var goAhead = confirm( "Are you sure you want to un-feature this post?\n\n" + $.trim( itemText ) );
		if ( goAhead ) {
			$currentTarget.parents('li').remove();
		}
		e.preventDefault();
	}

	$('.js-featured-post-ids-autocomplete')
		.autocomplete({
			minChars: 2,
			source: getSource,
			select: selectAutocompleteItem,
			search: function() { $spinner.show(); },
			response: function() { $spinner.hide(); },
			focus: function(e) { e.preventDefault(); }
		})
		.data( 'ui-autocomplete' )._renderItem = renderAutocompleteItem;

	$('.js-featured-post-ids-results')
		.on('click', '.js-featured-post-ids-delete', handleDeleteItem)
		.sortable({
			axis: 'y',
			containment: '.featured-post-ids-sortable-container',
		})
		.disableSelection();
});
