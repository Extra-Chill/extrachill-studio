/**
 * Webpack configuration for Extra Chill Studio.
 *
 * Extends the default wp-scripts config to add the standalone Roadie
 * floating chat entry alongside the auto-discovered block entries.
 *
 * Block entries (src/blocks/studio/) are auto-discovered by wp-scripts
 * from block.json. The Roadie entry is appended manually.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		...( typeof defaultConfig.entry === 'function'
			? defaultConfig.entry()
			: defaultConfig.entry ),
		roadie: './src/roadie/index.ts',
	},
};
