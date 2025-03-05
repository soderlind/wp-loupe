<?php
// Get current tag/version
$manualVersion = getenv( 'MANUAL_VERSION' );
$version       = '';

if ( ! empty( $manualVersion ) ) {
	$version = $manualVersion;
} else {
	// Get latest tag
	$tagsOutput = shell_exec( 'git tag -l --sort=-v:refname' );
	$tags       = explode( "\n", trim( $tagsOutput ) );
	if ( ! empty( $tags[ 0 ] ) ) {
		$version = $tags[ 0 ];
	}
}

if ( empty( $version ) ) {
	echo "No version found. Exiting.\n";
	exit( 1 );
}

// Read plugin file to get metadata
$pluginFile = file_get_contents( 'wp-loupe.php' );
preg_match( '/Plugin Name:\s*(.+)$/m', $pluginFile, $pluginNameMatches );
preg_match( '/Description:\s*(.+)$/m', $pluginFile, $descriptionMatches );
preg_match( '/Author:\s*(.+)$/m', $pluginFile, $authorMatches );
preg_match( '/Author URI:\s*(.+)$/m', $pluginFile, $authorUriMatches );
preg_match( '/Plugin URI:\s*(.+)$/m', $pluginFile, $pluginUriMatches );
preg_match( '/Requires at least:\s*(.+)$/m', $pluginFile, $requiresMatches );
preg_match( '/Requires PHP:\s*(.+)$/m', $pluginFile, $requiresPhpMatches );

// Read changelog to get changes for this version
$changelog = file_get_contents( 'CHANGELOG.md' );
preg_match( '/## \[' . preg_quote( $version, '/' ) . '\][^\n]*\n\n(.*?)(?=\n## |$)/s', $changelog, $changelogMatches );

$changes = [];
if ( isset( $changelogMatches[ 1 ] ) ) {
	$sections = preg_split( '/### /', trim( $changelogMatches[ 1 ] ) );
	foreach ( $sections as $section ) {
		if ( empty( trim( $section ) ) )
			continue;

		$lines       = explode( "\n", trim( $section ) );
		$sectionName = trim( $lines[ 0 ] );
		array_shift( $lines ); // Remove section name

		$changes[ $sectionName ] = array_map( function ($line) {
			return trim( ltrim( $line, '-' ) );
		}, array_filter( $lines, function ($line) {
			return ! empty( trim( $line ) );
		} ) );
	}
}

// Construct the download URL
$downloadUrl = "https://github.com/soderlind/wp-loupe/releases/latest/download/wp-loupe.zip";

// Build info array
$info = [ 
	'name'            => isset( $pluginNameMatches[ 1 ] ) ? trim( $pluginNameMatches[ 1 ] ) : 'WP Loupe',
	'slug'            => 'wp-loupe',
	'version'         => ltrim( $version, 'v' ),
	'download_url'    => $downloadUrl,
	'tested'          => get_wordpress_tested_version(),
	'requires'        => isset( $requiresMatches[ 1 ] ) ? trim( $requiresMatches[ 1 ] ) : '5.9',
	'requires_php'    => isset( $requiresPhpMatches[ 1 ] ) ? trim( $requiresPhpMatches[ 1 ] ) : '7.4',
	'last_updated'    => date( 'Y-m-d H:i:s' ),
	'sections'        => [ 
		'description' => isset( $descriptionMatches[ 1 ] ) ? trim( $descriptionMatches[ 1 ] ) : '',
		'changelog'   => format_changelog( $changelog ),
	],
	'author'          => isset( $authorMatches[ 1 ] ) ? trim( $authorMatches[ 1 ] ) : '',
	'author_homepage' => isset( $authorUriMatches[ 1 ] ) ? trim( $authorUriMatches[ 1 ] ) : '',
	'homepage'        => isset( $pluginUriMatches[ 1 ] ) ? trim( $pluginUriMatches[ 1 ] ) : '',
];

// Save to info.json
file_put_contents( 'info.json', json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
echo "Generated info.json for version {$version}\n";

/**
 * Try to determine the latest WordPress version this was tested with
 */
function get_wordpress_tested_version() {
	$readmeContent = '';
	if ( file_exists( 'readme.txt' ) ) {
		$readmeContent = file_get_contents( 'readme.txt' );
		preg_match( '/Tested up to:\s*(.+)$/m', $readmeContent, $matches );
		if ( isset( $matches[ 1 ] ) ) {
			return trim( $matches[ 1 ] );
		}
	}

	// Default to recent version if not found
	return '6.3';
}

/**
 * Format changelog for the info.json file
 */
function format_changelog( $changelog ) {
	$formattedChangelog = '<h3>Changelog</h3>';

	preg_match_all( '/## \[(.*?)\][^\n]*\n\n(.*?)(?=\n## |$)/s', $changelog, $matches, PREG_SET_ORDER );

	foreach ( $matches as $versionBlock ) {
		$versionNumber  = $versionBlock[ 1 ];
		$versionChanges = $versionBlock[ 2 ];

		$formattedChangelog .= "<h4>{$versionNumber}</h4>";
		$formattedChangelog .= "<ul>";

		// Process each section (Added, Changed, Fixed, etc.)
		$sections = preg_split( '/### /', $versionChanges );
		foreach ( $sections as $section ) {
			if ( empty( trim( $section ) ) )
				continue;

			$lines       = explode( "\n", trim( $section ) );
			$sectionName = trim( $lines[ 0 ] );
			array_shift( $lines ); // Remove section name

			if ( ! empty( $sectionName ) ) {
				$formattedChangelog .= "<li><strong>{$sectionName}</strong><ul>";
			}

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) )
					continue;

				$formattedChangelog .= "<li>" . ltrim( $line, '- ' ) . "</li>";
			}

			if ( ! empty( $sectionName ) ) {
				$formattedChangelog .= "</ul></li>";
			}
		}

		$formattedChangelog .= "</ul>";
	}

	return $formattedChangelog;
}

/**
 * Get upgrade notice from the changelog
 */
function get_upgrade_notice( $changelog, $version ) {
	$version = ltrim( $version, 'v' );
	preg_match( '/## \[' . preg_quote( $version, '/' ) . '\][^\n]*\n\n(.*?)(?=\n## |$)/s', $changelog, $matches );

	if ( ! isset( $matches[ 1 ] ) ) {
		return '';
	}

	// Find the first few significant changes
	$changes = explode( "\n", trim( $matches[ 1 ] ) );
	$notice  = array_filter( $changes, function ($line) {
		$line = trim( $line );
		return ! empty( $line ) && strpos( $line, '### ' ) !== 0;
	} );

	// Take just a few important lines
	$notice = array_slice( $notice, 0, 3 );

	return implode( " ", array_map( function ($line) {
		return trim( ltrim( $line, '- ' ) );
	}, $notice ) );
}