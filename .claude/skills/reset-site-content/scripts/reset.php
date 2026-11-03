<?php
/**
 * Reset a WordPress site's content while keeping its header and footer.
 *
 * Usage: php reset.php <wp-root> <dry|apply> <backup-dir>
 *
 * - Deletes every post of every content post type (posts, pages, CPTs, revisions, drafts, trash).
 * - Keeps config post types (menus, global styles, block navigation/templates, ACF definitions).
 * - Converts menu items that link to posts into custom links, so deleting the target
 *   doesn't make WordPress remove them from the header/footer menus.
 * - Deletes every attachment (and its files) except images used by the header/footer:
 *   ACF options fields read in the active theme's header.php/footer.php, site_icon, custom_logo.
 * - Resets the front page / posts page settings if they point at a deleted page.
 */

[ , $root, $mode, $backup_dir ] = $argv + [ null, null, 'dry', null ];
if ( ! $root || ! is_file( "$root/wp-load.php" ) ) {
	fwrite( STDERR, "Usage: php reset.php <wp-root> <dry|apply> <backup-dir>\n" );
	exit( 1 );
}
$apply = ( 'apply' === $mode );

require "$root/wp-load.php";
global $wpdb;

$keep_types = array(
	'nav_menu_item', 'wp_global_styles', 'wp_navigation', 'wp_template', 'wp_template_part',
	'wp_font_family', 'wp_font_face', 'custom_css',
	'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page',
);

// ---- Images used by the header/footer --------------------------------------------------
$keep_imgs  = array();
$theme_dirs = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
$fields     = array();
foreach ( $theme_dirs as $dir ) {
	foreach ( array( 'header.php', 'footer.php' ) as $f ) {
		if ( is_file( "$dir/$f" ) && preg_match_all( "/get_field\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]options?['\"]/", file_get_contents( "$dir/$f" ), $m ) ) {
			$fields = array_merge( $fields, $m[1] );
		}
	}
}
$fields = array_unique( $fields );
foreach ( $fields as $name ) {
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = %s OR option_name LIKE %s",
		"options_$name", $wpdb->esc_like( "options_{$name}_" ) . '%'
	) );
	foreach ( $rows as $r ) {
		$vals = maybe_unserialize( $r->option_value );
		$vals = is_array( $vals ) ? $vals : array( $vals );
		array_walk_recursive( $vals, function ( $v ) use ( &$keep_imgs, $r ) {
			if ( is_numeric( $v ) && 'attachment' === get_post_type( (int) $v ) ) {
				$keep_imgs[ (int) $v ] = $r->option_name;
			}
		} );
	}
}
foreach ( array( 'site_icon' => get_option( 'site_icon' ), 'custom_logo' => get_theme_mod( 'custom_logo' ) ) as $k => $v ) {
	if ( $v && 'attachment' === get_post_type( (int) $v ) ) {
		$keep_imgs[ (int) $v ] = $k;
	}
}

echo "Site: " . home_url() . "  (theme: " . get_stylesheet() . ")\n";
echo "Mode: " . ( $apply ? 'APPLY' : 'DRY RUN' ) . "\n\n";
echo "Header/footer option fields scanned: " . implode( ', ', $fields ) . "\n";
echo "Images kept:\n";
foreach ( $keep_imgs as $id => $src ) {
	echo "  $id " . basename( get_attached_file( $id ) ) . "  (from $src)\n";
}

// ---- Inventory ---------------------------------------------------------------------------
$in       = "'" . implode( "','", array_map( 'esc_sql', $keep_types ) ) . "'";
$content  = $wpdb->get_results( "SELECT ID, post_type, post_status, post_title FROM {$wpdb->posts} WHERE post_type NOT IN ($in) AND post_type <> 'attachment' ORDER BY post_type, ID" );
$att_ids  = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment'" ) );
$del_atts = array_values( array_diff( $att_ids, array_keys( $keep_imgs ) ) );

$by_type = array();
foreach ( $content as $p ) {
	$by_type[ "$p->post_type/$p->post_status" ] = ( $by_type[ "$p->post_type/$p->post_status" ] ?? 0 ) + 1;
}
echo "\nContent to delete (" . count( $content ) . "):\n";
foreach ( $by_type as $k => $c ) {
	echo "  $k: $c\n";
}
echo "Attachments to delete: " . count( $del_atts ) . " of " . count( $att_ids ) . "\n";

$menu_fix = array();
foreach ( wp_get_nav_menus() as $menu ) {
	foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
		if ( 'post_type' === $item->type ) {
			$menu_fix[] = $item;
		}
	}
}
echo "Menu items to convert to custom links: " . count( $menu_fix ) . "\n";

// Files belonging to the attachments being deleted.
$files = array();
foreach ( $del_atts as $aid ) {
	$f = get_attached_file( $aid );
	if ( ! $f ) {
		continue;
	}
	$files[] = $f;
	$dir     = dirname( $f );
	$meta    = wp_get_attachment_metadata( $aid );
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $s ) {
		$files[] = "$dir/" . $s['file'];
	}
	if ( ! empty( $meta['original_image'] ) ) {
		$files[] = "$dir/" . $meta['original_image'];
	}
}
$files = array_values( array_unique( $files ) );
echo "Upload files belonging to those attachments: " . count( $files ) . "\n";

if ( ! $apply ) {
	echo "\nDry run only. Nothing changed.\n";
	exit( 0 );
}

if ( $backup_dir ) {
	file_put_contents( "$backup_dir/deleted-files.txt", implode( "\n", $files ) . "\n" );
}

// ---- Apply -------------------------------------------------------------------------------
foreach ( $menu_fix as $item ) {
	update_post_meta( $item->ID, '_menu_item_type', 'custom' );
	update_post_meta( $item->ID, '_menu_item_object', 'custom' );
	update_post_meta( $item->ID, '_menu_item_object_id', $item->ID );
	update_post_meta( $item->ID, '_menu_item_url', $item->url );
	wp_update_post( array( 'ID' => $item->ID, 'post_title' => html_entity_decode( $item->title ) ) );
}

$deleted_ids = array();
foreach ( $content as $p ) {
	if ( get_post( $p->ID ) && wp_delete_post( $p->ID, true ) ) {
		$deleted_ids[] = (int) $p->ID;
	}
}
// Revisions are normally removed with their parent; sweep any orphans.
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ) as $id ) {
	wp_delete_post( $id, true );
}

$a = 0;
foreach ( $del_atts as $id ) {
	if ( wp_delete_attachment( $id, true ) ) {
		$a++;
	}
}

// Remove any listed file WordPress left behind (only files of deleted attachments).
$leftover = 0;
foreach ( $files as $f ) {
	if ( file_exists( $f ) ) {
		@unlink( $f );
		$leftover++;
	}
}

foreach ( array( 'page_on_front', 'page_for_posts' ) as $opt ) {
	$id = (int) get_option( $opt );
	if ( $id && ! get_post( $id ) ) {
		update_option( $opt, 0 );
		echo "Reset $opt (pointed at deleted page $id)\n";
	}
}
if ( 'page' === get_option( 'show_on_front' ) && ! get_option( 'page_on_front' ) ) {
	update_option( 'show_on_front', 'posts' );
	echo "Set show_on_front = posts\n";
}

// ---- Verify ------------------------------------------------------------------------------
$still = array_filter( $files, 'file_exists' );
echo "\nDeleted content: " . count( $deleted_ids ) . "\n";
echo "Deleted attachments: $a\n";
echo "Leftover files removed manually: $leftover\n";
echo "Files of deleted attachments still on disk: " . count( $still ) . "\n";
foreach ( $still as $f ) {
	echo "  STILL: $f\n";
}
echo "\nRemaining posts by type:\n";
foreach ( $wpdb->get_results( "SELECT post_type, COUNT(*) c FROM {$wpdb->posts} GROUP BY post_type" ) as $r ) {
	echo "  $r->post_type: $r->c\n";
}
echo "Menus:\n";
foreach ( wp_get_nav_menus() as $menu ) {
	$items = (array) wp_get_nav_menu_items( $menu );
	echo "  $menu->name: " . count( $items ) . " items (" . implode( ', ', array_map( fn( $i ) => $i->title, $items ) ) . ")\n";
}
