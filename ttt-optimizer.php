<?php
/*
Plugin Name: TTT Optimizer
Plugin URI: http://www.33themes.com
Description: JS and CSS compressor. Simple and fast.
Version: 0.5.1
Author: 33 Themes UG i.Gr.
Author URI: http://www.33themes.com
*/

//define('TTT_OPTIMIZER_DEBUG',true);
define('TTTINC_OPTIMIZER', dirname(__FILE__) );

function ttt_autoload_optimizer( $class ) {
    
    if ( 0 !== strpos($class, 'TTTLoadmore')
        && 0 !== strpos($class, 'CSSmin')
        && 0 !== strpos($class, 'JSMin') ) return;

    if (class_exists('CSSmin')) return;
    if (class_exists('JSMin')) return;
    
    $file = TTTINC_OPTIMIZER . '/class/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
        return true;
    }
         
    throw new Exception("Unable to load $class at ".$file);
}

if ( function_exists( 'spl_autoload_register' ) ) {
    spl_autoload_register( 'ttt_autoload_optimizer' );
} else {
    //require_once TTTINC_OPTIMIZER . '/class/TTTLoadmore_Common.php';
}

class TTTOptimizer_Common {
    var $srcs;
    var $cache_time = 3600;
    var $minify = false;

    const sname = 'tttoptimizer';

    public function reset() {
        
        unset( $this->srcs );
        unset( $this->_cache );
        unset( $this->_csing );
        unset( $this->_sing );

        $this->do_optimization = false;
        if (  defined('TTT_OPTIMIZER_DEBUG') && TTT_OPTIMIZER_DEBUG == true ) {
            $this->do_optimization = true;
        }
    }

    public function cache_control( $file ) {

        if ( defined('TTT_OPTIMIZER_DEBUG') && TTT_OPTIMIZER_DEBUG == true ) {
            $this->do_optimization = true;
            return false;
        }

        if ( ( defined('WP_DEBUG') && WP_DEBUG == true ) || defined('SCRIPT_DEBUG') && SCRIPT_DEBUG == true ) {
            $this->do_optimization = false;
            return false;
        }

        if (!is_file($file)) {
            $this->do_optimization = true;
            return false;
        }

        $file_time = filemtime($file) + $this->cache_time;
        $time = time();
        if ( $file_time <= $time ) {
            $this->do_optimization = true;
            return true;
        }

        return true;
    }

    public function sing() {
        if (count($this->srcs) <= 0 ) return false;

        if ( $this->_sing ) return $this->_sing;

        if ( defined('TTT_OPTIMIZER_DEBUG') && TTT_OPTIMIZER_DEBUG == true ) {
            echo '<!-- ttt-optimizer-sing :: ';
            echo var_export(serialize($this->srcs));
            echo '-->';
        }
        $this->_sing = md5( serialize($this->srcs) );
        $this->_cache = $this->get( $this->ext.'_'.$this->sing );

        return $this->_sing;
    }

    /**
     * Get/Download content of the file (js or css) to concat in the general js or css
     */
    public function download($src) {

        if (isset($src['content'])) {
            return $src['content'];
        }

        $_base = get_site_url();
        $_base = preg_replace(array('/^https*\:\/\//','/^\/\//'),'',$_base);
        $_src = preg_replace(array('/^https*\:\/\//','/^\/\//'),'',$src['file']);


        // Get content from local file
        if (strpos($_src, $_base) === 0) {
            $_src = str_replace($_base, '', $_src);
            $_src = preg_replace('/\?.*/','',$_src);
            if (file_exists(ABSPATH.$_src)) {
                if (!isset($src['media'])) {
                    return file_get_contents( ABSPATH.$_src);
                }
                else {
                    var_dump($src['media']);
                    $_txt = "\n";
                    $_txt .= "/* TTT Optimizer - Media */";
                    $_txt .= "\n";
                    $_txt .= '@media '.$src['media'].' {'."\n";
                    $_txt .= file_get_contents( ABSPATH.$_src );
                    $_txt .= '}'."\n";
                    return $_txt;
                }
            }
        }

        // Get content from remove file
        $ch = curl_init($src['file']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        // $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // $header = $this->get_headers_from_curl_response( substr($response, 0, $header_size) );
        // $body = substr($response, $header_size);
        curl_close($ch);
        return $response;
        
    }

    public function filter_file( $uri, $string ) {
        return $string;
    }

    public function control_file($src) {
        return fwrite($this->temp, $this->filter_file($src['file'], $this->download($src))."\n" );
    }

    public function add_async_call() {
        if ( $this->ext == 'js' ) {
            $asyncjs = "
            // TTT-Optimizer - Async Event for jQuery
            if ( jQuery ) {
                jQuery(document).ready(function($) {
                    $.each(tttopt_callbacks, function(i, func) {
                        $(func);
                    });
                });
                jQuery(window).load();
                jQuery(document).ready();
            }
            // TTT-Optimizer - Async Event for jQuery
            ";
            fwrite($this->temp, $asyncjs );
        }
    }

    public function del( $name ) {
        return delete_option( self::sname . '_' . $name );
    }
    
    public function get( $name ) {
        return get_option( self::sname . '_' . $name );
    }
    
    public function set( $name, $value ) {
        if (!get_option( self::sname . '_' . $name ))
            add_option( self::sname . '_' . $name, $value);
        
        update_option( self::sname . '_' . $name , $value);
    }

    public function shutdown() {

        if ( count($this->srcs) <= 0 ) return true;
        if ( $this->do_optimization === false ) return true;


        $this->temp = tmpfile();
        $metaDatas = stream_get_meta_data($this->temp);

        if ($this->_cache) return false;

        foreach ($this->srcs as $src) {
            $this->control_file($src);
        }

        if ($this->async == true)
            $this->add_async_call();
    
        mkdir(ABSPATH.'/wp-content/ttt-optimizer');
        rename( $metaDatas['uri'], ABSPATH.'/wp-content/ttt-optimizer/'.$this->sing().'.'.$this->ext );
        chmod(ABSPATH.'/wp-content/ttt-optimizer/'.$this->sing().'.'.$this->ext, 0644 );

        fclose($this->temp);
    }

    public function html_satinize($buffer) {

        $search = array(
            '/\>[^\S ]+/s',  // strip whitespaces after tags, except space
            '/[^\S ]+\</s',  // strip whitespaces before tags, except space
            '/(\s)+/s'       // shorten multiple whitespace sequences
        );

        $replace = array(
            '>',
            '<',
            '\\1'
        );

        $buffer = preg_replace($search, $replace, $buffer);

        return $buffer;
    }
}

class TTTOptimizer_JS extends TTTOptimizer_Common {

    function __construct( $async = false ) {
        $this->async = $async;
        $this->reset();
        $this->ext = 'js';
        //register_shutdown_function( array(&$this,'shutdown') );

        if (  defined('TTT_OPTIMIZER_JS_MINIFY') && TTT_OPTIMIZER_JS_MINIFY === true )
            $this->minify = true;
            
    }

    public function filter_file( $uri, $string ) {
        if ( !$this->minify )
            return $string;
        else
            return JSMin::minify( $string ).';';
    }

    public function start( &$buffer ) {
        $s = preg_replace_callback('/<script\s+type=\'text\/javascript\'\s+src=\'([^\']+)\'><\/script>/i', array($this,'add'), $buffer );
        unset($buffer);

        if ( count($this->srcs) <= 0 ) return $s;

        if ( $this->async == false) {
            $this->cache_control( ABSPATH.'/wp-content/ttt-optimizer/'.$this->sing().'.js' );
            return $s."\n"."<script type='text/javascript' src='".get_bloginfo('wpurl')."/wp-content/ttt-optimizer/".$this->sing().".js'></script>\n";
        }
        else {
            return $s." ";
        }
    }

    public function add( $src ) {
        $this->srcs[] = array( 'file' => $src[1] );
        $this->html .= "<script type='text/javascript' src='".$src[1]."'></script>\n";
    }

    public function print_out() {
        $this->cache_control( ABSPATH.'/wp-content/ttt-optimizer/'.$this->sing().'.'.$this->ext );
        $this->shutdown();

        echo "\n"."<script type='text/javascript' src='".get_bloginfo('wpurl')."/wp-content/ttt-optimizer/".$this->sing().".js' async></script>\n";
    }

    public function download($src) {
        return parent::download($src).';';
    }

}

class TTTOptimizer_CSS extends TTTOptimizer_Common {

    function __construct( $async ) {
        $this->async = $async;
        $this->reset();
        $this->ext = 'css';

        if (  defined('TTT_OPTIMIZER_CSS_MINIFY') && TTT_OPTIMIZER_CSS_MINIFY === true )
            $this->minify = true;

        //register_shutdown_function( array(&$this,'shutdown') );
    }

    public function filter_file( $uri, $string ) {

        $this->replace_url = preg_replace('/^(http|https)\:\/\//','//',$uri);
        $regexp = "/url\([\"\']*([^\)\"\']+)[\"\']*\)/i";

        if ( !$this->minify ) {
            return preg_replace_callback( $regexp, array( &$this, 'url_replace'), $string );
        }
        else {
            $minify = new CSSmin();
            $minify->set_memory_limit('64M');
            return $minify->run( preg_replace_callback( $regexp, array( &$this, 'url_replace'), $string ) );
        }
    }

    public function url_replace( $s ) {

        if ( preg_match('/data:(application|image)/i',$s[0]) )
            return $s[0];

        if ( preg_match('/(https|http):\/\//i',$s[0]) )
            return $s[0];

        $a = explode('/',$this->replace_url);
        array_pop($a);

        return 'url("'.implode('/',$a).'/'.$s[1].'")';
    }

    public function start( &$buffer ) {
        // <link rel='stylesheet' id='videojs-css'  href='http://callwey-dev.hting/wp-content/plugins/featured-video-plus/css/videojs.min.css?ver=1.8' type='text/css' media='' />

        $s1 = preg_replace_callback(
            array(
                '/<link(target, link)k\s+rel=\'stylesheet\'\s+id=\'([^\']+)\'\s+href=\'([^\']+)\'\s+type=\'text\/css\'\s+media=\'([^\']+)\'[^\>]*>/i',
                '/<link\s+rel=\'stylesheet\'\s+id=\'([^\']+)\'\s+href=\'([^\']+)\'\s+type=\'text\/css\'\s+media=\'([^\']+)\'[^\>]*>/i',
                '/<link\s+rel=\'stylesheet\'\s+id=\'([^\']+)\'\s+href=\'([^\']+)\'\s+type=\'text\/css\'[^\/]*\/>/i'
            ),
            array( &$this,'add' ),
            $buffer
        );
        unset($buffer);


        $s = preg_replace_callback(
            array(
                '/<style[^>]*>([^<]+)?<\/style>/im',
            ),
            array( $this,'add_inline' ),
            $s1
        );

        if ( count($this->srcs) <= 0 ) return $s;

        if ( $this->async == false ) {
            $this->cache_control( ABSPATH.'/wp-content/ttt-optimizer/'.$this->sing().'.'.$this->ext );
            if (defined('TTT_OPTIMIZER_DEBUG') && TTT_OPTIMIZER_DEBUG == true) {
                $s .= '<!--';
                $s .= "\n";
                $s .= 'TTT Optimizer: DEBUG';
                $s .= "\n";
                // $s .= var_export($this->srcs,true);
                $s .= "\n";
                $s .= $this->html;
                $s .= '-->';
            }
            return $s."\n"."<link rel='stylesheet' id='ttt-optimizer-css' href='".get_bloginfo('wpurl')."/wp-content/ttt-optimizer/".$this->sing().".css' type='text/css' media='all' />\n";
        }
        else {
            return $s." ";
        }
    }

    public function add_inline($src) {
        $this->srcs[] = array( 'content' => $src[1], 'media' => $src[3] );
        if (defined('TTT_OPTIMIZER_DEBUG') && TTT_OPTIMIZER_DEBUG == true) {
            $this->html .= var_export($src,true);
            $this->html .= "\n";
        }
    }

    public function add( $src ) {
        $this->srcs[] = array( 'file' => $src[2], 'media' => $src[3] );
        if (defined('TTT_OPTIMIZER_DEBUG') && TTT_OPTIMIZER_DEBUG == true) {
            // $this->html .= "<link rel='stylesheet' id='".$src[1]."' href='".$src[2]."' type='text/css' media='".$src[3]."' />"."\n";
            // $this->html .= var_export($src,true);
            // $this->html .= "\n";
        }
    }

    public function print_out() {
        if ( count($this->srcs) <= 0 ) return '';

        $this->cache_control( ABSPATH.'/wp-content/ttt-optimizer/'.$this->sing().'.'.$this->ext );
        $this->shutdown();
        echo "\n"."<link rel='stylesheet' id='ttt-optimizer-css' href='".get_bloginfo('wpurl')."/wp-content/ttt-optimizer/".$this->sing().".css' type='text/css' media='all' />\n";
    }

}

class TTTOptimizer {

    public $async = true;
    public $_start_time = 0;
    public $_end_time = 0;

    public function __construct($async = true) {

        $this->async_js = ( defined('TTT_OPTIMIZER_ASYNC_JS') ? TTT_OPTIMIZER_ASYNC_JS : $async );
        $this->async_css = ( defined('TTT_OPTIMIZER_ASYNC_CSS') ? TTT_OPTIMIZER_ASYNC_CSS : $async );

        $this->JS = new TTTOptimizer_JS( $this->async_js );
        $this->CSS = new TTTOptimizer_CSS( $this->async_css );
    }
    
    public function buffer_start() {
        $this->_start_time = microtime(true);
        ob_start( array( $this,'start') );
    }

    public function buffer_end() {
        ob_end_flush();
        $this->_end_time = microtime(true);

        echo sprintf("\n<!-- TTT Optimizer time: %s -->\n", $this->_end_time-$this->_start_time);
    }

    public function start( $buffer ) {

        $buffer = $this->CSS->start( $buffer );
        if ( $this->async_css !== true ) {
            $this->CSS->shutdown();
            $this->CSS->reset();
        }

        $buffer = $this->JS->start( $buffer );
        if ( $this->async_js == false ) {
            $this->JS->shutdown();
            $this->JS->reset();
        }

        return $buffer;
    }

    public function global_end() {
        $this->CSS->print_out();
        $this->JS->print_out();
    }

    public function jquery_async() {
        ?>
        <script type="text/javascript">
            var tttopt_callbacks = []; function jQuery(func) { if (func) tttopt_callbacks.push(func); return {ready: function(func) { tttopt_callbacks.push(func); }}; }
        </script>
        <?php
    }
}


function ttt_optimizer_init() {
    if (  defined('TTT_OPTIMIZER_ENABLED') && TTT_OPTIMIZER_ENABLED == false ) {
        return false;
    }


    if (  defined('TTT_OPTIMIZER_ASYNC') && TTT_OPTIMIZER_ASYNC == false ) {
        $TTTOptimizer1 = new TTTOptimizer(false);
        add_action('wp_head', array( $TTTOptimizer1, 'buffer_start'), -1);
        add_action('wp_footer', array( $TTTOptimizer1, 'buffer_end'), 9999);
    }
    else {
        $TTTOptimizer = new TTTOptimizer();
        add_action('wp_head', array( $TTTOptimizer, 'buffer_start') ,0);
        add_action('wp_head', array( $TTTOptimizer, 'buffer_end') ,999);
        add_action('wp_head', array( $TTTOptimizer, 'jquery_async') , 0);
        add_action('wp_footer', array( $TTTOptimizer, 'buffer_start') ,0);
        add_action('wp_footer', array( $TTTOptimizer, 'buffer_end') ,998);

        if ( defined('TTT_OPIMIZER_WPEND') && TTT_OPIMIZER_WPEND == true) {
            add_action('ttt_optimizer_wpend', array( $TTTOptimizer, 'global_end') ,9999);
        }
        else {
            add_action('wp_footer', array( $TTTOptimizer, 'global_end') ,9999);
        }
    }
}
add_action('init','ttt_optimizer_init');
