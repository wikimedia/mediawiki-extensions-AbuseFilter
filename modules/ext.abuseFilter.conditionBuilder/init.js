const Vue = require( 'vue' );
const ConditionBuilder = require( './components/ConditionBuilder.vue' );

// The picker reads its options from the fallback dropdown and inserts by
// selecting through it, so edit.js keeps handling the actual insert.
// Wait for document ready so the form exists and edit.js is set up first.
$( () => {
	const select = document.getElementById( 'wpFilterBuilder' );
	const mountPoint = document.querySelector( '.mw-abusefilter-condition-builder' );

	if ( !select || !mountPoint ) {
		return;
	}

	const groups = Array.from( select.querySelectorAll( 'optgroup' ) ).map( ( optgroup ) => ( {
		label: optgroup.label,
		items: Array.from( optgroup.children ).map( ( option ) => ( {
			value: option.value,
			label: option.textContent
		} ) )
	} ) );

	Vue.createMwApp( ConditionBuilder, {
		groups,
		placeholder: select.options[ 0 ].textContent,
		insert: ( value ) => {
			select.value = value;
			select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	} ).mount( mountPoint );
} );
