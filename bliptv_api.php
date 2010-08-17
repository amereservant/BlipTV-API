<?php
/**
 * JSON alternative if the json_decode function is unavailable such as in PHP5 < 5.2.0
 * This makes this class compatible with PHP versions 5.0.0 and up.
 */
if (!function_exists('json_decode')) {
    function json_decode($content, $assoc=false) {
        require_once 'Services_JSON.php';
        if ($assoc) {
            $json = new Services_JSON(SERVICES_JSON_LOOSE_TYPE);
        }
        else {
            $json = new Services_JSON;
        }
        return $json->decode($content);
    }
}

if (!function_exists('json_encode')) {
    function json_encode($content) {
        require_once 'Services_JSON.php';
        $json = new Services_JSON;
        return $json->encode($content);
    }
}

/**
 * BlipTV API Class
 *
 * The BlipTV API Class is used to retrieve data from the Blip.TV APIs.
 * It currently doesn't provide authenticated requests support which is needed
 * for video uploads and/or video removals.
 * See the {@link http://code.google.com/p/blip-php/ Blip-PHP project} for
 * REST API (authenticated functions) support.
 *
 * The APIs currently supported are the 'Video Details API', 'Video Browsing API',
 * and the 'Search API'.  The 'Playlist API' wasn't functioning at the time of
 * writing this class, so it's not included.
 *
 * Data types supported are JSON, XML, and RSS.  XML and RSS data is returned
 * unparsed as a string and JSON is decoded and returned as an array.
 * Known issues with the API are noted below in the method's comments.
 *
 * PHP5 (tested with 5.0.5 & 5.3.2)
 *
 * 
 * API Details
 *  The Blip.TV API has a few different APIs, so here's a list of them and the
 *  available Sections and Query Parameters I was able to find via their wiki
 *  at {@link http://wiki.blip.tv/index.php/Blip.tv_API}:
 *
 *      ////  GETTING INFORMATION APIs  ////
 *      + Video Details API
 *          - Sections        : 'file'
 *          - Commands        : 'view'
 *          - Required Params : 'id' (number, id of the file)
 *                              'skin' (string, response format api, rss, json)
 *          - Example         : http://www.blip.tv/file/1234?skin=api
 *    
 *      //// FINDING VIDEOS  ////
 *      + Video Browsing API
 *          - Sections        : 'popular', 'recent', 'random', 'featured', 'posts'
 *          - Commands        : 'view'
 *          - Required Params : 'skin' (string, response format api, rss, json)
 *          - Optional Params : 'page' (number, page number to retrieve)
 *                              // The following are only valid when calling `posts` section:
 *                              'file_type' (string, Comma separated list of formats (ie: flv, mov, m4v) to filter by)
 *                              'topic_name' (string, Comma-separated list of tags to filter by)
 *                              'license' (string, Comma separated list of license ids to filter by)
 *                              'language_code' (string, Two letter language code to filter by)
 *                              'sort' (string, Sort method.  Valid options are `date`, `popularity`, `random`)
 *                              'categories_id' (string, Comma separated lsit of category ids to filter by)
 *          - Examples        : http://www.blip.tv/popular/view/?skin=api
 *                              http://www.blip.tv/recent/?skin=api
 *                              http://www.blip.tv/recent/?skin=api&page=2
 *                              http://www.blip.tv/posts/?file_type=flv&sort=popular&skin=api
 *          
 *      + Playlist API **(doesn't seem to work correctly)**
 *          - Sections        : 'rss', 'bookmarks' ( see notes at {@link http://wiki.blip.tv/index.php/Playlist_API} )
 *          - Commands        : 'bookmarks', 'view'
 *          - Required Params : 'skin' (string, response format rss, json) (api currently unavailable)
 *                              'id' (number, playlist id of playlist to return.  Not required if using 'bookmarked_by' param)
 *          - Optional Params : 'page' (number, page number to retrieve)
 *                              'bookmarked_by' (string, username for whom you want to fetch the default playlist)
 *          - Examples        : http://www.blip.tv/rss/bookmarks/1234
 *                              http://www.blip.tv/bookmarks/view/1234?skin=api
 *                              http://www.blip.tv/bookmarks/?bookmarked_by=hotepisodes
 *                              
 *      + Search API
 *          - Sections        : 'search'
 *          - Commands        : 'view'
 *          - Required Params : 'search' (string, search terms)
 *                              'skin' (string, response format api, rss, json)
 *          - Optional Params : 'sort' (string, sort method.  Valid options are `date`, `popularity`, `random` - Doesn't work?)
                                'pagelen' (int, number of results to return)
                                'page' (int, page number of results to display)
 *          - Examples        : http://www.blip.tv/search/view/?search=foo&skin=api
 *                              http://www.blip.tv/search/view/?q=foo&skin=api (equivalent)
 *                              http://www.blip.tv/search/view/?q=foo&skin=api (equivalent)
 *
 *      ////  PUBLISHING APIs  ////
 *      + Licenses API
 *          - Sections        : 'licenses'
 *          - Commands        : 'view'
 *          - Required Params : 'skin' (string, response format api, rss, json)
 *          - Example         : http://www.blip.tv/?section=licenses&cmd=view&skin=api
 *
 *
 * @category    API
 * @package     BlipTV API
 * @version     0.0.1  (08-17-2010)
 * @author      David Miles <david@amereservant.com>
 * @link        http://github.com/amereservant/BlipTV-API
 * @license     http://creativecommons.org/licenses/MIT/ MIT License
 *                    
 */                              

class BlipTV_API
{
    // Channel/User Name
    protected $channel;
    
    // Blip URL - (without 'http://' or 'www')
    public $blipurl = 'blip.tv';

    // Data type (json, api(xml) or rss)
    public $data_type;
    
    // Versioning - ( For JSON, see http://wiki.blip.tv/index.php/JSON_Output_Format#Versioning )
    public $version = 3; // 2 or 3
    
    // Pagination - (For JSON with Versioning 3 only!)
    public $pagination;
    
    // API Section - Depending on which API, it will vary.
    public $section;
    
    // Command - (usually 'view', but some APIs have others)
    public $command;
    
    // Query Parameters
    protected $params = array();
    
    // Request URL (will be the last one if several requests are made)
    public $request_url;
    
    // Error Message
    public $error;
    
   /**
    * Class Constructor
    *
    * @param    string  $data_type   The requested data's format.
    *                                Options are 'json', 'api' or 'rss'
    * @param    int     $version     JSON version to use, '2' or '3'.
    *                                This value is ignored if using another data type
    * @return   void
    * @access   public
    */
    public function __construct( $data_type, $version=3 )
    {
        if( strtolower($data_type) != 'json' && strtolower($data_type) != 'rss' && 
            strtolower($data_type) != 'api' )
        {
            throw new Exception("Invalid data type specified!");
        }
        $this->data_type = strtolower($data_type);
        
        if( !empty($version) ) { $this->version = $version; }
    }
    
   /**
    * Get User/Channel Videos
    *
    * Fetches the user/channel's videos based on the given parameters.
    * Pagination only works for 'json' {@link $data_type}.
    *
    * @param    string  $section    One of either 'popular', 'recent', 'random', 'featured', 'posts'
    * @param    string  $channel    The user/channel name to fetch items for. (only for 'posts' $section)
    * @param    array   $params     An array containing the following possible keys:
                                    ['page'] = (int) The page number to retrieve contents for
                                    
                                    -- !! The following will ONLY work for {@link $section} = 'posts' !! --
                                    ['count'] = (int) The number of items to fetch
                                    ['filetype'] = (string) Filetypes to retrieve
                                    ['tags'] = (string) Comma separated list of tags to filter by
                                    ['license'] = (string) Comma separated list of license ids to filter by
                                    ['language'] = (string) Two letter language code to filter by
                                    ['sort'] = (string) Sort method, 'date', 'popularity', 'random'
                                    ['categories'] = (string) Comma separated list of cat. ids to filter by
    * @return   string|array        String or array containing data in the format 
    *                               specified by the {@link $data_type} property
    * @access   public
    * @since    0.0.1
    */
    public function get_videos( $section, $channel='', $params=array() )
    {
        if( !in_array($section, array('popular', 'recent', 'random', 'featured', 'posts')) )
        {
            trigger_error('Invalid section `'. $section .'` specified!', E_USER_WARNING);
            return false;
        }
        
        $this->clear_params();
        $this->section = $section;
        
        // The $channel value is irrelevant for any other section type
        if( $section == 'posts' ) $this->channel = $channel;
        
        if( count($params) > 0 )
        {
            // Verify the set params are indeed correct according to $section value
            $post_only = array('count', 'filetype', 'tags', 'language', 'sort', 'categories');
            if( $section != 'posts' )
            {
                // Unset any invalid params
                foreach( $params as $key => $blah )
                {
                    if( in_array($key, $post_only) ) { unset($params[$key]); }
                }
            }
            
            $this->params = $params;
        }
        return $this->fetch_data();
    }
    
   /**
    * Search Videos
    *
    * This method doesn't provide user-specific searching since the API doesn't
    * have a user parameter.
    * In the tests I've performed, the sort option doesn't work either, but I
    * have included it since it is listed in the API.
    *
    * @param    string  $search     The search phrase to search videos for
    * @param    array   $params     An array containing the following possible keys:
    *                               ['page'] = (int) The page number to retrieve contents for
    *                               ['count'] = (int) The number of items to fetch
    *                               ['sort'] = (string) Sort method, 'date', 'popularity', 'random'
    * @return   string|array        String or array containing data in the format 
    *                               specified by the {@link $data_type} property
    * @access   public
    * @since    0.0.1
    */
    public function search_videos( $phrase, $params=array() )
    {
        $this->clear_params();
        $this->section    = 'search';
        $params['search'] = $phrase;
        
        $this->params = $params;
        return $this->fetch_data();
    }
    
   /**
    * Get Video Details
    *
    * Retrieves all associated information based on the provided file id.
    * 
    * @param    int             $id     The ID number for the file
    * @return   string|array            String or array containing data in the format 
    *                                   specified by the {@link $data_type} property
    * @access   public
    * @since    0.0.1
    */
    public function get_video_info( $id )
    {
        $this->clear_params();
        $this->section = 'file';
        $this->params  = array('id' => $id);
        
        return $this->fetch_data();
    }
    
   /**
    * Get Available Licenses
    *
    * Returns all of the available Licenses supported by Blip.TV.
    * ** NOTE: RSS format is not an option.  JSON is available, but the return
    *          data is invalid json data and un-escaped HTML formatting, therefore 
    *          this method only returns XML ('api').
    *
    * @param    void
    * @return   string      String containing the XML data
    * @access   public
    * @since    0.0.1
    */
    public function get_licenses()
    {
        $current_data_type = $this->data_type; // So we can restore it when we're done
        $this->clear_params();
        $this->section = 'licenses';
        $this->data_type = 'api';
        $this->command = 'view'; // MUST be set or request will fail!
        
        $results = $this->fetch_data();
        $this->data_type = $current_data_type;
        
        return $results;
    }
        
    
   /**
    * Get JSON Data
    *
    * This is called by other methods to retrieve and decode the return data in
    * JSON format.
    * If {@link $data_type} is not 'json', it will return false.
    * This method will set the {@link $error} property on data retrieval failure
    * so it's value can be retrieved separately.
    *
    * If {@link $version} is set to `3`, the {@link $pagination} property will
    * be populated with the resulting pagination data.
    *
    * @param    void
    * @return   array       Associative array from the JSON decoded data, false on failure
    * @access   protected
    * @since    0.0.1
    */
    protected function get_json_data()
    {
        if( $this->data_type != 'json' ) 
        {   
            trigger_error('Wrong data_type specified!  Type: '. $this->data_type);   
            return false;
        }

        $data = file_get_contents( $this->build_query() );
        
        if( $data === false ) 
        {
            $this->error = 'Data retrieval failed for url: '. $this->request_url;
            trigger_error($this->error);
            return false;
        }

        if( $data )
        {
            // Correct JSON data format
            $data = str_replace( "blip_ws_results([{", "[{", $data );
            $data = str_replace( "]);", "]", $data );

            // Correct improperly escaped single quotes
            $data = str_replace( "\\'", "\\\\'", $data);
            
            // Check if $version == 3 and correct the json string before decoding it
            if( $this->version == 3 )
            {
                $part1 = substr( $data, 0, strrpos($data, '],')+1);
                $json = json_decode( $part1, true );
                
                // Check for error return
                if( !$json )
                {
                    $json = json_decode( $data, true );
                    if( isset($json[0]['error']) )
                    {
                        $this->error = $json[0]['error'];
                        trigger_error($json[0]['error']);
                        return false;
                    }
                    
                }
                
                // Parse pagination values - (improper json format)
                $part2 = trim(str_replace(array('[', ']', '{', '}'), '', substr( $data, strrpos($data, '],')+2)));
                $part2 = explode(',', $part2);
                $part2[0] = explode(':', $part2[0]);
                $part2[1] = explode(':', $part2[1]);
                foreach($part2 as $piece) {
                    $newpart[trim($piece[0])] = trim($piece[1]);
                }
                
                $this->pagination = $newpart;
            }
            else
            {
                $json = json_decode( $data, true );
            }
            return $json;
        }
        else
        {
            return false;
        }
    }
    
   /**
    * Get XML Data - (For both 'api' and 'rss' data types)
    *
    * This is called by other methods to retrieve the data in API or RSS format.
    * Both data types are XML formatted.
    * The data will NOT be parsed by this class!  Use JSON format if you want
    * the parsed data.
    *
    * If {@link $data_type} is not 'api' or 'rss', it will return false and trigger an
    * E_USER_NOTICE error.
    *
    * ** NOTE: Data seems to be cached via BLIP's API for datatype `rss`, but not `api`.  
    *          Changing the `count` parameter doesn't seem to immediately change
    *          the number of results.
    *
    * @param    void
    * @return   string      String containing returned XML data, false on failure
    * @access   protected
    * @since    0.0.1
    */
    protected function get_xml_data()
    {
        if( $this->data_type != 'api' && $this->data_type != 'rss' ) 
        {   
            trigger_error('Wrong data_type specified!  Type: '. $this->data_type);   
            return false;
        }
        
        $data = file_get_contents( $this->build_query() );
        
        if( $data === false ) 
        {
            $this->error = 'Data retrieval failed for url: '. $this->request_url;
            trigger_error($this->error);
            return false;
        }
        return $data;
    }
    
   /**
    * Fetch Data
    *
    * This method is used to get/return the data formatted according to the 
    * value of the {@link $data_type} property.
    *
    * @param    void
    * @return   mixed       String if {@link $data_type} is 'rss' or 'api', else
    *                       array if 'json'.  False on failure.
    * @access   protected
    * @since    0.0.1
    */
    protected function fetch_data()
    {
        if( in_array($this->data_type, array('rss', 'api')) )
        {
            return $this->get_xml_data();
        }
        elseif( $this->data_type == 'json' )
        {
            return $this->get_json_data();
        }
        else
        {
            // Invalid data type specified
            trigger_error("The data type `{$this->data_type}` is invalid!");
            return false;
        }
    }
    
   /**
    * Build Data Query URL
    *
    * This method constructs the query URL based on properties set by other methods.
    * The method setting the parameters MUST validate the parameters first, otherwise
    * a faulty query URL will be constructed!
    *
    * All query parameters are set here because this class uses alternative names
    * for some of them and plus it also makes sure only accepted parameters are set.
    *
    * @param    void
    * @return   string      Query URL to perform the current API query
    * @access   protected
    * @since    0.0.1
    */
    protected function build_query()
    {
        $url = 'http://'. ( !empty($this->channel) ? $this->channel .'.':'' ) . $this->blipurl .'/';
        $url .= $this->section .'/'. ( !empty($this->command) ? $this->command .'/':'' ) .'?';
        
        // Set all of the query parameters
        $params['skin'] = $this->data_type;
        if( $this->data_type == 'json' )     $params['version'] = $this->version;
        if( !empty($this->params['count']) ) $params['pagelen'] = $this->params['count'];
        if( !empty($this->params['id']) )         $params['id'] = $this->params['id'];
        if( !empty($this->params['search']) ) $params['search'] = $this->params['search'];
        if( !empty($this->params['page']) )  $params['page']    = $this->params['page'];
        if( !empty($this->params['sort']) )  $params['sort']    = $this->params['sort'];
        if( !empty($this->params['filetype']) ) $params['file_type'] = $this->params['filetype'];
        if( !empty($this->params['license']) )    $params['license'] = $this->params['license'];
        if( !empty($this->params['tags']) )    $params['topic_name'] = $this->params['tags'];
        if( !empty($this->params['language']) )   $params['language_code'] = $this->params['language'];
        if( !empty($this->params['categories']) ) $params['categories_id'] = $this->params['categories'];
        
        $query = http_build_query($params);
        $this->request_url = $url . $query;
        
        return $this->request_url;
    }
    
   /**
    * Clear Params
    *
    * Used to clear values from the properties set by methods to avoid errors/conflict
    * if multiple methods are called on the same class instance.
    *
    * @param    void
    * @return   void
    * @access   protected
    * @since    0.0.1
    */
    protected function clear_params()
    {
        $this->section = '';
        $this->command = '';
        $this->params  = array();
        $this->error   = '';
        $this->channel = '';
    }
}




/**************
 ** EXAMPLES **
 **************

function xml_highlight($s) // Used only to highlight XML output for easier reading
{       
    $s = htmlspecialchars($s);
    $s = preg_replace("#&lt;([/]*?)(.*)([\s]*?)&gt;#sU",
        "<font color=\"#0000FF\">&lt;\\1\\2\\3&gt;</font>",$s);
    $s = preg_replace("#&lt;([\?])(.*)([\?])&gt;#sU",
        "<font color=\"#800000\">&lt;\\1\\2\\3&gt;</font>",$s);
    $s = preg_replace("#&lt;([^\s\?/=])(.*)([\[\s/]|&gt;)#iU",
        "&lt;<font color=\"#808000\">\\1\\2</font>\\3",$s);
    $s = preg_replace("#&lt;([/])([^\s]*?)([\s\]]*?)&gt;#iU",
        "&lt;\\1<font color=\"#808000\">\\2</font>\\3&gt;",$s);
    $s = preg_replace("#([^\s]*?)\=(&quot;|')(.*)(&quot;|')#isU",
        "<font color=\"#800080\">\\1</font>=<font color=\"#FF00FF\">\\2\\3\\4</font>",$s);
    $s = preg_replace("#&lt;(.*)(\[)(.*)(\])&gt;#isU",
        "&lt;\\1<font color=\"#800080\">\\2\\3\\4</font>&gt;",$s);
    echo '<pre>'.nl2br($s).'</pre>';
}

$blip = new BlipTV_API( 'json', 3 );

$data = $blip->get_videos( 'posts', 'mercyscross', array('count' => 1) );
echo '<h2>Channel\'s Video Feed Results</h2>';
echo 'URL: '. $blip->request_url .'<br />';
highlight_string("<?php\n ".print_r($data, true));
echo '<strong>Pagination</strong><br />';
echo '<pre>'. print_r($blip->pagination, true) .'</pre>';

$data = $blip->search_videos('Hail Storm', array('count' => 2, 'page' => 3));
echo '<h2>Search Results</h2>';
echo 'URL: '. $blip->request_url .'<br />';
echo '<pre>'. htmlentities(print_r($data, true)) .'</pre>';
echo '<strong>Pagination</strong><br />';
echo '<pre>'. print_r($blip->pagination, true) .'</pre>';

$data = $blip->get_licenses();
echo '<h2>Licenses Result</h2>';
echo 'URL: '. $blip->request_url .'<br />';
xml_highlight($data);

$data = $blip->get_video_info( 4011705 );
echo '<h2>Video Item\'s Details</h2>';
echo 'URL: '. $blip->request_url .'<br />';
highlight_string("<?php\n ".print_r($data, true));
**/
