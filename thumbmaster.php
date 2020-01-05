<?php
/*
Plugin Name: Thumbmaster
Description:
Version: 2.9
Plugin URI:
Author: Attila Seres
Author URI:
*/

if (!class_exists('thumbmaster')) :
    class thumbmaster
    {
        const OPTIONS = 'default_thumbnail_options';
        public static $default_thumbnail_id;
        public static $attachment_base_id;
        public static $attachment_id;
        public static $sizes;
        private static $_instance;
        private static $regeximage;
        public function instance()
        {
            if (!isset(self::$_instance)) {
                self::$_instance =  new self();
            }
            return self::$_instance;
        }

        public function __construct()
        {
            self::$attachment_base_id = self::$attachment_id = PHP_INT_MAX-1000000;
            self::$attachment_id++;
            add_theme_support('post-thumbnails');
            $options = self::get_option();
            if ($default_thumbnail = $options['default_thumbnail']) {
                self::$default_thumbnail_id = self::get_attachment_id($default_thumbnail);
            }

            if (is_admin()) {
                add_action('admin_init', array($this,'admin_init'));
                add_action('admin_menu', array($this, 'admin_menu'));
            } //else {
            add_action("wp_loaded", array($this,'thumbmaster_init'));
            add_action('wp_head', function () {
                ?><style>img.wp-post-image{object-fit:cover}</style><?php
            });
            //}
            $dir = dirname(__FILE__).'/thumbmaster';
            if (file_exists($dir)) {
                foreach (scandir($dir) as $file) {
                    if (substr($file, -4)=='.php') {
                        require_once($dir.'/'.$file);
                    }
                }
            }
        }

        public function thumbmaster_init()
        {
            add_filter("get_attached_file", array($this,"get_attached_file"), 100, 2);
            add_filter("wp_get_attachment_url", array($this,"wp_get_attachment_url"), 100, 2);
            add_filter("image_downsize", array($this,"image_downsize"), 100, 3);
            add_filter("get_post_metadata", array($this,"get_post_metadata"), 100, 4);
            add_filter("wp_get_attachment_image_attributes", array($this,"wp_get_attachment_image_attributes"), 100, 3);
            add_filter('thumbmaster_remote_images', array($this,'get_youtube_images'), 10, 2);
        }

        //start filters
        public function wp_get_attachment_image_attributes($attr, $attachment, $size)
        {
            $class = 'wp-image-'. $attachment->ID;
            if (strpos($attr['class'], $class)===false) {
                $attr['class'].=' '. $class;
            }
            return $attr;
        }

        public function get_attached_file($file, $attachment_id)
        {
            if (self::is_virtual_attachment($attachment_id)) {
                if ($pos = stripos($file, 'http', 1)) {
                    $file = substr($file, $pos);
                }
            } //remote image
            return $file;
        }

        public function wp_get_attachment_url($file, $attachment_id)
        {
            if (self::is_virtual_attachment($attachment_id)) {
                return get_attached_file($attachment_id);
            } else {
                return $file;
            }
        }

        public function get_post_metadata($dummy, $object_id, $meta_key, $single)
        {
            if (!$single || $meta_key != '_thumbnail_id') {
                return;
            }
            if (!$meta_cache = wp_cache_get($object_id, 'post_meta')) {
                $meta_cache = update_meta_cache('post', array($object_id));
                $meta_cache = $meta_cache[$object_id];
            }
            if (isset($meta_cache[$meta_key])) {
                return maybe_unserialize($meta_cache[$meta_key][0]);
            } //use existing thumbnail

            $post = get_post($object_id);

            if (in_array($post->post_status, ['auto-draft', 'inherit'])) {
                return;
            }

            if ($ids = self::get_local_images($post->post_content)) {
                return self::set_post_thumbnail($object_id, $meta_cache, $ids[0]);
            } // attach first local image
            elseif ($images = self::get_remote_images($post->post_content)) {
                $src = $images[0];
            } // use first remote image
            elseif (self::$default_thumbnail_id) {
                return self::set_post_thumbnail($object_id, $meta_cache, self::$default_thumbnail_id);
            } // use fallback image if defined
            else {
                return self::set_post_thumbnail($object_id, $meta_cache, apply_filters('thumbmaster_id', 0, $object_id));
            } //no image at all: trick metacache w/null thumbnail to prevent further timewasting

            //found first remote image - the magic happens: inject as virtual attachment into the cache
            $attachment_id = self::$attachment_id++; //create a fake id
            $width=$height=0;
            $mimetype = 'image/';

            if (in_array(strtolower(substr($src, -4)), array('.gif','.bmp','.png'))) {
                $mimetype.= strtolower(substr($src, -3));
            } else {
                $mimetype.= 'jpeg';
            }
            $attachment = new WP_Post((object)array('ID' => $attachment_id,'post_type' => 'attachment','post_title' => basename($src),'post_mime_type' => $mimetype)); //create virtual attachment
            wp_cache_set($attachment_id, $attachment, 'posts'); //inject virtual attachment into post cache
            $meta = array('_wp_attached_file' => array($src) ,'_wp_attachment_metadata' => array( serialize(array('width' => $width,'height' => $height,'file' => $src))));
            wp_cache_set($attachment_id, $meta, 'post_meta'); //inject virtual attachment into meta cache
            update_meta_cache('post', array($attachment_id)); //force update meta cache
            self::set_post_thumbnail($object_id, $meta_cache, $attachment_id); // attach virtual attachment to post
//if(is_super_admin()) echo $object_id.'*'.$attachment_id.'*'.$src.'<hr>';
            return $attachment_id;
        }

        public function image_downsize($dummy, $id, $size)
        {
            if (!$img_url = wp_get_attachment_url($id)) {
                return false;
            }
            list($width, $height, $crop) = self::get_size($size);
            if (self::is_virtual_attachment($id)) {
                return array( $img_url, $width == 9999 ? null : $width, $height == 9999 ? null : $height, false );
            } // remote image

            if (!$crop || !self::get_option('image_resize_dimensions')) {
                return false;
            } // let the wordpress default function handle this

            $is_intermediate = false;
            $img_url_basename = wp_basename($img_url);

            // try for a new style intermediate size
            if ($intermediate = self::image_get_intermediate_size($id, array($width,$height))) {
                $img_url = str_replace($img_url_basename, $intermediate['file'], $img_url);
                $is_intermediate = true;
            } elseif ($size == 'thumbnail') {
                // fall back to the old thumbnail
                if (($thumb_file = wp_get_attachment_thumb_file($id))) {
                    $img_url = str_replace($img_url_basename, wp_basename($thumb_file), $img_url);
                    $is_intermediate = true;
                }
            }

            return array( $img_url, $width == 9999 ? null : $width, $height == 9999 ? null : $height, $is_intermediate );
        }

        public function get_youtube_images($images, $html)
        {
            if (!empty($images)) {
                return $images;
            }
            // dig for embedded youtube videos
            $images = array();

            if ($html) {
                $html = rawurldecode($html);
                foreach (self::match_youtube('/(youtube\.com\/watch(.*)?[\?\&]v=|youtu\.be\/)([a-zA-Z0-9-_]+)/i', $html, 3) as $img) {
                    if (!in_array($img, $images)) {
                        $images[] = $img;
                    }
                }
                foreach (self::match_youtube('/(youtube|ytimg)\.com\/(e|v|embed|vi)\/([a-zA-Z0-9-_]+)/i', $html, 3) as $img) {
                    if (!in_array($img, $images)) {
                        $images[] = $img;
                    }
                }
            }
            return $images;
        }
        /*
                // allow upscaled thumbnail generation
                function image_resize_dimensions($default, $orig_w, $orig_h, $new_w, $new_h, $crop){
                    if ( !$crop ) return null; // let the wordpress default function handle this

                    $aspect_ratio = $orig_w / $orig_h;
                    $size_ratio = max($new_w / $orig_w, $new_h / $orig_h);

                    $crop_w = round($new_w / $size_ratio);
                    $crop_h = round($new_h / $size_ratio);

                    $s_x = floor( ($orig_w - $crop_w) / 2 );
                    $s_y = floor( ($orig_h - $crop_h) / 2 );

                    return array( 0, 0, (int) $s_x, (int) $s_y, (int) $new_w, (int) $new_h, (int) $crop_w, (int) $crop_h );
                }
        */
        //end filters

        public static function is_virtual_attachment($attachment_id)
        {
            return $attachment_id > self::$attachment_base_id;
        }

        public static function get_attachment_id($image_url)
        {
            global $wpdb;
            $attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE guid='%s';", $image_url));
            return $attachment[0];
        }

        public static function set_post_thumbnail($object_id, $meta_cache, $attachment_id)
        {
            $meta_cache['_thumbnail_id'] = array($attachment_id);
            wp_cache_set($object_id, $meta_cache, 'post_meta'); //attach attachment to post in meta cache
            update_meta_cache('post', array($object_id)); //force update meta cache
            return $attachment_id;
        }

        public static function get_local_images($html)
        {
//            if ($_SERVER['HTTPS']) $html = str_replace(str_replace('https://', 'http://', site_url()), site_url(), $html);
            $regex='/<img.+class=.+wp-image-(\d+).+>/i';
            //			if(preg_match_all($regex, $html, $ids, PREG_SET_ORDER)) $ids = array_filter(array_map(function($a) { return strpos($a[0], site_url())===false ? NULL : self::validateimage($a[1]); },$ids));
            if (preg_match_all($regex, $html, $ids, PREG_SET_ORDER)) {
                $ids = array_filter(array_map('self::local_src', $ids));
            }
            return apply_filters('thumbmaster_local_images', $ids, $html);
        }

        public static function local_src($a)
        {
            return strpos($a[0], site_url())===false ? null : self::validateimage($a[1]);
        }

        public static function get_remote_images($html)
        {
//            if ($_SERVER['HTTPS']) $html = str_replace(str_replace('https://', 'http://', site_url()), site_url(), $html);
            $images = array();
            $doc = new DOMDocument();
            //				@$doc->loadHTML('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd"><html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en"><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head><body>'.$html.'</body></html>');
            @$doc->loadHTML(mb_convert_encoding($html, 'html-entities', 'UTF-8'));
            if ($elements = $doc->getElementsByTagName('img')) {
                foreach ($elements as  $element) {
                    if ($src = self::validateimage(trim($element->getAttribute('src')))) {
                        $images[] = $src;
                    }
                }
            }
            return apply_filters('thumbmaster_remote_images', $images, $html);
        }

        public static function remote_src($a)
        {
            return self::validateimage($a[1]);
        }

        public static function urlencode($string)
        {
//    $entities = array('%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D');
//    $replacements = array('!', '*', "'", "(", ")", ";", ":", "@", "&", "=", "%20", "$", ",", "/", "?", "%", "#", "[", "]");
//    return str_replace($entities, $replacements, urlencode(urldecode($string)));
            return strtr(urlencode(urldecode($string)), array(
'%21' => '!',
'%2A' => '*',
'%27' => '\'',
'%28' => '(',
'%29' => ')',
'%3B' => ';',
'%3A' => ':',
'%40' => '@',
'%26' => '&',
'%3D' => '=',
//'+' => '%20',
'%2B' => '+',
'%24' => '$',
'%2C' => ',',
'%2F' => '/',
'%3F' => '?',
'%25' => '%',
'%23' => '#',
'%5B' => '[',
'%5D' => ']',
    ));
        }
        public static function validateimage($image)
        {
            if (substr($image, 0, 2)=='//') {
                $image='http:'.$image;
            }
            //   $image=str_ireplace('https://','http://', $image);
            if (substr(strtolower($image), 0, 4)!='http') {
                return false;
            }
            $image = self::urlencode(html_entity_decode($image, ENT_QUOTES, 'UTF-8'));
            if (preg_match('/safe_image\.php.*url=([^&]+)/i', $image, $match)) {
                if (substr($match[1], 0, 4)=='http') {
                    return rawurldecode($match[1]);
                }
            }
            if(!isset(self::$regeximage)) {
              $excludes = [
      'noscript=',
      'pixel.wp.com',
      'blank',
      '.svg',
      'zoom',
      'tracker',
      'doubleclick',
      'pheedo',
      'spacer',
      'advert',
      'imageads',
      'player',
      'feed',
      'icon',
      'plugins',
      'stats',
      'tweetmeme',
      'feedburner',
      'paidcontent',
      'twitter',
      'phpAds',
      'digg',
      'button',
      'gomb',
      'avatar',
      'adview',
      'kapjot',
      'loading',
      'badge',
      'hirdetes',
      'empty',
      ];
      self::$regeximage = '/'.strtr(implode('|',$excludes),['.' => '\\.', '/' => '\\/']).'/';
            }
            if(preg_match(self::$regeximage, $image, $match)) return false;

            $image=str_replace('"', '', $image);
            $image=str_replace("'", '', $image);
            $image=trim($image);
            $image=str_replace(' ', '%20', $image);
            $image=str_replace('&amp;', '&', $image);
            return $image;
        }

        public static function match_youtube($regex, $html, $i = 1)
        {
            $images = array();
            @preg_match_all($regex, $html, $matches, PREG_SET_ORDER);

            if (count($matches)) {
                foreach ($matches as $match) {
                    if (!$id = $match[$i]) {
                        continue;
                    }
                    if ($pos = strpos($id, 'endofvid')) {
                        $id = substr($id, 0, $pos);
                    }
                    if ($id) {
                        $images[] = "https://img.youtube.com/vi/" . $id . "/0.jpg";
                    }
                }
            }
            return $images;
        }

        public static function get_size($size = 'thumbnail')
        {
            if (empty($size)) {
                $size = 'thumbnail';
            }
            list($width, $height, $crop)=self::getsizes($size);
            return array($width, $height, $crop);
        }

        public static function getsizes($size=null)
        {
            if (is_array($size)) {
                list($width, $height, $crop)=$size;
                return array($width,$height,isset($crop) ? $crop : true);
            }
            if (!isset(self::$sizes)) {
                self::$sizes = array();
                global $_wp_additional_image_sizes;
                foreach (get_intermediate_image_sizes() as $_size) {
                    if (in_array($_size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
                        self::$sizes[ $_size ] = array( get_option($_size."_size_w"), get_option($_size."_size_h"), get_option($_size."_crop"));
                    } elseif (isset($_wp_additional_image_sizes[ $_size ])) {
                        self::$sizes[ $_size ] = array($_wp_additional_image_sizes[ $_size ]['width'], $_wp_additional_image_sizes[ $_size ]['height'], $_wp_additional_image_sizes[ $_size ]['crop']);
                    }
                }
            }

            return $size ? self::$sizes[$size] : self::$sizes;
        }

        public static function image_get_intermediate_size($post_id, $size = array())
        {
            if (empty($size) || ! is_array($imagedata = wp_get_attachment_metadata($post_id))) {
                return false;
            }

            $data = array();

            // Find the best match as '$size' is an array.
            $candidates = array();

            foreach ((array)$imagedata['sizes'] as $_size => $data) {
                // If there's an exact match to an existing image size, short circuit.
                if ($data['width'] == $size[0] && $data['height'] == $size[1]) {
                    //		                $candidates[ $data['width'] * $data['height'] ] = $data;
                    $candidates=array( $data['width'] * $data['height'] => $data);
                    break;
                }

                if ($data['width'] >= $size[0] && $data['height'] >= $size[1]) {
                    $candidates[ $data['width'] * $data['height'] ] = $data;
                }
            }

            if (! empty($candidates)) {
                // Sort the array by size if we have more than one candidate.
                if (count($candidates) > 1) {
                    ksort($candidates);
                }
                $data = array_shift($candidates);
            } else {
                return false;
            }

            // include the full filesystem path of the intermediate file
            if (empty($data['path']) && !empty($data['file'])) {
                $file_url = wp_get_attachment_url($post_id);
                $data['path'] = path_join(dirname($imagedata['file']), $data['file']);
                $data['url'] = path_join(dirname($file_url), $data['file']);
            }

            return apply_filters('image_get_intermediate_size', $data, $post_id, $size);
        }

        public static function get_option($field=null, $default=null)
        {
            $options = get_option(self::OPTIONS, array());
            return $field ? (isset($options[$field]) ? $options[$field] : $default) : $options;
        }

        // admin stuff
        public function admin_init()
        {
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array( $this, 'action_links' ), 10, 2);
            if ((defined('DOING_AJAX') || $GLOBALS['pagenow'] == 'edit.php') && empty($GLOBALS['typenow'])) {
                $GLOBALS['typenow'] = empty($_REQUEST['post_type']) ? 'post' : $_REQUEST['post_type'];
            }
            global $typenow;
            add_filter('manage_'.$typenow.'_posts_columns', array($this,'thumbnail_column'), 1000000000000000);
            add_action('manage_'.$typenow.'_posts_custom_column', array($this,'manage_posts_custom_column'), 10, 2);
            //die($GLOBALS['pagenow']);
            switch ($GLOBALS['pagenow']) {
   case 'edit.php':
/*
add_action('admin_head',function() {
    if(post_type_supports($GLOBALS['typenow'], 'thumbnail')) {
       $this->thumbmaster_init();
    }
});
*/
   break;
   case 'options-media.php':
add_action('admin_print_styles', function () {
    ?><style>.default-thumbnail{width:200px}</style><?php
});
add_action('admin_footer', array($this, 'media_upload_javascript'));
add_action('admin_print_scripts', array($this, 'admin_enqueue_scripts_upload_image'));
break;
}
        }

        public function admin_menu()
        {
            if (class_exists('download_plugin')) {
                new download_plugin('options-media.php');
            }

            add_settings_section('thumbmaster', __('Thumbmaster'), null, 'media');
            register_setting('media', self::OPTIONS);
            add_settings_field('default_thumbnail', __('Default image:'), array($this, 'default_thumbnail_field'), 'media', 'thumbmaster');
            add_settings_field('image_resize_dimensions', __('Flexible image scaling:'), array($this, 'image_resize_dimensions_field'), 'media', 'thumbmaster');
        }

        public function action_links($actions, $plugin_file)
        {
            $actions[] = sprintf('<a href="%s" title="Thumbmaster settings">Settings</a>', admin_url('options-media.php'));
            return $actions;
        }

        public function default_thumbnail_field()
        {
            $default_thumbnail = self::get_option('default_thumbnail');
            $id = 'default_thumbnail';
            $name =self::OPTIONS.'[default_thumbnail]'; ?>
        <p>
            <input id="<?php  echo $id  ?>" name="<?php  echo $name  ?>" class="normal-text" type="text" size="36"  value="<?php  echo $default_thumbnail; ?>" />
            <input class="upload_image_button button button-primary" type="button" value="Upload Image" />
        </p>
<?php  if (!empty($default_thumbnail)) : ?>
<p><img src="<?php  echo $default_thumbnail  ?>" class="default-thumbnail"></p>
<?php
            endif;
        }


        public function image_resize_dimensions_field()
        {
            $image_resize_dimensions = self::get_option('image_resize_dimensions') ? true : false;
            $id = 'image_resize_dimensions';
            $name =self::OPTIONS.'[image_resize_dimensions]';
            //echo '<pre>'.var_export($GLOBALS['submenu'],true).'</pre>';?>
		<p><input class="checkbox" type="checkbox"<?php checked($image_resize_dimensions); ?> id="<?php echo $id ?>" name="<?php echo $name ?>" value="1" /></p>
<?php
        }

        public function admin_enqueue_scripts_upload_image()
        {
            wp_enqueue_script('media-upload');
            wp_enqueue_script('thickbox');
            wp_enqueue_style('thickbox');
        }


        public function media_upload_javascript()
        {
            ?>
<script type="text/javascript">
jQuery(document).ready(function($) {
    $(document).on("click", ".upload_image_button", function() {
        jQuery.data(document.body, 'prevElement', $(this).prev());
        window.send_to_editor = function(html) {
        	response = jQuery("<div>" + html + "</div>").find('img');
            imgurl = response.attr('src');
            inputText = jQuery.data(document.body, 'prevElement');
            if(inputText != undefined && inputText != '')
            {
                inputText.val(imgurl);
            }
            tb_remove();
        };
        tb_show('', 'media-upload.php?type=image&tab=library&TB_iframe=true');
        return false;
    });
});
</script>
<?php
        }

        public function thumbnail_column($columns)
        {
            if (isset($columns['thumbnail'])) {
                return $columns;
            }
            if (!post_type_supports($GLOBALS['typenow'], 'thumbnail')) {
                return $columns;
            }
            $columns['thumbnail'] = __('Thumbnail');
            //			   add_action('manage_posts_custom_column',  array($this,'manage_posts_custom_column'));?><style>.column-thumbnail{width:10%}.column-thumbnail img.wp-post-image{object-fit:cover}</style><?php
            return $columns;
        }


        public function manage_posts_custom_column($name)
        {
            global $post;
            switch ($name) {
                case 'thumbnail':
                    $thumbnail = get_the_post_thumbnail($post->ID, array(100,100));
                    echo $thumbnail;
            }
        }
    } //class
thumbmaster::instance();
endif;
