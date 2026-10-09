<?php
/**
 * Plugin Name: BV Geotagged Media
 * Description: Show the location data for every photo uploaded from wp-admin.
 * Plugin URI: https://github.com/digfish/geotagged-media
 * Author: Lai Consulting Team, Gentrit
 * Author URI: https://github.com/gentritbiba
 * Version: 0.2
 * GitLab Plugin URI: https://gitlab.com/beauvoyage/bv_geotagged_media
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

global $special_categories;
$special_categories = array('private','standalone','uncategorized');

/*
 * A $special_cat_ids array used to be built here. It was removed: it
 * accumulated with `+=`, which is union *by key*, so after the first element
 * every subsequent `+= array( $term_id )` was discarded and it could only ever
 * hold one id. Nothing read it, and building it ran three
 * get_category_by_slug() queries at file scope on every request, admin
 * included.
 */

/**
 * Whether a URL points at an image, by extension.
 *
 * These two helpers previously lived in gtm_plugin_main.php_gentrit, a file
 * WordPress never loads because of its extension, so they did not exist at
 * runtime -- which is why bv_map/includes/compat.php had to reimplement them
 * for voyage's story-list template. They belong in the media plugin. bv_map's
 * copies are behind function_exists(), and so are these -- whichever plugin
 * loads first wins and the other defers. Without a guard on both sides a load
 * order where bv_map came first would be a fatal redeclare.
 *
 * The original split on "." and took the last segment, which treats a query
 * string as part of the extension. This parses the URL first.
 *
 * @param string $url File URL or path.
 * @return bool
 */
if ( ! function_exists( 'isImage' ) ) {
	function isImage( $url ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return bv_gtm_url_has_extension( $url, array( 'JPG', 'JPEG', 'PNG', 'GIF', 'TIFF', 'RAW', 'WEBP' ) );
	}
}

/**
 * Whether a URL points at a video, by extension.
 *
 * @param string $url File URL or path.
 * @return bool
 */
if ( ! function_exists( 'isVideo' ) ) {
	function isVideo( $url ) { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		return bv_gtm_url_has_extension(
			$url,
			array( 'WEBM', 'MPG', 'MP2', 'MPEG', 'MPE', 'MPV', 'OGG', 'MP4', 'M4P', 'M4V', 'AVI', 'WMV', 'MOV', 'QT', 'FLV', 'SWF' )
		);
	}
}

/**
 * Match a URL's extension against a whitelist, ignoring any query string.
 *
 * @param string   $url        File URL or path.
 * @param string[] $extensions Uppercase extensions to accept.
 * @return bool
 */
function bv_gtm_url_has_extension( $url, $extensions ) { // phpcs:ignore
	if ( ! is_string( $url ) || '' === $url ) {
		return false;
	}

	$path      = wp_parse_url( $url, PHP_URL_PATH );
	$extension = pathinfo( $path ? $path : $url, PATHINFO_EXTENSION );

	return in_array( strtoupper( $extension ), $extensions, true );
}

/**
 * Reads the exif data of an image 
 * @param string $file the location of the image
 * @return array longitude, latitude and datetime created
 */
/**
 * Divide an EXIF rational ("num/den") safely.
 *
 * The six coordinate components used to be divided directly, e.g.
 * `$lat_degrees_a[0] / $lat_degrees_a[1]`, with no check that the denominator
 * existed or was non-zero. A rational with a zero or missing denominator is a
 * DivisionByZeroError on PHP 8 -- a fatal, during an upload.
 *
 * @param string $rational EXIF rational, e.g. "54/1".
 * @return float
 */
function bv_gtm_exif_rational( $rational ) {
	$parts = explode( '/', (string) $rational );
	$num   = isset( $parts[0] ) ? (float) $parts[0] : 0.0;
	$den   = isset( $parts[1] ) ? (float) $parts[1] : 1.0;

	return 0.0 === $den ? 0.0 : $num / $den;
}

function read_gps_location($file){
	// exif_read_data() warns and returns false for anything that is not a
	// JPEG/TIFF, and it was previously called for every attachment -- PDFs,
	// video, audio -- after which $info['DateTimeOriginal'] warned too.
	if ( ! is_string( $file ) || ! function_exists( 'exif_read_data' ) || ! isImage( $file ) ) {
		return array();
	}

	$info = @exif_read_data( $file );

	if ( ! is_array( $info ) ) {
		return array();
	}
	//error_log(print_r(' file Info: '. json_encode($info),true));
	// print_r($info);
	if (isset($info['GPSLatitude']) && isset($info['GPSLongitude']) &&
		isset($info['GPSLatitudeRef']) && isset($info['GPSLongitudeRef']) &&
		in_array($info['GPSLatitudeRef'], array('E','W','N','S')) && in_array($info['GPSLongitudeRef'], array('E','W','N','S'))) {

		$GPSLatitudeRef  = strtolower(trim($info['GPSLatitudeRef']));
		$GPSLongitudeRef = strtolower(trim($info['GPSLongitudeRef']));

		// GPSLatitude/GPSLongitude are arrays of three rationals; a camera
		// that omits one leaves the offset undefined.
		if ( ! isset( $info['GPSLatitude'][2], $info['GPSLongitude'][2] ) ) {
			return isset( $info['DateTimeOriginal'] )
				? array( 'datetime' => $info['DateTimeOriginal'] )
				: array();
		}

		$lat_degrees = bv_gtm_exif_rational( $info['GPSLatitude'][0] );
		$lat_minutes = bv_gtm_exif_rational( $info['GPSLatitude'][1] );
		$lat_seconds = bv_gtm_exif_rational( $info['GPSLatitude'][2] );
		$lng_degrees = bv_gtm_exif_rational( $info['GPSLongitude'][0] );
		$lng_minutes = bv_gtm_exif_rational( $info['GPSLongitude'][1] );
		$lng_seconds = bv_gtm_exif_rational( $info['GPSLongitude'][2] );

		$lat = (float) $lat_degrees+((($lat_minutes*60)+($lat_seconds))/3600);
		$lng = (float) $lng_degrees+((($lng_minutes*60)+($lng_seconds))/3600);

		//If the latitude is South, make it negative. 
		//If the longitude is west, make it negative
		$GPSLatitudeRef  == 's' ? $lat *= -1 : '';
		$GPSLongitudeRef == 'w' ? $lng *= -1 : '';

		$result = array(
			'lat' => $lat,
			'lng' => $lng,
		);

		if ( isset( $info['DateTimeOriginal'] ) ) {
			$result['datetime'] = $info['DateTimeOriginal'];
		}

		return $result;
	}

	// No usable GPS. DateTimeOriginal was read unguarded here, which warned on
	// any image without it.
	return isset( $info['DateTimeOriginal'] )
		? array( 'datetime' => $info['DateTimeOriginal'] )
		: array();
}

add_action("add_attachment", 'bv_attachment');
function bv_attachment($attachment_ID)
{          
	global $current_user;
	//get_currentuserinfo();
	wp_get_current_user();
	$att_url = get_attached_file( $attachment_ID );
	//$att_url = wp_get_attachment_url( $attachment_ID );
	$exif=read_gps_location($att_url);
	
	//error_log(print_r(' exif: '. json_encode($exif),true));
	// Adds geospatial data
	//if( $exif['lng'] && $exif['lat'] ){
	if(isset($exif['lng'])  && isset($exif['lat']) ){
		//update the geom_point of an image
		update_post_meta($attachment_ID, 'geom_point','{"type":"Feature","properties":{},"geometry":{"type":"Point","coordinates":['. $exif['lng'].','. $exif['lat'] .']}}');
		//add_post_meta($attachment_ID, 'geom_point',WP_GeoUtil::point( $exif['lat'], $exif['lng'] ); //to be rewritten
	}
	if( isset( $exif['datetime']) ){
		update_post_meta($attachment_ID,'datetime',$exif['datetime']);
		$my_args = array(
			'ID' => $attachment_ID,
			'post_date' => $exif['datetime'],
			'post_date_gmt' => $exif['datetime']
		);
		wp_update_post( $my_args );
	}
}

/*
 * Hooked to save_post only.
 *
 * It was also attached to transition_post_status at priority 30, but the two
 * hooks pass incompatible arguments: save_post gives ( $post_id, $post,
 * $update ) while transition_post_status gives ( $new_status, $old_status,
 * $post ). On the latter the first parameter was a status string, so
 * get_post_type( 'publish' ) returned false and the callback returned
 * immediately -- the hook never did anything. Removing the registration
 * preserves current behaviour exactly; wiring it up properly would introduce
 * new, untested double-processing.
 *
 * That also resolves a second bug: the re-entrancy guard below called
 * remove_action( 'transition_post_status', ... ) without a priority, so it did
 * not match the registration at 30 and never detached anything.
 */
add_action( 'save_post', 'bv_thumbnailmeta_postmeta', 10, 2 );
function bv_thumbnailmeta_postmeta($post,$post_obj)
{
	if ( get_post_type($post) != 'post' ) return;
	if( $post_thumbnail_id = get_post_thumbnail_id( $post ) ){
		$datetime=get_post_meta( $post_thumbnail_id, 'datetime',true);
		$geom_point=get_post_meta( $post_thumbnail_id, 'geom_point',true);
		//$post_tags=wp_get_post_tags( $post_thumbnail_id );
		// echo "update_post_meta($post,'geom_point',$geom_point)";
		if( isset($datetime) && !empty($datetime) ){
			$my_args = array(
				'ID' => $post,
				'post_date' => $datetime,
				'post_date_gmt' => $datetime
			);

			if ( ! wp_is_post_revision( $post ) ){
				// unhook this function so it doesn't loop infinitely
				remove_action('save_post', 'bv_thumbnailmeta_postmeta');
				// update the post, which calls save_post again
				wp_update_post( $my_args );
				if ( isset($geom_point) && !empty($geom_point)){
					update_post_meta($post,'geom_point',$geom_point);
				}else{
					delete_post_meta($post,'geom_point');
				}
				/*
				if ( empty(wp_get_post_tags($post)) && isset($post_tags) && !empty($post_tags)){
					wp_set_post_tags( $post, $post_tags,true);
				}
				 */
				//bv_update_bothline1($post); not needed. Function is called via another hook with lower priority.
				// re-hook this function
				add_action('save_post', 'bv_thumbnailmeta_postmeta',10,2);
			}
		/*
		var_error_log('datetime ' . $datetime . ' of post ' . $post);
			$post_obj->post_date = $datetime;
			$post_obj->post_date_gmt= get_gmt_from_date($datetime);
		 */
		}
	}
}

/*
 * $get_value keeps its default for call-site compatibility, but declaring an
 * optional parameter before a required one is deprecated in PHP 8.0 and the
 * notice fired on every load into a debug.log that is enabled in production.
 * Giving $current_post a default silences it without changing any call site --
 * all four pass both arguments positionally.
 */
function bv_get_nghbpost_geom($get_value=false,$current_post=null,$previous=true,$arr_slugs=array(), $excluded_terms = '', $taxonomy = 'category'){
	if ( ! $current_post || ! function_exists( 'bv_get_adjacent_post' ) ) {
		return null; // bv_get_adjacent_post() is provided by the voyage plugin.
	}

	$current_point= $current_post->geom_point;
	if (empty($current_point)) return;
	$ref_post=$current_post;
	while ( !empty($adjacent_post=bv_get_adjacent_post($ref_post,$previous,$arr_slugs,$excluded_terms,$taxonomy)) ){
		$adjacent_point=$adjacent_post->geom_point ;
		#if (  ($adjacent_post->ID != $ref_post->ID) && !empty($adjacent_point) && !WP_GeoUtil::st_equals( $current_point, $adjacent_point)) break;
		if (  ($adjacent_post->ID != $ref_post->ID) && !empty($adjacent_point) && !( $current_point==$adjacent_point)) break;
		$ref_post = $adjacent_post; // look further until there is non-empty geom_point
	}
	if ($get_value){
		return($adjacent_point??NULL);
	}else{
		return($adjacent_post??NULL);
	}	
}

add_action( 'save_post', 'bv_update_catline',100 ,2 );
add_action( 'deleted_post', 'bv_update_catline',200,2);
add_action( 'trashed_post', 'bv_update_catline',300,2);
//add_action('publish_post', 'bv_update_catline',110,2);
//add_action( 'transition_post_status', 'bv_update_catline',120,2);

function bv_update_gline2($current_post,$where=0,$term_id=0, $excluded_terms = '', $taxonomy = 'category'){
	//$wp_geoutil = WP_GeoUtil::get_instance();
	if ($term_id == 0) return;

	// WP_GeoUtil comes from WP-GeoMeta and was called unguarded below, so
	// deactivating that plugin made every post save fatal.
	if ( ! class_exists( 'WP_GeoUtil' ) ) {
		return;
	}

	$metakey_cat='geom_line2__'. strval($term_id) ;
	$current_point= $current_post->geom_point;
	if (empty($current_point)) return;

	$geom_line=get_post_meta($current_post->ID,  $metakey_cat , true);

	$startpoint= $geom_line?WP_GeoUtil::st_startpoint($geom_line):$current_point;
	$endpoint = $geom_line?WP_GeoUtil::st_endpoint($geom_line):$current_point;
	//error_log(print_r('startPt: '. json_encode($startpoint),true));
	//error_log(print_r('endPt: '. json_encode($endpoint),true));

	//error_log(print_r(' empty CURRENT: '. json_encode($current_point),true));
	$previous_point = $where>0?$startpoint:bv_get_nghbpost_geom(true,$current_post,true,array($term_id),$excluded_terms,$taxonomy)??$current_point;
	$next_point = $where<0?$endpoint:bv_get_nghbpost_geom(true,$current_post,false,array($term_id),$excluded_terms,$taxonomy)??$current_point;
	if( empty($previous_point)) $previous_point=$current_point;
	if( empty($next_point)) $next_point=$current_point;
	

	//error_log(print_r( $metakey_cat . ' 	  posT Id: '. $current_post->ID,true));
	//error_log(print_r($where. '  - geomLine: '. json_encode($geom_line),true));
	//error_log(print_r(' prevIOUS: '. $previous_point,true));
	//error_log(print_r(' curRENT: '. $current_point,true));
	//error_log(print_r(' neXT: '. $next_point,true));

	$geom_line_new= WP_GeoUtil::LineString( $previous_point, $current_point, $next_point) ;

	/*
	 * WP_GeoUtil::__callStatic() returns null when the function is not in
	 * get_capabilities(). The installed WP-GeoMeta is hand-patched
	 * (wp-geoutil.php:714) to add 'linestring' and 'point'; any update to that
	 * plugin reverts the patch, after which this is null and the line below
	 * would overwrite a good geom_line with nothing. Leave the existing value
	 * alone instead.
	 */
	if ( empty( $geom_line_new ) ) {
		return $geom_line;
	}
	//error_log(print_r('		 classMethods: '. json_encode(get_class_methods("wp_geoutil") ) , true));
	//error_log(print_r('		 geomCapabilities: '. json_encode(WP_GeoUtil::get_capabilities()) , true));
	//error_log(print_r('		 geomFuncs: '. json_encode(WP_GeoUtil::get_all_funcs()) , true));
	//error_log(print_r('		 geom_line_nEW: '. json_encode(WP_GeoUtil::linestring(WP_GeoUtil::aswkb( $previous_point),WP_GeoUtil::aswkb( $current_point), wp_geoutil::aswkb($next_point))) , true));
	//error_log(print_r('		 geom_line_nEW: '. wp_geoutil::LineString( $previous_point, $current_point, $next_point ), true));
	//error_log(print_r('		 geom_line_nEW: '. wp_geoutil::st_asgeojson(WP_GeoUtil::LineString( $previous_point, $current_point, $next_point) ), true));
	//error_log(print_r('		 geom_line_nEW: '. json_encode(WP_GeoUtil::st_asgeojson(WP_GeoUtil::linestring( $previous_point, $current_point, $next_point))) , true));
	//error_log(print_r('		 geom_line_nEW: '. json_encode(WP_GeoUtil::linestring( $current_point)) , true));
	//error_log(print_r(' geom_line_NEw: '. json_encode(wp_geoutil::st_asgeojson($geom_line_new)),true));

	//error_log(print_r(' geom_lines EQUAL: '. json_encode(WP_GeoUtil::st_eqUals($geom_line,$geom_line_new) ),true));
	//error_log(print_r('   		 geom_line_old: '. json_encode($geom_line),true));
	//if( empty($geom_line) || !WP_GeoUtil::st_equalS($geom_line,$geom_line_new)  ){
	if( empty($geom_line) || !($geom_line==$geom_line_new)  ){
		update_post_meta($current_post->ID, $metakey_cat , $geom_line_new);
		//update_post_meta($current_post->ID, $metakey_cat , $geom_line_new);
		//update_post_meta($current_post->ID, $metakey_cat , WP_GeoUtil::ST_asgeojson($geom_line_new));
	}
	//error_log(print_r('		 geom_line_new: '. json_encode($geom_line_new),true));
	return $geom_line_new;
}

function bv_update_catline($current_id,$updatecurrent=NULL){
	$special_categories_local = ( isset( $GLOBALS['special_categories'] ) && is_array( $GLOBALS['special_categories'] ) )
		? $GLOBALS['special_categories']
		: array();
	$current_post=get_post($current_id);
	if ($current_post->post_type != 'post') return false;
	if (empty($current_post->geom_point)) return false;
	$excluded_terms='';
	$taxonomy='category';
	$categories=get_the_category($current_id);
	$arr_ids=array();
	foreach($categories as $cat){
		// Was in_array( $cat, ... ) -- comparing a WP_Term object against an
		// array of slug strings, which never matches, so journey lines were
		// built for 'private', 'standalone' and 'uncategorized' too.
		if ( ! in_array( $cat->slug, $special_categories_local, true ) ) {
			$arr_ids[] = $cat->term_id;
		}
	}
	foreach($arr_ids as $term_id){
		$previous_post=bv_get_nghbpost_geom(false,$current_post,true,array($term_id),$excluded_terms,$taxonomy);
		if ( !empty($previous_post) ){
			$resultforth=(bv_update_gline2($previous_post,1,$term_id,$excluded_terms,$taxonomy));
		}
		// update source-path of next post
		$next_post=bv_get_nghbpost_geom(false,$current_post,false,array($term_id),$excluded_terms,$taxonomy);
		if ( !empty($next_post) ){
			$resultback=(bv_update_gline2($next_post,-1,$term_id,$excluded_terms,$taxonomy));
		}
		$result = $updatecurrent?(bv_update_gline2($current_post,0,$term_id, $excluded_terms, $taxonomy)):NULL;
	}

	#error_log(print_r(' gRESULTw: '. json_encode($result),true));
	return $arr_ids;
}

