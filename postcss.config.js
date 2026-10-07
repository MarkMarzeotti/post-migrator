module.exports = {
	plugins: [
		require( 'autoprefixer' ),
		'production' === process.env.NODE_ENV ? require( 'cssnano' ) : false,
	].filter( Boolean ),
};
