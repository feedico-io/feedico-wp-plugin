( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor || wp.editor;
	const { PanelBody, TextControl, ToggleControl } = wp.components;

	const clampPerPage = function ( v ) {
		const n = parseInt( v, 10 );
		if ( isNaN( n ) ) {
			return 24;
		}
		return Math.max( 1, Math.min( 100, n ) );
	};

	registerBlockType( 'feedico/merchants', {
		apiVersion: 2,
		title: 'Feedico merchants',
		icon: 'store',
		category: 'widgets',
		attributes: {
			perPage: { type: 'integer', default: 24 },
		},
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: 'feedico-block-edit-note' } );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Feedico', initialOpen: true },
						el( TextControl, {
							label: 'Per page',
							type: 'number',
							min: 1,
							max: 100,
							value: props.attributes.perPage,
							onChange: function ( v ) {
								props.setAttributes( { perPage: clampPerPage( v ) } );
							},
						} )
					)
				),
				el( 'p', { style: { margin: 0 } }, '[feedico_merchants] — ', props.attributes.perPage, ' / page' )
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'feedico/coupons', {
		apiVersion: 2,
		title: 'Feedico coupons',
		icon: 'tickets-alt',
		category: 'widgets',
		attributes: {
			merchantId: { type: 'string', default: '' },
			perPage: { type: 'integer', default: 24 },
			showSearch: { type: 'boolean', default: false },
			wrapOuter: { type: 'boolean', default: true },
		},
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: 'feedico-block-edit-note' } );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Feedico', initialOpen: true },
						el( TextControl, {
							label: 'Merchant ID (optional)',
							value: props.attributes.merchantId,
							onChange: function ( v ) {
								props.setAttributes( { merchantId: ( v || '' ).trim() } );
							},
						} ),
						el( TextControl, {
							label: 'Per page',
							type: 'number',
							min: 1,
							max: 100,
							value: props.attributes.perPage,
							onChange: function ( v ) {
								props.setAttributes( { perPage: clampPerPage( v ) } );
							},
						} ),
						el( ToggleControl, {
							label: 'Outer wrapper',
							checked: props.attributes.wrapOuter,
							onChange: function ( v ) {
								props.setAttributes( { wrapOuter: !! v } );
							},
						} ),
						el( ToggleControl, {
							label: 'Search form',
							checked: props.attributes.showSearch,
							onChange: function ( v ) {
								props.setAttributes( { showSearch: !! v } );
							},
						} )
					)
				),
				el(
					'p',
					{ style: { margin: 0 } },
					'[feedico_coupons]',
					props.attributes.merchantId ? ' merchant_id="' + props.attributes.merchantId + '"' : ''
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	registerBlockType( 'feedico/merchant-page', {
		apiVersion: 2,
		title: 'Feedico merchant page',
		icon: 'id-alt',
		category: 'widgets',
		attributes: {
			merchantId: { type: 'string', default: '' },
			perPage: { type: 'integer', default: 24 },
			showSearch: { type: 'boolean', default: false },
			showHero: { type: 'boolean', default: true },
		},
		edit: function ( props ) {
			const blockProps = useBlockProps( { className: 'feedico-block-edit-note' } );
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Feedico', initialOpen: true },
						el( TextControl, {
							label: 'Merchant ID',
							help: 'Internal Feedico merchant id from the merchants table.',
							value: props.attributes.merchantId,
							onChange: function ( v ) {
								props.setAttributes( { merchantId: ( v || '' ).trim() } );
							},
						} ),
						el( TextControl, {
							label: 'Per page (coupon list)',
							type: 'number',
							min: 1,
							max: 100,
							value: props.attributes.perPage,
							onChange: function ( v ) {
								props.setAttributes( { perPage: clampPerPage( v ) } );
							},
						} ),
						el( ToggleControl, {
							label: 'Show hero',
							checked: props.attributes.showHero,
							onChange: function ( v ) {
								props.setAttributes( { showHero: !! v } );
							},
						} ),
						el( ToggleControl, {
							label: 'Coupon search form',
							checked: props.attributes.showSearch,
							onChange: function ( v ) {
								props.setAttributes( { showSearch: !! v } );
							},
						} )
					)
				),
				el(
					'p',
					{ style: { margin: 0 } },
					'[feedico_merchant_page merchant_id="',
					props.attributes.merchantId || '…',
					'"]'
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
