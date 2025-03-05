<?php
// Get current tag/version
$manualVersion = getenv( 'MANUAL_VERSION' );
$githubRef     = getenv( 'GITHUB_REF' );
$githubEvent   = getenv( 'GITHUB_EVENT_NAME' );
$version       = '';

echo "Starting info.json generation...\n";
echo "Event type: " . ( $githubEvent ?: "Not specified" ) . "\n";

// Check for version from workflow_dispatch input
if ( ! empty( $manualVersion ) ) {
	$version = $manualVersion;
	echo "Using manually specified version: {$version}\n";
	// Check for version from GitHub release event (refs/tags/v1.2.3)
} elseif ( ! empty( $githubRef ) && strpos( $githubRef, 'refs/tags/' ) === 0 ) {
	$version = str_replace( 'refs/tags/', '', $githubRef );
	echo "Found version from GitHub reference: {$version}\n";
} else {
	// For release events, we should always have a tag reference
	if ( $githubEvent === 'release' ) {
		echo "Error: This is a release event but no tag reference was found.\n";
		echo "GITHUB_REF: " . ( $githubRef ?: "empty" ) . "\n";
		echo "This should not happen during a normal GitHub release. Exiting.\n";
		exit( 1 );
	}

	// Get latest tag as fallback for non-release events
	echo "No version specified, attempting to use latest git tag...\n";
	$tagsOutput = shell_exec( 'git tag -l --sort=-v:refname' );
	$tags       = explode( "\n", trim( $tagsOutput ) );
	if ( ! empty( $tags[ 0 ] ) ) {
		$version = $tags[ 0 ];
		echo "Using latest git tag: {$version}\n";
	}
}

if ( empty( $version ) ) {
	echo "Error: No version found. Exiting.\n";
	exit( 1 );
}

// Verify this is a valid version format
if ( ! preg_match( '/^v?\d+\.\d+(\.\d+)?(-[a-zA-Z0-9.-]+)?$/', $version ) ) {
	echo "Error: Invalid version format '{$version}'. Must match pattern v1.2.3 or 1.2.3. Exiting.\n";
	exit( 1 );
}

// Print confirmation of version being used
echo "Proceeding with version: {$version}\n";

// Read plugin file to get metadata
$pluginFile = file_get_contents( 'wp-loupe.php' );
preg_match( '/Plugin Name:\s*(.+)$/m', $pluginFile, $pluginNameMatches );
preg_match( '/Description:\s*(.+)$/m', $pluginFile, $descriptionMatches );
preg_match( '/Author:\s*(.+)$/m', $pluginFile, $authorMatches );
preg_match( '/Author URI:\s*(.+)$/m', $pluginFile, $authorUriMatches );
preg_match( '/Plugin URI:\s*(.+)$/m', $pluginFile, $pluginUriMatches );
preg_match( '/Requires at least:\s*(.+)$/m', $pluginFile, $requiresMatches );
preg_match( '/Requires PHP:\s*(.+)$/m', $pluginFile, $requiresPhpMatches );

// Get description from readme.txt (preferred) or plugin file
$description = get_readme_description();
if ( empty( $description ) ) {
	$description = isset( $descriptionMatches[ 1 ] ) ? trim( $descriptionMatches[ 1 ] ) : '';
}

// Read changelog from readme.txt only
$readmeChangelog = get_readme_changelog();

// Build info array
$info = [ 
	'name'            => isset( $pluginNameMatches[ 1 ] ) ? trim( $pluginNameMatches[ 1 ] ) : 'WP Loupe',
	'slug'            => 'wp-loupe',
	'version'         => ltrim( $version, 'v' ),
	'download_url'    => $downloadUrl,
	'tested'          => get_wordpress_tested_version(),
	'requires'        => isset( $requiresMatches[ 1 ] ) ? trim( $requiresMatches[ 1 ] ) : '6.3',
	'requires_php'    => isset( $requiresPhpMatches[ 1 ] ) ? trim( $requiresPhpMatches[ 1 ] ) : '8.2',
	'last_updated'    => date( 'Y-m-d H:i:s' ),
	'sections'        => [ 
		'description' => $description,
		'changelog'   => format_changelog( $readmeChangelog ),
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
 * Get the full description from the readme.txt file,
 * excluding the metadata section at the top
 * 
 * @return string The formatted description or empty string if not found
 */
function get_readme_description() {
	if ( ! file_exists( 'readme.txt' ) ) {
		return '';
	}

	$readme = file_get_contents( 'readme.txt' );

	// Look for the Description section content - the true plugin description
	if ( preg_match( '/== Description ==\s*(.*?)(?:==|$)/s', $readme, $longDescription ) ) {
		$longDesc = trim( $longDescription[ 1 ] );

		// Format the description - convert markdown to HTML
		$longDesc = preg_replace( '/=\s*(.*?)\s*=/', '<h3>$1</h3>', $longDesc );
		$longDesc = preg_replace( '/\*\*(.*?)\*\*/', '<strong>$1</strong>', $longDesc );
		$longDesc = preg_replace( '/\*(.*?)\*/', '<em>$1</em>', $longDesc );

		// Convert list items
		$longDesc = preg_replace( '/^\*\s*(.*?)$/m', '<li>$1</li>', $longDesc );
		$longDesc = preg_replace( '/(<li>.*?<\/li>\s*)+/', '<ul>$0</ul>', $longDesc );

		return $longDesc;
	}

	// If no Description section found
	return '';
}

/**
 * Get changelog information from readme.txt
 * 
 * @return string The changelog content from readme.txt or empty string if not found
 */
function get_readme_changelog() {
	if ( ! file_exists( 'readme.txt' ) ) {
		return '';
	}

	$readme = file_get_contents( 'readme.txt' );

	// Extract the Changelog section
	if ( preg_match( '/== Changelog ==\s*(.*?)(?:==|$)/s', $readme, $matches ) ) {
		return trim( $matches[ 1 ] );
	}

	return '';
}

/**
 * Format changelog from readme.txt for the info.json file
 *
 * @param string $readmeChangelog Changelog content from readme.txt
 * @return string The formatted HTML changelog
 */
function format_changelog( $readmeChangelog ) {
	$formattedChangelog = '<h3>Changelog</h3>';

	// If no changelog content, return the basic header
	if ( empty( $readmeChangelog ) ) {
		return $formattedChangelog . "<p>No changelog information available.</p>";
	}

	// Process the readme changelog format (typically = Version x.x.x =)
	$readmeChangelog = preg_replace( '/= ([^=]+) =/m', '<h4>$1</h4>', $readmeChangelog );

	// Create a proper list for bullet items
	$lines              = explode( "\n", $readmeChangelog );
	$inList             = false;
	$processedChangelog = '';

	foreach ( $lines as $line ) {
		$line = trim( $line );

		// Skip empty lines
		if ( empty( $line ) ) {
			if ( $inList ) {
				$processedChangelog .= "</ul>";
				$inList             = false;
			}
			continue;
		}

		// If this is a header, add it directly
		if ( strpos( $line, '<h4>' ) === 0 ) {
			if ( $inList ) {
				$processedChangelog .= "</ul>";
				$inList             = false;
			}
			$processedChangelog .= $line;
		}
		// Handle bullet points
		elseif ( preg_match( '/^\*\s*(.+)$/', $line, $matches ) || preg_match( '/^-\s*(.+)$/', $line, $matches ) ) {
			if ( ! $inList ) {
				$processedChangelog .= "<ul>";
				$inList             = true;
			}
			$processedChangelog .= "<li>" . trim( $matches[ 1 ] ) . "</li>";
		}
		// Regular text
		else {
			if ( $inList ) {
				$processedChangelog .= "</ul>";
				$inList             = false;
			}
			$processedChangelog .= "<p>" . $line . "</p>";
		}
	}

	// Close any open list
	if ( $inList ) {
		$processedChangelog .= "</ul>";
	}

	return $formattedChangelog . $processedChangelog;
}