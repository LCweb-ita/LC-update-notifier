<?php
/**                                                           
 *   Codecanyon plugin updates notificator       			  *
 *	 version 1.14											  *
 *   fully compatible from WP 3.5              				  *
 *															  *
 *   Author: Luca Montanari (LCweb)                           *
 *   Site: http://www.lcweb.it/					  			  *
 *                                                            *
 **/
 
 
class lc_update_notifier {
	public $endpoint_url = '';
	public $basepath = '';
	
	/**
	 * handle plugin basepath and check cache
	 *
	 * @param string $path
	 * @param string $endpoint
	 */
	public function __construct($path, $endpoint){
		$this->endpoint_url = $endpoint;
		
		// abort if server doesn't have curl
		if(!function_exists('curl_version')) {return false;}
		
		// get true basepath
		$arr = explode(DIRECTORY_SEPARATOR, $path); 
		if(count($arr) < 2) {return false;}
		$this->basepath = $arr[(count($arr) - 2)] .'/'. $arr[(count($arr) - 1)];

		$this->globals('id', 'set', md5( $path));
		$this->globals('url', 'set', $this->endpoint_url);
		$this->globals('basepath', 'set', $this->basepath);
		
		// cannot use wp_get_update_data from admin init - use two steps
		if(function_exists('get_transient')) { // cookie to avoid continuous redirect
			if(get_transient('lcun_cache') ) {
				add_action('init', array($this, 'init_check'), 1);	
			}
			else {
				if(!isset($_COOKIE[ md5(get_site_url()).'_lcun_sc'])) {
					add_action('admin_init', array($this, 'data_collect'), 999);
				}
			}
		}
	}
	
	
	// function to trigger method at last
	private function is_last_call() {
		if(!isset($GLOBALS['lcun_last_call'])) {
			$GLOBALS['lcun_last_call'] = 0;
		}
		
		$data = $this->globals('all');
		$tot = count($data['id']);
		
		$GLOBALS['lcun_last_call'] = $GLOBALS['lcun_last_call'] + 1;
		return (count($data['id']) == $GLOBALS['lcun_last_call']) ? true : false;
	}
	
	
	/**
	 * init function triggered only once to check the latest version.
	 * if current is old - triggers WP notifiers
	 *
	 */
	public function init_check() {
		if($this->is_last_call()) {
			if(is_admin() && current_user_can('install_plugins')) {
				$GLOBALS['lcun_to_update'] = get_transient('lcun_cache');
				if(is_array($GLOBALS['lcun_to_update']) && count($GLOBALS['lcun_to_update']) > 0) {
					// only from WP 3.5
					if((float)substr(get_bloginfo('version'), 0, 3) >= 3.5) {
						add_filter('wp_get_update_data', array($this, 'update_count'));
					}
					
					add_action('core_upgrade_preamble', array($this, 'upgrade_core_message'));
					add_action('admin_head', array($this, 'plugin_list_message'), 999);	
				}
			}
		}
	}
	
	
	/**
	 * collect data to be used on next init
	 *
	 */
	public function data_collect() {
		if($this->is_last_call()) {
			if(is_admin() && current_user_can('install_plugins') && function_exists('get_plugin_data')) {
				$data = $this->globals('all');
				$lcun_to_update = array();
				
				// check all the registered plugins
				for($a=0; $a < count($data['id']); $a++) {
					$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $data['basepath'][$a]);
					$curr_version = (float)$plugin_data['Version'];	
		
					$rm_data = $this->get_remote( $data['url'][$a] );
					if($rm_data) { 
						$rm_arr = (array)json_decode($rm_data);

						if(isset($rm_arr['version']) && (float)$rm_arr['version'] > $curr_version) {
							$lcun_to_update[ $data['id'][$a] ] = array(
								'name' 		=> $plugin_data['Name'],
								'basepath'	=> $data['basepath'][$a],
								'old_ver'	=> $curr_version,
								'new_ver'	=> $rm_arr['version'],
								'plugin_uri'=> $plugin_data['PluginURI'], 
								'note' 		=> (isset($rm_arr['note']) && !empty($rm_arr['note'])) ? trim(preg_replace('/\s\s+/', '', $rm_arr['note'])) : ''
							);
						}
					}
				}

				$return = set_transient('lcun_cache', $lcun_to_update, 7200); // cache each 2 hours
				
				if($return && !isset($_REQUEST['lcun_redirect'])) {
					// set security cookie
					setcookie( md5(get_site_url()).'_lcun_sc' , "1", time()+7200);
					
					if(strpos($this->curr_url(), '?') === false) {
						header('location: '. $this->curr_url().'?lcun_redirect=1' );
					} else {
						header('location: '. $this->curr_url().'&lcun_redirect=1' );	
					}
				} 
			}
		}
	}


	// get current page url
	private function curr_url() {
		$pageURL = 'http';
		
		if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
		$pageURL .= "://" . $_SERVER['HTTP_HOST'].$_SERVER["REQUEST_URI"];
	
		return $pageURL;
	}


	/**
	 * set $GLOBALS for the current plugin
	 *
	 * @param string $subj - data index
	 * @param (optional) string $action - get/set
	 * @param (optional) string $subj_val 
	 *
	 * @return none / data requested
	 */
	public function globals($subj, $action = 'get', $subj_val = false) {
		if(!isset($GLOBALS['lcun_data'])) {
			$GLOBALS['lcun_data'] = array();
		}

		// set globals
		if($action == 'set') {
			$GLOBALS['lcun_data'][ $subj ][] = $subj_val;
		}
		
		// get globals
		else {
			if($subj == 'all') {return $GLOBALS['lcun_data'];}
			return (isset($GLOBALS['lcun_data'][ $subj ][ $subj_val ])) ? $GLOBALS['lcun_data'][ $subj ][ $subj_val ] : '';
		}
		
		return true;
	}
	
	
	/**
	 * use cURL to get JSON data from the endpoint
	 *
	 * @param string $url
	 * @return string data or false if call fails
	 *
	 */
	public function get_remote($url) {
		if(!function_exists('curl_version')) {return false;}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_USERAGENT, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 8); //timeout in seconds
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		
		$data = curl_exec($ch);
		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		return ($http_status == '200') ? $data : false;
	}	
	
	
	///////// show messages
	
	
	// plugins update count
	public function update_count($update_data) {
		$curr_plug_count = (float)$update_data['counts']['plugins'];
		$update_data['counts']['plugins'] = $curr_plug_count + count( $GLOBALS['lcun_to_update'] );
		
		$curr_tot_count = (float)$update_data['counts']['total'];
		$update_data['counts']['total'] = $curr_tot_count + count( $GLOBALS['lcun_to_update'] );
		
		return $update_data;
	}

	
	// WP core updates screen
	public function upgrade_core_message() {
		$notes = '';
		echo '<h3>Premium Plugins</h3>
		<p>'. __('The following premium plugins have new versions available', 'lcum_ml').'</p>
		
		<table class="update-premium-plugins-table widefat" cellspacing="0">
			<tbody class="plugins">
		';

		foreach($GLOBALS['lcun_to_update'] as $pid => $data) {	
			echo '
			<tr style="box-shadow: 0 -1px 0 rgba(0, 0, 0, 0.1) inset;">
				<td>';
				
				if(!empty($data['note'])) {
					$pl_note = ' or <a class="lcun_update_note" pcun_name="'.$data['name'].'" rel="pcunh_'.$pid.'" href="">view update notes</a>';
					$notes .= '<div id="pcunh_'.$pid.'" style="display: none;">'.$data['note'].'</div>';
				}
				else {$pl_note = '';}
				
				echo '
					<p><strong>'.$data['name'].'</strong></br>
						'. __('You have version').' '.$data['old_ver'].' '. __('installed').'. 
						<a href="http://codecanyon.net/downloads" title="'.$data['name'].'" target="_blank">Download v'.$data['new_ver'].'</a>'.$pl_note.'
					</p>
				</td>
			</tr>
			';	
			  
		}
		
		echo '</tbody></table>';
		
		// thickbox integration
		if(!empty($notes)) :
			echo $notes;
		?>
			<script type="text/javascript">
            jQuery(document).ready(function(e) {
                jQuery('body').delegate('.lcun_update_note', "click", function (e) {
                    e.preventDefault();
                    tb_show('Plugin Information: '+ jQuery(this).attr('pcun_name') , '#TB_inline?height=600&width=640&inlineId='+ jQuery(this).attr('rel'));
					setTimeout(function() {
						jQuery('#TB_window').css('background-color', '#fff').css('background-image', 'none');
					}, 50);
                });
            });
            </script>
        <?php
		endif;
	}
	
	
	// dinamically add code to show updates in plugins list
	public function plugin_list_message() {
		global $current_screen;
		if(isset($current_screen->base) && $current_screen->base == 'plugins' && is_array($GLOBALS['lcun_to_update'])) {
			
			// array of elements to use with javascript
			$id = array();
			$name = array();
			$new_ver = array();
			$notes = array();
			
			foreach($GLOBALS['lcun_to_update'] as $pid => $data) {	
				$bp_arr = explode(DIRECTORY_SEPARATOR, $data['basepath']);
				$id[] = sanitize_title($data['name']);
				$name[] = $data['name'];
				$new_ver[] = $data['new_ver'];
				$notes[] = addslashes($data['note']);
			}
	
			wp_enqueue_script('jquery');
			?>
            <script type="text/javascript">
			var id 		= ['<?php echo implode("','", $id) ?>'];
			var lcun_name = ['<?php echo implode("','", $name) ?>']; // safe name with prefix
			var new_ver = ['<?php echo implode("','", $new_ver) ?>'];
			var notes 	= ['<?php echo implode("','", $notes) ?>'];
			
			jQuery(document).ready(function(e) {
				jQuery.each(id, function(i, v) {
					if(notes[i] != '') {
						var pl_note = ' or <a class="lcun_update_note" pcun_name="'+ lcun_name[i] +'" rel="pcunh_'+ v +'" href="">view update notes</a>';
						jQuery('body').append('<div id="pcunh_'+ v +'" style="display: none;">'+ notes[i] +'</div>');
					}
					else {var pl_note = '';} 
					
					var code = '\
					<tr class="plugin-update-tr">\
						<td colspan="3" class="plugin-update colspanchange">\
							<div class="update-message">\
							There is a new version of '+ lcun_name[i] +' available. \
							<a href="http://codecanyon.net/downloads" title="'+ lcun_name[i] +'" target="_blank">Download version '+ new_ver[i] +'</a>'+ pl_note +'.</div>\
						</td>\
					</tr>';
					jQuery('#'+v).addClass('update');
					jQuery('#'+v).after(code);
					
					<?php 
					// if WP 4 - hide "view details" link
					if((float)substr(get_bloginfo('version'), 0, 3) >= 4) {
						?>
						jQuery('#'+v).find('a.thickbox').remove();
						var v4_txt = jQuery('#'+v).find('.plugin-version-author-uri').html(); 
						jQuery('#'+v).find('.plugin-version-author-uri').html( v4_txt.slice(0,-2) );
						<?php	
					} 
					?>
				});
				
				// show thickbox
				jQuery('body').delegate('.lcun_update_note', "click", function (e) {
					e.preventDefault();
					tb_show('Plugin Information: '+ jQuery(this).attr('pcun_name') , '#TB_inline?height=600&width=640&inlineId='+ jQuery(this).attr('rel'));
					setTimeout(function() {
						jQuery('#TB_window').css('background-color', '#fff').css('background-image', 'none');
					}, 50);
				});
			});
			</script>
            <?php	
		}
	}
}


// erase cache on plugins deactivation/activation
add_action('activate_plugin', 'lcun_erase_cache');
add_action('deactivated_plugin', 'lcun_erase_cache');

function lcun_erase_cache() {
	delete_transient('lcun_cache');
	setcookie( md5(get_site_url()).'_lcun_sc' , "", time()-3600);
}
