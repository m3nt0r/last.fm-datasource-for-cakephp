<?php
/**
 * Last.FM API DataSource
 *
 * This Datasource covers all REST methods available on last.fm
 * @link http://www.lastfm.com/api/ LastFM API Homepage
 * 
 * PHP Version 5
 * 
 * @version 0.3
 * @author Kjell Bublitz <m3nt0r.de@gmail.com>
 * @link http://wiki.github.com/m3nt0r/cake-bits/lastfm-datasource-documentation Documentation
 * @link http://cakealot.com Authors Weblog
 * @link http://github.com/m3nt0r/cake-bits Repository
 * @copyright 2008-2009 (c) Kjell Bublitz
 * @license http://www.opensource.org/licenses/mit-license.php The MIT License
 * @package cake-bits
 * @subpackage datasource
 */
App::import(array(
	'HttpSocket', 
	'Xml', 
	'Set'
));

/**
 * LastFM Datasource
 * 
 * @package cake-bits
 * @subpackage datasource
 */
class LastfmSource extends DataSource {
	
	/**
	 * Source Version
	 *
	 * @var string
	 */
	public $version = '0.3';
	
	/**
	 * Token provided by lastfm login page
	 *
	 * @var string
	 */
	public $authToken = '';
	
	/**
	 * Session Key from Auth.get*Session response
	 *
	 * @var string
	 */
	public $sessionKey = '';
	
	/**
	 * Session Method: Defaults to auth.getSession
	 * 
	 * Normally you would never have to change it.
	 * Refer to the documentation on the differences
	 * 
	 * @var string
	 */
	public $sessionMethod = 'auth.getSession';
	
	/**
	 * Public methods of this class
	 * 
	 * Overwrite generic class lookup in query()
	 *
	 * @var array
	 */
	private $__ownMethods = array(
		'catchToken', 
		'setAuthToken', 
		'setSessionKey', 
		'setSessionMethod', 
		'getLoginUrl', 
		'apiMethods',
		'getLastCall'
	);
	
	/**
	 * The default configuration
	 *
	 * @var array
	 */
	public $_baseConfig = array(
		'loginPage' => 'http://www.last.fm/api/auth/', 
		'apiHost' => 'ws.audioscrobbler.com', 
		'apiPath' => '2.0/'
	);
	
	/**
	 * Create instance of LastFM(methodName) and query the service
	 * 
	 * Here i catch all function calls to this source. I use the method-name 
	 * to build the className. The final className is then searched and a object
	 * is created. The object constructor figures out the params and REST method
	 * and then queries the service if the first param to the function call matches
	 * a valid REST method. If everything goes well a array from the XML response 
	 * will be returned.
	 *
	 * Your API Key is automaticly appended. If authentication is required the 
	 * datasoure will let you know. Method to set auth-data is in place.
	 * 
	 * @param string $method
	 * @param array $params
	 * @param object $object
	 * @throws LastfmSourceException
	 * @return mixed false || array
	 */
	public function query ($method, $params, $object) {
		if (in_array($method, $this->__ownMethods)) {
			return $this->{$method}($params, $object);
		}
		else {
			$apiClass = $this->__methodToClass($method);
			if (!class_exists($apiClass)) {
				throw new LastfmSourceException("Class '{$apiClass}' was not found");
			}
			else {
				$instance = new $apiClass($this, $object, $params);
				$this->ApiInstance = $instance;
				return $instance->readResponse();
			}
		}
		return null;
	}
	
	/**
	 * Get the last call params and URI
	 *
	 * @return array
	 */
	public function getLastCall() {
		if (!isset($this->ApiInstance)) return false;
		return array(
			'uri' => $this->ApiInstance->lastUri,
			'params' => $this->ApiInstance->lastData,
			'type' => $this->ApiInstance->queryType,
			'sig' => $this->ApiInstance->lastHash
		);
	}	

	/**
	 * Extract the token GET param given to the Last.FM API callback url
	 * 
	 * You need to call this method in your action you've set up as landing page after remote login
	 * You will have to setup your callback url in your API account yourself. If you don't do this 
	 * your users will never be able to auth themselves.
	 * 
	 * Store the retrieved value in the local session and set it back in this class whenever you
	 * plan to call a REST method that requires auth.  
	 * 
	 * NOTE: Token is valid for 60 minutes from the moment it was granted
	 * 
	 * @see LastfmSource::setAuthToken();
	 * @return string empty || token value if found
	 */
	public function catchToken () {
		if (array_key_exists('token', $_GET)) {
			if (!empty($_GET['token']) && strlen($_GET['token']) == 32) {
				$this->authToken = $_GET['token'];
			} else {
				throw new LastfmSourceException('Callback Token was empty');
			}
		}
		return $this->authToken;
	}
	
	/**
	 * Set the authToken for the following requests
	 *
	 * @param string $value
	 * @return object
	 */
	public function setAuthToken ($value) {
		$this->authToken = $value[0];
		return $this;
	}
	
	/**
	 * Set the sessionKey for the following requests
	 *
	 * @param string $value
	 * @return object
	 */
	public function setSessionKey ($value) {
		$this->sessionKey = $value[0];
		return $this;
	}
	
	/**
	 * Set the sessionMethod for the following requests
	 *
	 * @param string $value
	 * @return object
	 */
	public function setSessionMethod ($value) {
		$this->sessionMethod = $value[0];
		return $this;
	}
	
	/**
	 * Build the LastFM API Login-URL from database.php settings (apikey)
	 *
	 * @return string http://...
	 */
	public function getLoginUrl () {
		return $this->config['loginPage'] . '?api_key=' . $this->config['apikey'];
	}
	
	/**
	 * Return a list of all available api methods for a single response group
	 *
	 * @example $this->Model->apiMethods('tag'); // all methods available
	 * @example $this->Model->apiMethods('tag', true); // only methods that need auth
	 * @example $this->Model->apiMethods('tag', false); // only methods that don't need auth
	 * 
	 * @param array $params __call params
	 * @param object $object Model
	 * @return array
	 */
	public function apiMethods ($params, $object) {
		$apiClass = $this->__methodToClass($params[0]);
		$whoNeedAuth = isset($params[1]) ? (bool) $params[1] : null;
		$instance = new $apiClass($this, $object, $params);
		return $instance->apiMethods($whoNeedAuth);
	}
	
	/**
	 * Translate method to className
	 *
	 * @param unknown_type $method
	 * @return unknown
	 */
	private function __methodToClass ($method) {
		$apiClass = Inflector::camelize($method);
		$apiClass = 'LastFm' . $apiClass;
		return $apiClass;
	}
}

/**
 * LastFM Exception
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.exceptions
 */
class LastfmSourceException extends Exception {
	public function __construct ($message, $code = null) {
		Debugger::log("Last.fm Source: '{$message}'", LOG_ERROR);
		parent::__construct($message, $code);
	}
}

/**
 * LastFM API Exception
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.exceptions
 */
class LastfmSourceApiException extends Exception {
	public function __construct ($restMethod, $message, $code) {
		Debugger::log("Last.fm API: {$restMethod} said '{$message}' (code {$code})", LOG_ERROR);
		parent::__construct($message, $code);
	}
}

/**
 * LastFM API Base class
 * 
 * All definition classes extend from this
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes
 */
abstract class LastFmApi extends Object {
	
	/**
	 * The 'namespace' for all defined REST methods
	 *
	 * @var string
	 */
	public $entity = 'API';
	
	/**
	 * Model Object
	 *
	 * @var object
	 */
	public $model = null;
	
	/**
	 * LastfmSource Object
	 *
	 * @var object
	 */
	public $source = null;
	
	/**
	 * Type of query: post, get, put
	 *
	 * @var string
	 */
	public $queryType = 'get';
	
	/**
	 * Method requires auth
	 *
	 * @var boolean
	 */
	public $requiresAuth = false;
	
	/**
	 * Response content
	 *
	 * @var string
	 */
	public $response = null;
	
	/**
	 * Name of the current api method
	 *
	 * @var string
	 */
	public $method = '';
	
	/**
	 * Query params for HttpSocket
	 *
	 * @var array
	 */
	public $methodParams = array();
	
	/**
	 * The translated rest method
	 *
	 * @var string
	 */
	public $restMethod = '';
	
	/**
	 * Last API uri
	 *
	 * @var string
	 */
	public $lastUri = '';
	
	/**
	 * Last query params
	 *
	 * @var string
	 */
	public $lastData = '';	
	
	/**
	 * Last signature
	 *
	 * @var string
	 */
	public $lastHash = '';		
	
	/**
	 * List a methods in entity that work with GET request
	 *
	 * @var array
	 */
	protected $_get_methods = array();
	
	/**
	 * List a methods in entity that work with POST request
	 *
	 * @var array
	 */
	protected $_post_methods = array();
	
	/**
	 * List a methods in entity that require authentication
	 *
	 * @var array
	 */
	protected $_require_auth = array();
	
	/**
	 * Extracts the params and calls current entities (rest)method
	 *
	 * @throws LastfmSourceException
	 * @param object $source LastfmDatasource
	 * @param object $model Related model
	 * @param array $params REST query params
	 */
	public function __construct ($source, $model, $params) {
		
		$this->source = $source;
		$this->model = $model;
		
		if (empty($params)) {
			throw new LastfmSourceException("You can't call an entity without params");
		}
		
		// i expect the first param to be the rest method
		$this->method = array_shift($params);
		
		if (isset($params[0])) {
			$this->methodParams = $params[0];
		}
	}
	
	/**
	 * Read the response as Array.
	 * 
	 * Is automaticly called in query() method.
	 * Returns false if the response is empty.
	 *
	 * @throws LastfmSourceApiException
	 * @return mixed false || array
	 */
	public function readResponse () {
		$this->response = $this->{$this->method}($this->methodParams);
		
		if (!empty($this->response)) {
			$resp = Set::reverse(new Xml($this->response));
			
			if (is_array($resp)) {
				$resp = $resp['Lfm'];
				if ($resp['status'] == 'failed') {
					throw new LastfmSourceApiException($this->restMethod, $resp['error']['value'], $resp['error']['code']);
				}
			}
			return $resp;
		}
		return false;
	}
	
	/**
	 * Public function to get a list of all available REST methods one could call.
	 *
	 * @param boolean $needAuth TRUE = only those that need, FALSE = only those that don't need
	 * @return array
	 */
	public function apiMethods ($needAuth = null) {
		$all = am($this->_get_methods, $this->_post_methods);
		
		if ($needAuth === true) {
			return array_intersect($all, $this->_require_auth);
		}
		
		if ($needAuth === false) {
			return array_diff($all, $this->_require_auth);
		}
		
		return $all;
	}
	
	/**
	 * Translate called method to REST method format
	 *
	 * That's what $this->entity is for. In development we don't need
	 * to worry about the first part of the REST method, since we already
	 * know the parent as per class definition. 
	 * 
	 * Before the translation i search get and post arrays in this instance
	 * for a match. Depending on where it's found the queryType will
	 * be changed which is important for proper communication.
	 * 
	 * @example: $this->_translateMethod('getEvents'); // returns 'user.getEvents'
	 * 
	 * @throws LastfmSourceException
	 * @param string $name The method as per API definition. Example: 'search'
	 * @return mixed false || string
	 */
	protected function _translateMethod ($name) {
		
		$method = false;
		
		$isGetMethod = in_array($name, $this->_get_methods);
		$isPostMethod = in_array($name, $this->_post_methods);
		$requiresAuth = in_array($name, $this->_require_auth);
		
		if (!$isGetMethod && !$isPostMethod) {
			throw new LastfmSourceException("Method '{$name}' was not found");
		}
		elseif ($isPostMethod) {
			$this->queryType = 'post';
			$this->requiresAuth = $requiresAuth;
			$method = low($this->entity) . '.' . $name;
		}
		elseif ($isGetMethod) {
			$this->queryType = 'get';
			$this->requiresAuth = $requiresAuth;
			$method = low($this->entity) . '.' . $name;
		}
		
		$this->restMethod = $method;
		
		return $method;
	}
	
	/**
	 * Catch all calls to the instance and dispatch it as method to the API
	 *
	 * @throws LastfmSourceException
	 * @param string $name
	 * @param array $params
	 * @return mixed false || xml-response
	 */
	function __call ($name, $params = array()) {
		
		$restMethod = $this->_translateMethod($name);
		if (!$restMethod) {
			throw new LastfmSourceException("Method couldn't be translated. Does it exist? Typo?");
		}
		
		// Extract Method params
		$methodParams = isset($params[0]) ? $params[0] : array();
		
		// New Socket
		$sockConfig = array(
			'version' => '1.1', 
			'header' => array(
				'Connection' => 'close', 
				'User-Agent' => 'CakePHP Last.FM Datasource v' . $this->source->version
			)
		);
		$sock = new HttpSocket($sockConfig);
		
		// extend query with defaults
		$methodParams = am(array(
			'method' => $restMethod, 
			'api_key' => $this->source->config['apikey']
		), $methodParams);
		
		
		// If Auth is required add additional params
		if ($this->requiresAuth) {
		    			
		    if (empty($this->source->sessionKey) && $this->entity != 'Auth') {
		    	throw new LastfmSourceException("Method requires auth. SessionKey may not be empty");
		    }
		    if (empty($this->source->authToken)) {
		    	throw new LastfmSourceException("Method requires auth. AuthToken may not be empty");
		    }
		
		    // Add session key and api signature
		
		    if ($this->entity == 'Auth') { // auth is there to get the sk, dig?
		    	$methodParams = am($methodParams, array(
		    		'token' => $this->source->authToken
		    	));		
		    } else {
		    	$methodParams = am($methodParams, array(
		    		'sk' => $this->source->sessionKey
		    	));
		    }
		
			$methodParams['api_sig'] = $this->_authSignature($methodParams);
		}		
		
		
		// post needs all query params in body instead
		if ($this->queryType == 'post') {  
			$methodData = $methodParams;
			$methodParams = array(); 
		}		
				
		// Build the URI
		$uriConfig = array(
			'host' => $this->source->config['apiHost'], 
			'path' => $this->source->config['apiPath'], 
			'query' => $methodParams
		);
		$uri = $sock->buildUri($uriConfig, '%scheme://%host/%path?%query');
		
		$this->lastUri = $uri;
		$this->lastData = $methodParams;
		
		// Query the service
		if ($this->queryType == 'get') {
			return $sock->get($uri);
		}
		elseif ($this->queryType = 'post') {
			$this->lastData = $methodData;
			return $sock->post($uri, $methodData);
		}
		elseif ($this->queryType = 'put') {
			$this->lastData = $methodData;
			return $sock->put($uri, $methodData);
		}
		
		return false;
	}
		
	/**
	 * Create a request signature using key, method, authmethod and token
	 * 
	 * @link http://www.lastfm.de/api/webauth Read section 6 about this
	 * @return string MD5 string
	 */
	private function _authSignature ($params) {
		ksort($params);
		
		$hashMe = '';
		foreach ($params as $key => $val) {
			$hashMe.=$key.$val;
		} 
		$hashMe.= $this->source->config['secret'];
		$this->lastHash = utf8_encode($hashMe);
		
		return md5($this->lastHash);
	}

}

####################################################################################################
# LastFM Api Classes
# --------------------------------------------------------------------------------------------------
#
#  Each class below can be called as method. Example:
#
#  "LastFmAlbum" equals to: 
#    $this->Model->album('search', array(...params...));
#
####################################################################################################


/**
 * LastFM API: Album
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmAlbum extends LastFmApi {
	public $entity = 'Album';
	protected $_get_methods = array(
		'getInfo', 
		'getTags', 
		'search'
	);
	protected $_post_methods = array(
		'addTags', 
		'removeTag'
	);
	protected $_require_auth = array(
		'addTags', 
		'removeTag'
	);
}

/**
 * LastFM API: Artist
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmArtist extends LastFmApi {
	public $entity = 'Artist';
	protected $_get_methods = array(
		'getEvents', 
		'getInfo', 
		'getShouts', 
		'getSimilar', 
		'getTags', 
		'getTopAlbums', 
		'getTopFans', 
		'getTopTags', 
		'getTopTracks', 
		'search'
	);
	protected $_post_methods = array(
		'addTags', 
		'removeTag', 
		'share'
	);
	protected $_require_auth = array(
		'addTags', 
		'removeTag', 
		'share'
	);
}

/**
 * LastFM API: Auth
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmAuth extends LastFmApi {
	public $entity = 'Auth';
	protected $_get_methods = array(
		'getMobileSession', 
		'getSession', 
		'getToken', 
		'getWebSession'
	);
	protected $_require_auth = array(
		'getMobileSession', 
		'getSession', 
		'getToken', 
		'getWebSession'
	);
}

/**
 * LastFM API: Event
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmEvent extends LastFmApi {
	public $entity = 'Event';
	protected $_get_methods = array(
		'getInfo', 
		'getShouts'
	);
	protected $_post_methods = array(
		'attend', 
		'share'
	);
	protected $_require_auth = array(
		'attend', 
		'share'
	);
}

/**
 * LastFM API: Geo
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmGeo extends LastFmApi {
	public $entity = 'Geo';
	protected $_get_methods = array(
		'getEvents', 
		'getTopArtists', 
		'getTopTracks'
	);
}

/**
 * LastFM API: Group
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmGroup extends LastFmApi {
	public $entity = 'Group';
	protected $_get_methods = array(
		'getMembers', 
		'getWeeklyAlbumChart', 
		'getWeeklyArtistChart', 
		'getWeeklyChartList', 
		'getWeeklyTrackChart'
	);
}

/**
 * LastFM API: Library
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmLibrary extends LastFmApi {
	public $entity = 'Library';
	protected $_get_methods = array(
		'getAlbums', 
		'getArtists', 
		'getTracks'
	);
	protected $_post_methods = array(
		'addAlbum', 
		'addArtist', 
		'addTrack'
	);
	protected $_require_auth = array(
		'addAlbum', 
		'addArtist', 
		'addTrack'
	);
}

/**
 * LastFM API: Playlist
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmPlaylist extends LastFmApi {
	public $entity = 'Playlist';
	protected $_get_methods = array(
		'fetch'
	);
	protected $_post_methods = array(
		'create', 
		'addTrack'
	);
	protected $_require_auth = array(
		'create', 
		'addTrack'
	);
}

/**
 * LastFM API: Tag
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmTag extends LastFmApi {
	public $entity = 'Tag';
	protected $_get_methods = array(
		'getSimilar', 
		'getTopAlbums', 
		'getTopArtists', 
		'getTopTags', 
		'getTopTracks', 
		'getWeeklyArtistChart', 
		'getWeeklyChartList', 
		'search'
	);
}

/**
 * LastFM API: Tasteometer
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmTasteometer extends LastFmApi {
	public $entity = 'Tasteometer';
	protected $_get_methods = array(
		'compare'
	);
}

/**
 * LastFM API: Track
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmTrack extends LastFmApi {
	public $entity = 'Track';
	protected $_get_methods = array(
		'getInfo', 
		'getSimilar', 
		'getTags', 
		'getTopFans', 
		'getTopTags', 
		'search'
	);
	protected $_post_methods = array(
		'addTags', 
		'ban', 
		'love', 
		'removeTag', 
		'share'
	);
	protected $_require_auth = array(
		'addTags', 
		'ban', 
		'love', 
		'removeTag', 
		'share'
	);
}

/**
 * LastFM API: User
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmUser extends LastFmApi {
	public $entity = 'User';
	protected $_get_methods = array(
		'getEvents', 
		'getFriends', 
		'getInfo', 
		'getLovedTracks', 
		'getNeighbours', 
		'getPastEvents', 
		'getPlaylists', 
		'getRecentTracks', 
		'getRecommendedArtists', 
		'getRecommendedEvents', 
		'getShouts', 
		'getTopAlbums', 
		'getTopArtists', 
		'getTopTags', 
		'getTopTracks', 
		'getWeeklyAlbumChart', 
		'getWeeklyArtistChart', 
		'getWeeklyChartList', 
		'getWeeklyTrackChart'
	);
}

/**
 * LastFM API: Venue
 * 
 * Contains just the REST method definitions.
 * 
 * @package cake-bits
 * @subpackage datasource.lastfm.classes.api
 */
final class LastFmVenue extends LastFmApi {
	public $entity = 'Venue';
	protected $_get_methods = array(
		'getEvents', 
		'getPastEvents', 
		'search'
	);
}
