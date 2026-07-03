<template>
	<cdx-field :is-fieldset="false" :hide-label="true">
		<template #label>
			{{ placeholder }}
		</template>
		<cdx-lookup
			v-model:selected="selected"
			v-model:input-value="inputValue"
			:menu-items="menuItems"
			:menu-config="menuConfig"
			:placeholder="placeholder"
			@update:input-value="onInput"
			@update:selected="onSelect"
		></cdx-lookup>
	</cdx-field>
</template>

<script>
const { defineComponent, ref } = require( 'vue' );
const { CdxField, CdxLookup } = require( '../codex.js' );

// Lowercasing is locale-aware so Turkish dotted and dotless i match correctly.
const pageLanguage = document.documentElement.lang || undefined;

module.exports = exports = defineComponent( {
	name: 'ConditionBuilder',
	components: { CdxField, CdxLookup },
	props: {
		groups: { type: Array, required: true },
		placeholder: { type: String, required: true },
		insert: { type: Function, required: true }
	},
	setup( props ) {
		const selected = ref( null );
		const inputValue = ref( '' );
		const menuItems = ref( props.groups );
		const menuConfig = { visibleItemLimit: 10 };

		function onInput( value ) {
			const term = value.trim().toLocaleLowerCase( pageLanguage );
			if ( !term ) {
				menuItems.value = props.groups;
				return;
			}
			menuItems.value = props.groups.map( ( group ) => ( {
				label: group.label,
				items: group.items.filter(
					( item ) => item.value.toLocaleLowerCase( pageLanguage ).includes( term ) ||
						item.label.toLocaleLowerCase( pageLanguage ).includes( term )
				)
			} ) ).filter( ( group ) => group.items.length > 0 );
		}

		function onSelect( value ) {
			if ( value === null ) {
				return;
			}
			props.insert( value );
			selected.value = null;
			inputValue.value = '';
			menuItems.value = props.groups;
		}

		return { selected, inputValue, menuItems, menuConfig, onInput, onSelect };
	}
} );
</script>
