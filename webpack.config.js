const path = require( 'path' );

module.exports = ( env, argv ) => {
	const isProd = 'production' === argv.mode;

	return {
		entry: {
			admin: './src/js/admin/script.js',
		},
		output: {
			path: path.resolve( __dirname, 'dist/js' ),
			filename: '[name].js',
		},
		devtool: isProd ? 'source-map' : 'eval-source-map',
		mode: isProd ? 'production' : 'development',
		module: {
			rules: [
				{
					test: /\.jsx?$/,
					exclude: /node_modules/,
					use: [ 'babel-loader' ],
				},
			],
		},
		resolve: {
			extensions: [ '.js', '.jsx' ],
		},
	};
};
