/**
 * BLOCK: Complianz Documents block
 *
 * Registering the Complianz Terms & Conditions document block with Gutenberg.
 */
import * as api from './utils/api';
import { useState, useEffect, Fragment } from '@wordpress/element';

const { __ } = wp.i18n;
const { registerBlockType } = wp.blocks;
const { InspectorControls, useBlockProps } = wp.blockEditor;
const { SelectControl, PanelBody, PanelRow, Icon } = wp.components;
const el = wp.element.createElement;

/**
 * Set custom Complianz icon.
 */
const iconEl = () => (
	<Icon icon={
		<svg id="uuid-098657ec-4091-4c5d-b2f8-761b37dc9655" xmlns="http://www.w3.org/2000/svg"
			 viewBox="0 0 22 19.26">
			<path className="uuid-fb9dd603-42c0-4281-8513-899f01887edf"
				  d="m13.54,4.47c-.06-.06-.15-.06-.21,0l-4.9,4.9.21.21,4.9-4.9c.06-.06.06-.16,0-.21h0Zm-4.48,3.19c-.06-.06-.15-.06-.21,0l-1.06,1.06.21.21,1.06-1.06c.06-.06.06-.15,0-.21ZM15.14,1.21c.46,0,.9.19,1.22.53.61.66.56,1.7-.08,2.34l-2.89,2.89c-.06.06-.06.15,0,.21.06.06.15.06.21,0l2.89-2.89c.76-.76.8-2.05.04-2.81-.38-.38-.88-.57-1.39-.57s-1,.19-1.39.57l-6.6,6.6.21.21L13.96,1.7c.31-.32.73-.49,1.17-.49h0Zm-5.64,3.46c-.06-.06-.15-.06-.21,0l-2.77,2.78.21.21,2.78-2.78c.06-.06.06-.15,0-.21h0Zm.74-.52l3.09-3.09c.48-.48,1.13-.75,1.81-.75.52,0,1.01.16,1.43.44.06.04.14.04.19-.02.07-.07.06-.18-.02-.23-.48-.33-1.04-.49-1.6-.49-.73,0-1.47.28-2.03.84l-3.09,3.1c-.06.06-.06.15,0,.21.06.06.16.06.21,0h0Zm2.63,3.56c-.06-.06-.15-.06-.21,0l-2.3,2.3c-.06.06-.06.15,0,.21h0c.06.06.15.06.21,0l2.3-2.3c.06-.06.06-.15,0-.21h0Zm3.46-2.39l-5.97,5.97.21.21,5.97-5.97c.06-.06.06-.15,0-.21-.06-.06-.16-.06-.22,0Zm1.2-4.03c-.05-.08-.17-.09-.23-.03-.05.05-.06.13-.02.19.28.42.43.91.43,1.42,0,.64-.23,1.24-.65,1.71-.06.06-.06.15,0,.21.06.06.16.06.22,0,.88-.98.97-2.43.25-3.5h0Zm-3.24,2.66l.93-.93c.06-.06.06-.15,0-.21-.06-.06-.15-.06-.21,0l-.93.93c-.06.06-.06.15,0,.21.06.06.16.06.21,0h0Zm1.59-1.84c-.2-.19-.47-.29-.73-.29s-.54.1-.75.31l-4.8,4.81c-.06.06-.06.15,0,.21.06.06.15.06.21,0l4.78-4.79c.12-.12.28-.21.46-.24.24-.03.47.05.63.21.14.14.22.33.22.53s-.08.39-.22.53l-6.6,6.6.21.21,6.57-6.57c.42-.42.44-1.13.01-1.54h0Z"/>
			<g>
				<rect className="uuid-fb9dd603-42c0-4281-8513-899f01887edf" x="7.81" y="5.93" width="2.91" height="5.64"
					  transform="translate(-3.47 9.11) rotate(-45)"/>
				<path className="uuid-fb9dd603-42c0-4281-8513-899f01887edf"
					  d="m10.7,11.35l-4.62-4.62h0l-1.28-1.28c-.55-.55-1.27-.83-1.99-.83s-1.44.27-1.99.83c-1.1,1.1-1.1,2.89,0,3.99l3.54,3.54h0l1.6,1.6c.06.06.15.06.21,0,0,0,0,0,0,0s0,0,0,0l.36-.36.03.88s0,.04.01.06c0,.02.02.04.03.05.06.06.15.06.21,0h0s0-.01,0-.01l3.82-3.82h0s.04-.04.04-.04Z"/>
				<path className="uuid-fb9dd603-42c0-4281-8513-899f01887edf"
					  d="m11.09,10.9l4.62-4.62h0l1.28-1.28c.55-.55.83-1.27.83-1.99s-.27-1.44-.83-1.99c-1.1-1.1-2.89-1.1-3.99,0l-3.54,3.54h0l-1.6,1.6c-.06.06-.06.15,0,.21,0,0,0,0,0,0s0,0,0,0l.36.36-.88.03s-.04,0-.06.01c-.02,0-.04.02-.05.03-.06.06-.06.15,0,.21h0s0,.01,0,.01l3.82,3.82h0s.04.04.04.04Z"/>
			</g>
		</svg>

	} />
);

const selectDocument = ( { attributes, setAttributes } ) => {
	const blockProps = useBlockProps();
	const [ documents, setDocuments ] = useState( {} );
	const [ documentSyncStatus, setDocumentSyncStatus ] = useState( attributes.documentSyncStatus );
	const [ customDocumentHtml, setCustomDocumentHtml ] = useState( '' );

	useEffect( () => {
		api.getDocument().then( ( response ) => {
			const documentData = response.data;
			setDocuments( documentData );

			let tempHtml = '';
			if ( documentData ) {
				tempHtml = documentData.content;
			}
			if ( attributes.customDocument && attributes.customDocument.length > 0 ) {
				tempHtml = attributes.customDocument;
			}
			setCustomDocumentHtml( tempHtml );
		} );
	}, [] );

	const onChangeCustomDocument = ( value ) => {
		setAttributes( { customDocument: value } );
	};

	const onChangeSelectDocumentSyncStatus = ( value ) => {
		setDocumentSyncStatus( value );
		setAttributes( { documentSyncStatus: value } );
		setCustomDocumentHtml( documents.content );
		setAttributes( { customDocument: documents.content } );
	};

	let output = __( 'Loading...', 'complianz-terms-conditions' );
	let id = 'document-title';
	const documentStatusOptions = [
		{ value: 'sync',   label: __( 'Synchronize document with Complianz', 'complianz-terms-conditions' ) },
		{ value: 'unlink', label: __( 'Edit document and stop synchronization', 'complianz-terms-conditions' ) },
	];

	// Show static preview image in the block inserter.
	if ( attributes.preview ) {
		return (
			<img alt="preview" src={ complianztc.cmplz_tc_preview } />
		);
	}

	// Populate output once documents have loaded.
	if ( documents && documents.hasOwnProperty( 'title' ) ) {
		output = documents.content;
		id     = attributes.selectedDocument;
	}

	// Sidebar controls — rendered unconditionally with API version 3.
	const inspectorControls = (
		<InspectorControls>
			<PanelBody title={ __( 'Document settings', 'complianz-terms-conditions' ) } initialOpen={ true }>
				<PanelRow>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						onChange={ onChangeSelectDocumentSyncStatus }
						value={ attributes.documentSyncStatus }
						label={ __( 'Document sync status', 'complianz-terms-conditions' ) }
						options={ documentStatusOptions }
					/>
				</PanelRow>
			</PanelBody>
		</InspectorControls>
	);

	if ( 'sync' === documentSyncStatus ) {
		return (
			<Fragment>
				{ inspectorControls }
				<div key={ id } { ...blockProps } dangerouslySetInnerHTML={ { __html: output } }></div>
			</Fragment>
		);
	}

	return (
		<Fragment>
			{ inspectorControls }
			<div
				{ ...blockProps }
				contentEditable={ true }
				onInput={ ( e ) => onChangeCustomDocument( e.currentTarget.innerHTML ) }
				dangerouslySetInnerHTML={ { __html: customDocumentHtml } }
			></div>
		</Fragment>
	);
};

/**
 * Register the Complianz Terms & Conditions Gutenberg block.
 *
 * @link https://wordpress.org/gutenberg/handbook/block-api/
 * @param  {string}   name     Block name.
 * @param  {Object}   settings Block settings.
 * @return {?WPBlock}          The block, if successfully registered; otherwise undefined.
 */
registerBlockType( 'complianztc/terms-conditions', {
	apiVersion: 3,
	title: __( 'Legal document - Complianz Terms & conditions', 'complianz-terms-conditions' ),
	icon: iconEl,
	category: 'widgets',
	example: {
		attributes: {
			preview: true,
		},
	},
	keywords: [
		__( 'Terms & conditions', 'complianz-terms-conditions' ),
	],
	attributes: {
		documentSyncStatus: {
			type: 'string',
			default: 'sync',
		},
		customDocument: {
			type: 'string',
			default: '',
		},
		content: {
			type: 'string',
			source: 'html',
			selector: 'p',
		},
		document: {
			type: 'array',
		},
		preview: {
			type: 'boolean',
			default: false,
		},
	},

	/**
	 * The edit function describes the block in the editor context.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/block-api/block-edit-save/
	 */
	edit: selectDocument,

	/**
	 * The save function returns null because rendering is handled server-side via PHP.
	 *
	 * @link https://wordpress.org/gutenberg/handbook/block-api/block-edit-save/
	 */
	save: function() {
		// Rendering in PHP.
		return null;
	},
} );

/**
 * Editor placeholder for the withdrawal form block.
 *
 * The form is fully determined by the generator configuration and rendered
 * server-side, so the editor shows a static placeholder rather than a live
 * preview.
 */
const withdrawalForm = () => {
	const blockProps = useBlockProps();
	return (
		<div { ...blockProps }>
			<p><strong>{ __( 'Complianz — Withdrawal form', 'complianz-terms-conditions' ) }</strong></p>
			<p>{ __( 'The withdrawal form is rendered here on the front end, using your Terms & Conditions settings.', 'complianz-terms-conditions' ) }</p>
		</div>
	);
};

/**
 * Register the Complianz withdrawal form Gutenberg block.
 *
 * Server-side rendered (save returns null); output is identical to the
 * [cmplz-tc-withdrawal-form] shortcode.
 */
registerBlockType( 'complianztc/withdrawal-form', {
	apiVersion: 3,
	title: __( 'Withdrawal form - Complianz Terms & conditions', 'complianz-terms-conditions' ),
	icon: iconEl,
	category: 'widgets',
	keywords: [
		__( 'Withdrawal form', 'complianz-terms-conditions' ),
		__( 'Terms & conditions', 'complianz-terms-conditions' ),
	],
	supports: {
		html: false,
	},
	edit: withdrawalForm,
	save: function() {
		// Rendering in PHP.
		return null;
	},
} );
