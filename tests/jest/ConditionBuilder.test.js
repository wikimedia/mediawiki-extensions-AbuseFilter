'use strict';

const { mount, flushPromises } = require( '@vue/test-utils' );
const ConditionBuilder = require( '../../modules/ext.abuseFilter.conditionBuilder/components/ConditionBuilder.vue' );

const groups = [
	{
		label: 'Operators',
		items: [
			{ value: '==', label: 'Equal to (==)' },
			{ value: '!==', label: 'Value and type not equal to (!==)' }
		]
	},
	{
		label: 'Variables',
		items: [
			{ value: 'user_name', label: 'User name (user_name)' },
			{ value: 'page_title', label: 'Page title without namespace (page_title)' }
		]
	}
];

function getWrapper( insert = jest.fn() ) {
	return mount( ConditionBuilder, {
		props: { groups, placeholder: 'Add to filter', insert }
	} );
}

describe( 'ConditionBuilder', () => {
	it( 'mounts with a lookup input', () => {
		const wrapper = getWrapper();
		expect( wrapper.find( '.cdx-lookup' ).exists() ).toBe( true );
		expect( wrapper.find( 'input' ).exists() ).toBe( true );
	} );

	it( 'filters options to those matching the typed term', async () => {
		const wrapper = getWrapper();
		const input = wrapper.find( 'input' );
		await input.setValue( 'user' );
		await input.trigger( 'input' );
		await flushPromises();

		const items = wrapper.findAll( '.cdx-menu-item:not(.cdx-menu__no-results)' );
		expect( items.length ).toBe( 1 );
		expect( items[ 0 ].text() ).toContain( 'user_name' );
	} );

	it( 'matches on the description as well as the token', async () => {
		const wrapper = getWrapper();
		const input = wrapper.find( 'input' );
		await input.setValue( 'namespace' );
		await input.trigger( 'input' );
		await flushPromises();

		const items = wrapper.findAll( '.cdx-menu-item:not(.cdx-menu__no-results)' );
		expect( items.length ).toBe( 1 );
		expect( items[ 0 ].text() ).toContain( 'page_title' );
	} );

	it( 'inserts the chosen value and clears the input', async () => {
		const insert = jest.fn();
		const wrapper = getWrapper( insert );
		const input = wrapper.find( 'input' );
		await input.setValue( 'user' );
		await input.trigger( 'input' );
		await flushPromises();

		await wrapper.find( '.cdx-menu-item' ).trigger( 'click' );
		await flushPromises();

		expect( insert ).toHaveBeenCalledWith( 'user_name' );
		expect( input.element.value ).toBe( '' );
	} );

	it( 'shows no options for a non-matching term', async () => {
		const wrapper = getWrapper();
		const input = wrapper.find( 'input' );
		await input.setValue( 'zzznotarealtoken' );
		await input.trigger( 'input' );
		await flushPromises();

		expect( wrapper.findAll( '.cdx-menu-item:not(.cdx-menu__no-results)' ).length ).toBe( 0 );
	} );
} );
