<?php

/**
Plugin Name: Webonary
Plugin URI: https://github.com/sillsdev/sil-dictionary-webonary
Description: Webonary gives language groups the ability to publish their bilingual or multilingual dictionaries on the web.
The SIL Dictionary plugin has several components. It includes a dashboard, an import for XHTML (export from Fieldworks Language Explorer), and multilingual dictionary search.
Author: SIL International
Author URI: http://www.sil.org/
Text Domain: sil_dictionary
Domain Path: /lang/
Version: v. 8.3.9
License: MIT
*/

/**
 * SIL Dictionary
 *
 * SIL Dictionaries: Includes a dashboard, an import for XHTML, and multilingual dictionary search.
 *
 * PHP version 5.2
 *
 * LICENSE GPL v2
 *
 * @package WordPress
 * @since 3.1
 */

// don't load directly
if ( ! defined('ABSPATH') )
	die( '-1' );

include_once __DIR__ . DIRECTORY_SEPARATOR . 'include' . DIRECTORY_SEPARATOR . 'defines.php';

/** @var wpdb $wpdb */
global $wpdb;

function webonary_admin_script()
{
	wp_register_script('webonary_admin_script', plugin_dir_url(__FILE__) . 'js/admin_script.js', [], false, true);
	wp_enqueue_script('webonary_admin_script');
	wp_localize_script(
		'webonary_admin_script',
		'webonary_ajax_obj',
		['ajax_url' => admin_url('admin-ajax.php')]
	);

	wp_register_style('webonary_admin_style', plugin_dir_url(__FILE__) . 'css/admin_styles.css', [], false, 'all');
	wp_enqueue_style('webonary_admin_style');
}
add_action('admin_enqueue_scripts', 'webonary_admin_script');


//if(is_admin() ){
	// Menu in the WordPress Dashboard, under tools.
	add_action('admin_menu', 'Webonary_Configuration::add_admin_menu');
	add_action('admin_bar_menu', 'Webonary_Configuration::on_admin_bar', 35);

	// I looked for a register_install_hook, but given the way WordPress plugins
	// can be implemented, I'm not sure it would work right even if I did find one.
	// The register_activation_hook() appears not to work for some reason. But the
	// site won't start up that much any way, and it doesn't hurt anything to call
	// it more than once.
	add_action('init', 'install_sil_dictionary_infrastructure', 0);

	// Take out the custom data when uninstalling the plugin.
	register_uninstall_hook( __FILE__, 'uninstall_sil_dictionary_infrastructure' );
//}


/* Search hook */
add_filter('search_message', 'sil_dictionary_custom_message');

add_filter('posts_request','replace_default_search_filter', 10, 2);

// this executes just before wordpress determines which template page to load
add_action('template_redirect', 'my_enqueue_css');

// add_action('pre_get_posts','no_standard_sort');
add_action('preprocess_comment' , 'preprocess_comment_add_type');

// API for FLEx
add_action('rest_api_init', 'Webonary_API_MyType::Register_New_Routes');

function get_dictionary_entries_as_posts($request, $dictionary) {
	echo 'Getting results from ' . $request; 
	$response = wp_remote_get( $request );
	$posts = [];

	if (is_array($response)) { 
		$body = json_decode( $response['body'] ); // use the content
		foreach( $body as $key => $entry ) {
			$post = convert_dictionary_entry_to_fake_post($dictionary, $entry);
			$post->ID = -$key; // negative ID, to avoid clash with a valid post
			$posts[$key] = $post;
		}	
	}

	return $posts;
}

function convert_dictionary_entry_to_fake_post($dictionary, $entry) {	
	//<div class="entry" id="ge5175994-067d-44c4-addc-ca183ce782a6"><span class="mainheadword"><span lang="es"><a href="http://localhost:8000/test/ge5175994-067d-44c4-addc-ca183ce782a6">bacalaitos</a></span></span><span class="senses"><span class="sensecontent"><span class="sense" entryguid="ge5175994-067d-44c4-addc-ca183ce782a6"><span class="definitionorgloss"><span lang="en">cod fish fritters/cod croquettes</span></span><span class="semanticdomains"><span class="semanticdomain"><span class="abbreviation"><span class=""><a href="http://localhost:8000/test/?s=&amp;partialsearch=1&amp;tax=9909">1.7</a></span></span><span class="name"><span class=""><a href="http://localhost:8000/test/?s=&amp;partialsearch=1&amp;tax=9909">Puerto Rican Fritters</a></span></span></span></span></span></span></span></div></div>
	$mainHeadWord = '<span class="mainheadword"><span lang="' . $entry->mainHeadWord[0]->lang . '">'
		. '<a href="' . get_site_url() . '/' . $entry->_id . '">' . $entry->mainHeadWord[0]->value . '</a></span></span>';

	$lexemeform = '';
	if ($entry->audio->src != '') {
		$lexemeform .= '<span class="lexemeform"><span><audio id="' . $entry->audio->id . '">';
		$lexemeform .= '<source src="' . CLOUD_FILE_PATH . $dictionary . '/'  . $entry->audio->src . '"></audio>';
		$lexemeform .= '<a class="' . $entry->audio->fileClass . '" href="#' . $entry->audio->id . '" onClick="document.getElementById(\'' . $entry->audio->id .   '\').play()"> </a></span></span>';
	}

	$sharedgrammaticalinfo = '<span class="sharedgrammaticalinfo"><span class="morphosyntaxanalysis"><span class="partofspeech"><span lang="' . $entry->senses->partOfSpeech->lang . '">' . $entry->senses->partOfSpeech->value . '</span></span></span></span>';

	$sensecontent = '<span class="sensecontent"><span class="sense" entryguid="' . $entry->_id . '">'
		. '<span class="definitionorgloss">';
	foreach( $entry->senses->definitionOrGloss as $definition )	{
		$sensecontent .= '<span lang="' . $definition->lang . '">' . $definition->value . '</span>';
	}
	$sensecontent .= '</span></span>';

	$senses = '<span class="senses">' . $sharedgrammaticalinfo . $sensecontent . '</span>';

	$pictures = '';
	if (count($entry->pictures)) {
		$pictures = '<span class="pictures">';
		foreach( $entry->pictures as $picture )	{
			$pictures .= '<div class="picture">';
			$pictures .= '<a class="image" href="' . CLOUD_FILE_PATH . $dictionary . '/'  . $picture->src . '">';
			$pictures .= '<img src="' . CLOUD_FILE_PATH . $dictionary . '/'  . $picture->src . '"></a>';
			$pictures .= '<div class="captioncontent"><span class="headword"><span lang="' . $definition->lang . '">' . $picture->caption . '</span></span></div>';
			$pictures .= '</div>';
		}
		$pictures .= '</span>';	
	}
	$post = new stdClass();
	$post->post_title = $entry->mainHeadWord[0]->value;
	$post->post_name = $entry->_id;
	$post->post_content = '<div class="entry" id="' . $entry->_id . '">' . $mainHeadWord . $lexemeform . $senses . $pictures . '</div>';
	$post->post_status = 'publish';
	$post->comment_status = 'closed';
	$post->post_type = 'post';
	$post->filter = 'raw'; // important, to prevent WP looking up this post in db!		

	return $post;
}

function get_dictionary_entries_as_reversals($request, $dictionary, $langcode) {
	echo 'Getting results from ' . $request; 
	$response = wp_remote_get( $request );
	$reversals = [];

	if (is_array($response)) { 
		$body = json_decode( $response['body'] ); // use the content
		foreach( $body as $key => $entry ) {
			$reversals[$key] = convert_dictionary_entry_to_reversal($dictionary, $entry, $langcode);
		}	
	}

	return $reversals;
}

function convert_dictionary_entry_to_reversal($dictionary, $entry, $langcode) {	
	//<div class=post><div xmlns="http://www.w3.org/1999/xhtml" class="reversalindexentry" id="g009ab666-43dd-4f2f-ba62-7017417f6b23"><span class="reversalform"><span lang="en">aardvark</span></span><span class="sensesrs"><span class="sensecontent"><span class="sensesr" entryguid="gee1142ec-65f5-4e23-8d95-413685a48c23"><span class="headword"><span lang="mos"><a href="https://www.webonary.org/moore/gee1142ec-65f5-4e23-8d95-413685a48c23">tãnturi</a></span></span><span class="scientificname"><span lang="en">orycteropus afer</span></span></span></span></span></div></div>
	
	$reversal_value = '';
	foreach( $entry->senses->definitionOrGloss as $definition )	{
		if ($langcode == $definition->lang) {
			$reversal_value = $definition->value;
			break;
		}
	}

	$content = '<div class=post><div class="reversalindexentry">';
	$content .= '<span class="reversalform"><span lang="' . $lang . '">';
	$content .= $reversal_value . '</span></span>';
	
	$content .= '<span class="sensesrs"><span class="sensecontent">';
	$content .= '<span class="sensesr" entryguid="' . $entry->_id . '">';

	$content .= '<span class="headword"><span lang="' . $entry->mainHeadWord[0]->lang . '">'
		. '<a href="' . get_site_url() . '/' . $entry->_id . '">' . $entry->mainHeadWord[0]->value . '</a></span></span>';

	$content .= '</span></span></span>';
	$content .= '</div></div>';
	
	$reversal = new stdClass();
	$reversal->reversal_content = $content;

	return $reversal;
}

// search dictionary entries from Cloud Backend, rather than searching WP DB
function search_dictionary_entries($posts, WP_Query $query) {
	if (!$query->is_main_query()) {
		return null;
	}

	$search_word = get_search_query();
	if ($search_word == '') {
		return null;
	}

	$dictionary = is_subdomain_install() ? explode('.', $_SERVER['HTTP_HOST'])[0] : str_replace('/', '', get_blog_details()->path);

	// $dictionary = 'spanish-englishfooddictionary';
	$request = CLOUD_ENTRY_PATH . 'search/' . $dictionary . '/' . $search_word;	

	return get_dictionary_entries_as_posts($request, $dictionary);
}

// Cloud backend test
function get_dictionary_entry( $content ) {
	$page_name = get_query_var('name');
	if (preg_match('/^g[a-f\d]{8}(-[a-f\d]{4}){4}[a-f\d]{8}$/i', $page_name)) {
		$dictionary = is_subdomain_install() ? explode('.', $_SERVER['HTTP_HOST'])[0] : str_replace('/', '', get_blog_details()->path);
		// $dictionary = 'spanish-englishfooddictionary';
		
		$request = CLOUD_ENTRY_PATH . 'entry/' . $page_name;
		echo 'Getting results from ' . $request; 
		$response = wp_remote_get( $request );
	
		if (is_array($response)) { 
			$body = json_decode( $response['body'] ); // use the content
			$post = convert_dictionary_entry_to_fake_post($dictionary, $body);
			$content = $post->post_content;
		}
	}

	return $content;
}

if (in_array(get_current_blog_id(), array(2,624,222))) {
	add_filter( 'posts_pre_query', 'search_dictionary_entries', 10, 2 );
	add_filter('the_content', 'get_dictionary_entry');  
}

function add_rewrite_rules($aRules)
{
	//echo "rewrite rules<br>";
	$aNewRules = array('^/([^/]+)/?$' => 'index.php?clean=$matches[1]');
	$aRules = $aNewRules + $aRules;
	return $aRules;
}

add_filter('post_rewrite_rules', 'add_rewrite_rules');

function add_query_vars($qvars)
{
	if (!in_array('clean', $qvars))
		$qvars[] = 'clean';
	return $qvars;
}

add_filter('query_vars', 'add_query_vars');
