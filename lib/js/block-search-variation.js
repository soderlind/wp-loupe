( () => {
	if ( typeof window.wp === 'undefined' ) {
		return;
	}

	const { blocks, element, hooks, components, i18n, blockEditor, editor } =
		window.wp;
	const { createElement, Fragment } = element;

	const data = window.wpLoupeBlockData || {};
	const filterable = data.filterable || {};

	/**
	 * Get post type options for SelectControl.
	 *
	 * @return {Array<{label: string, value: string}>} Post type options.
	 */
	const getPostTypeOptions = () =>
		Object.keys( filterable ).map( ( postType ) => ( {
			label: postType,
			value: postType,
		} ) );

	/**
	 * Get field options for CheckboxControl list.
	 *
	 * @param {string} postType Post type.
	 * @return {Array<{key: string, label: string}>} Field options.
	 */
	const getFieldOptions = ( postType ) => {
		const fields =
			filterable && filterable[ postType ] ? filterable[ postType ] : {};
		return Object.keys( fields ).map( ( key ) => ( {
			key,
			label:
				fields[ key ] && fields[ key ].label
					? fields[ key ].label
					: key,
		} ) );
	};

	const InspectorControls =
		( blockEditor && blockEditor.InspectorControls ) ||
		( editor && editor.InspectorControls );

	// Variation: core/search with Loupe attributes.
	if ( blocks && typeof blocks.registerBlockVariation === 'function' ) {
		blocks.registerBlockVariation( 'core/search', {
			name: 'wp-loupe-search',
			title: i18n.__( 'Loupe Search', 'wp-loupe' ),
			description: i18n.__(
				'Core search with Loupe filter settings.',
				'wp-loupe'
			),
			attributes: {
				loupeFilters: true,
				loupePostType: 'post',
				loupeFilterFields: [],
			},
			isActive( attrs ) {
				return !! attrs.loupeFilters;
			},
			scope: [ 'inserter' ],
		} );
	}

	// Inspector controls: choose post type + fields.
	hooks.addFilter(
		'editor.BlockEdit',
		'wp-loupe/search-filters',
		/**
		 * Add inspector controls to core/search when the Loupe variation is active.
		 *
		 * @param {Function} BlockEdit The original block edit component.
		 * @return {Function} Wrapped component.
		 */
		( BlockEdit ) => {
			return function WrappedBlockEdit( props ) {
				if ( ! props || props.name !== 'core/search' ) {
					return createElement( BlockEdit, props );
				}

				const attrs = props.attributes || {};
				if ( ! attrs.loupeFilters ) {
					return createElement( BlockEdit, props );
				}

				if ( ! InspectorControls ) {
					return createElement( BlockEdit, props );
				}

				const postType = attrs.loupePostType || 'post';
				const selected = Array.isArray( attrs.loupeFilterFields )
					? attrs.loupeFilterFields
					: [];
				const fieldOptions = getFieldOptions( postType );
				const postTypeOptions = getPostTypeOptions();

				/**
				 * Toggle an entry in loupeFilterFields.
				 *
				 * @param {string}  fieldKey Field key.
				 * @param {boolean} enabled  Whether the field should be enabled.
				 */
				const toggleField = ( fieldKey, enabled ) => {
					const next = selected.slice();
					const idx = next.indexOf( fieldKey );
					if ( enabled && idx === -1 ) {
						next.push( fieldKey );
					}
					if ( ! enabled && idx !== -1 ) {
						next.splice( idx, 1 );
					}
					props.setAttributes( { loupeFilterFields: next } );
				};

				return createElement(
					Fragment,
					null,
					createElement( BlockEdit, props ),
					createElement(
						InspectorControls,
						null,
						createElement(
							components.PanelBody,
							{
								title: i18n.__( 'Loupe Filters', 'wp-loupe' ),
								initialOpen: true,
							},
							createElement( components.SelectControl, {
								label: i18n.__( 'Post type', 'wp-loupe' ),
								value: postType,
								options: postTypeOptions.length
									? postTypeOptions
									: [ { label: 'post', value: 'post' } ],
								onChange( value ) {
									props.setAttributes( {
										loupePostType: value,
										loupeFilterFields: [],
									} );
								},
							} ),
							fieldOptions.length
								? fieldOptions.map( ( field ) =>
										createElement(
											components.CheckboxControl,
											{
												key: field.key,
												label: field.label,
												checked: selected.includes(
													field.key
												),
												onChange( checked ) {
													toggleField(
														field.key,
														checked
													);
												},
											}
										)
								  )
								: createElement(
										'p',
										null,
										i18n.__(
											'No filterable fields configured for this post type.',
											'wp-loupe'
										)
								  )
						)
					)
				);
			};
		}
	);
} )();
