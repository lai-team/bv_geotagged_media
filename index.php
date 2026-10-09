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

$special_cat_ids = array();
foreach ($special_categories as $cat){
	$qid=get_category_by_slug($cat);
	if(!empty($qid)) $special_cat_ids+=array( $qid->term_id );
}

/**
 * Reads the exif data of an image 
 * @param string $file the location of the image
 * @return array longitude, latitude and datetime created
 */
function read_gps_location($file){
	$info = exif_read_data($file);
	//error_log(print_r(' file Info: '. json_encode($info),true));
	// print_r($info);
	if (isset($info['GPSLatitude']) && isset($info['GPSLongitude']) &&
		isset($info['GPSLatitudeRef']) && isset($info['GPSLongitudeRef']) &&
		in_array($info['GPSLatitudeRef'], array('E','W','N','S')) && in_array($info['GPSLongitudeRef'], array('E','W','N','S'))) {

		$GPSLatitudeRef  = strtolower(trim($info['GPSLatitudeRef']));
		$GPSLongitudeRef = strtolower(trim($info['GPSLongitudeRef']));

		$lat_degrees_a = explode('/',$info['GPSLatitude'][0]);
		$lat_minutes_a = explode('/',$info['GPSLatitude'][1]);
		$lat_seconds_a = explode('/',$info['GPSLatitude'][2]);
		$lng_degrees_a = explode('/',$info['GPSLongitude'][0]);
		$lng_minutes_a = explode('/',$info['GPSLongitude'][1]);
		$lng_seconds_a = explode('/',$info['GPSLongitude'][2]);

		$lat_degrees = $lat_degrees_a[0] / $lat_degrees_a[1];
		$lat_minutes = $lat_minutes_a[0] / $lat_minutes_a[1];
		$lat_seconds = $lat_seconds_a[0] / $lat_seconds_a[1];
		$lng_degrees = $lng_degrees_a[0] / $lng_degrees_a[1];
		$lng_minutes = $lng_minutes_a[0] / $lng_minutes_a[1];
		$lng_seconds = $lng_seconds_a[0] / $lng_seconds_a[1];

		$lat = (float) $lat_degrees+((($lat_minutes*60)+($lat_seconds))/3600);
		$lng = (float) $lng_degrees+((($lng_minutes*60)+($lng_seconds))/3600);

		//If the latitude is South, make it negative. 
		//If the longitude is west, make it negative
		$GPSLatitudeRef  == 's' ? $lat *= -1 : '';
		$GPSLongitudeRef == 'w' ? $lng *= -1 : '';

		return array(
			'lat' => $lat,
			'lng' => $lng,
			'datetime' =>$info['DateTimeOriginal']
		);
	}     
	return array(
		'datetime' =>$info['DateTimeOriginal']
	);
	//return false;
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

add_action( 'save_post', 'bv_thumbnailmeta_postmeta', 10, 2 );
//add_action('publish_post', 'bv_thumbnailmeta_postmeta', 20, 2 );
add_action( 'transition_post_status', 'bv_thumbnailmeta_postmeta', 30, 2 );
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
				//remove_action('publish_post', 'bv_thumbnailmeta_postmeta' );
				remove_action( 'transition_post_status', 'bv_thumbnailmeta_postmeta' );
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
				//add_action('publish_post', 'bv_thumbnailmeta_postmeta', 20, 2 );
				add_action( 'transition_post_status', 'bv_thumbnailmeta_postmeta', 30, 2 );
			}
		/*
		var_error_log('datetime ' . $datetime . ' of post ' . $post);
			$post_obj->post_date = $datetime;
			$post_obj->post_date_gmt= get_gmt_from_date($datetime);
		 */
		}
	}
}

function bv_get_nghbpost_geom($get_value=false,$current_post,$previous=true,$arr_slugs=array(), $excluded_terms = '', $taxonomy = 'category'){
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
	$special_categories_local=$GLOBALS['special_categories'];  
	$current_post=get_post($current_id);
	if ($current_post->post_type != 'post') return false;
	if (empty($current_post->geom_point)) return false;
	$excluded_terms='';
	$taxonomy='category';
	$categories=get_the_category($current_id);
	$arr_ids=array();
	foreach($categories as $cat){
		if (!in_array($cat,$special_categories_local) ) $arr_ids[]=$cat->term_id;	
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

