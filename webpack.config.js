const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'booking-widget': './src/booking-widget/index.js',
		'admin-dashboard': './src/admin-dashboard/index.js',
		'calendar-widget': './src/calendar-widget/index.js',
	},
	output: {
		...defaultConfig.output,
		path: require( 'path' ).resolve( __dirname, 'assets/js' ),
	},
};
