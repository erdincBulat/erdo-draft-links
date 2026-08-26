const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		sidebar: path.resolve( __dirname, 'assets/js/src/sidebar/index.js' ),
		classic: path.resolve( __dirname, 'assets/js/src/classic/admin-classic.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/js/build' ),
	},
};
