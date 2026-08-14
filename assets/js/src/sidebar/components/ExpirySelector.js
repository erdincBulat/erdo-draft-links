import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function ExpirySelector( { value, options, onChange } ) {
	return (
		<SelectControl
			label={ __( 'Link Expiry', 'erdo-draft-links' ) }
			value={ value }
			options={ options }
			onChange={ onChange }
		/>
	);
}
