<?php
/**
 * A PHP TileMap Server
 *
 * Serves image tiles, UTFgrid tiles and TileJson definitions 
 * from MBTiles files (as used by TileMill). (Partly) implements
 * the Tile Map Services Specification.
 *
 * Originally based on https://github.com/Zverik/mbtiles-php, 
 * but refactored and extended.
 *
 * @author E. Akerboom (github@infostreams.net)
 * @version 1.1
 * @license LGPL
 */
header('Access-Control-Allow-Origin: *');

$_identifier = '[\w\d_\-\s]+';
$_number = '\d+';

$r = new Router();
$r->map("", 
		array("controller"=>"serverinfo", "action"=>"hello"));

$r->map("root.xml", 
		array("controller"=>"TileMapService", "action"=>"root"));

$r->map("1.0.0", 
		array("controller"=>"TileMapService", "action"=>"service"));

$r->map("1.0.0/:layer", 
		array("controller"=>"TileMapService", "action"=>"resource"), array("layer"=>$_identifier));

$r->map("1.0.0/:layer/:z/:x/:y.:ext", 
		array("controller"=>"maptile", "action"=>"serveTmsTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(png|jpg|jpeg|json)"));

/* Quick-and-dirty hack for issue #15 */
$r->map(":layer/:z/:x/:y\@2x.:ext\?:argument=:callback",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(json|jsonp)", "argument"=>$_identifier, "callback"=>$_identifier));

$r->map(":layer/:z/:x/:y.:ext",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(png|jpg|jpeg|json)"));

/* Quick-and-dirty hack for issue #15 */
$r->map(":layer/:z/:x/:y\@2x.:ext",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(png|jpg|jpeg|json)"));

$r->map(":layer/:z/:x/:y.:ext\?:argument=:callback",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(json|jsonp)", "argument"=>$_identifier, "callback"=>$_identifier));

/* Quick-and-dirty hack for issue #15 */
$r->map(":layer/:z/:x/:y\@2x..:ext\?:argument=:callback",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(json|jsonp)", "argument"=>$_identifier, "callback"=>$_identifier));

$r->map(":layer/:z/:x/:y.grid.:ext",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(json|jsonp)", "argument"=>$_identifier, "callback"=>$_identifier));

$r->map(":layer/:z/:x/:y.grid.:ext\?:argument=:callback",
		array("controller"=>"maptile", "action"=>"serveTile"), 
		array("layer"=>$_identifier, "x"=>$_number, "y"=>$_number, "z"=>$_number, 
			  "ext"=>"(json|jsonp)", "argument"=>$_identifier, "callback"=>$_identifier));
			  			  			  
$r->map(":layer.tilejson", 
		array("controller"=>"maptile", "action"=>"tilejson"), array("layer"=>$_identifier));

$r->map(":layer.tilejsonp\?:argument=:callback", 
		array("controller"=>"maptile", "action"=>"tilejson"), 
		array("layer"=>$_identifier, "argument"=>$_identifier, "callback"=>$_identifier));

$r->run();



class BaseClass {
	protected $layer;
	protected $db;

	public function __construct() {

	}

	protected function getMBTilesName() {
		return $this->layer . ".mbtiles";
	}

	protected function openDB() {
		$filename = $this->getMBTilesName();

		if (file_exists($filename)) {
			$this->db = new PDO('sqlite:' . $filename, '', '');
		}
		if (!isset($this->db)) {
			$this->error(404, "Incorrect tileset name: " . $this->layer);
		}
	}

	protected function closeDB() {
		// close the database
		$this->db = null;
	}

	protected function error($nr, $message) {
		$http_codes = array(
			404=>'Not Found',
			500=>'Internal Server Error',
			// we don't need the rest anyway ;-)
		);

		header($_SERVER['SERVER_PROTOCOL'] . " $nr {$http_codes[$nr]}");
		echo $message;
		exit ;
	}

}

class ServerInfoController extends BaseClass {
	public function __construct() {

	}

	public function hello() {
		global $r;

		$x = new TileMapServiceController();
		echo "This is the " . $x->server_name . " version " . $x->server_version;
		echo "<br /><br />Try these!";
		echo "<ul>";
		foreach ($r->routes as $route) {
			if (strlen($route->url) > 0 && strpos($route->url, ":layer") === false) {
				$url = $route->url;
				echo "<li><a href='$url'>$url</a></li>";
			}
		}

		$layers = glob("*.mbtiles");
		foreach ($layers as $l) {
			$l = str_replace(".mbtiles", "", $l);
			$urls = array("$l/2/1/1.png", "$l.tilejson", "$l/2/1/1.json");
			foreach ($urls as $u) {
				echo "<li><a href='$u'>$u</a></li>";
			}
		}
		echo "</ul>";

		echo "<br/><br/>PS: non-exhaustive list, see source for details";
	}

}

class MapTileController extends BaseClass {
	protected $x;
	protected $y;
	protected $z;
	protected $tileset;
	protected $ext;
	protected $is_tms;

	public function __construct() {
		$this->is_tms = false;
	}

	protected function set($layer, $x, $y, $z, $ext, $callback) {
		$this->layer = $layer;
		$this->x = $x;
		$this->y = $y;
		$this->z = $z;
		$this->ext = $ext;
		$this->callback = $callback;
	}

	public function serveTile($layer, $x, $y, $z, $ext, $callback) {
		$this->set($layer, $x, $y, $z, $ext, $callback);

		if (!$this->is_tms) {
			$this->y = pow(2, $this->z) - 1 - $this->y;
		}

		switch (strtolower($this->ext)) {
			case "json" :
			case "jsonp" :
				if (is_null($this->callback)) {
					$this->jsonTile();
				} else {
					$this->jsonpTile();
				}
				break;

			case "png" :
			case "jpeg" :
			case "jpg" :
				$this->imageTile();
				break;
		}
	}

	public function serveTmsTile($tileset, $x, $y, $z, $ext, $callback) {
		$this->is_tms = true;

		$this->serveTile($tileset . "-tms", $x, $y, $z, $ext, $callback);
	}

	protected function jsonTile() {
		$etag = $this->etag("json");
		$this->checkCache($etag);

		$json = $this->getUTFgrid();

		// disable ZLIB ouput compression
		ini_set('zlib.output_compression', 'Off');

		// serve JSON file
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Length: ' . strlen($json));
		$this->cachingHeaders($etag);

		echo $json;
	}

	protected function jsonpTile() {
		$etag = $this->etag("jsonp");
		$this->checkCache($etag);

		$json = $this->getUTFgrid();
		$output = $this->callback . "($json)";

		// disable ZLIB output compression
		ini_set('zlib.output_compression', 'Off');

		// serve JSON file
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Length: ' . strlen($output));
		$this->cachingHeaders($etag);

		echo $output;
	}

	protected function etag($type) {
		return sha1(sprintf("%s-%s-%s-%s-%s-%s", $this->tileset, $this->x, $this->y, $this->z, $type, filemtime($this->getMBTilesName())));
	}

	protected function checkCache($etag) {
		if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $etag) {
			header('HTTP/1.1 304 Not Modified');
			exit();
		}
	}

	protected function cachingHeaders($etag=null) {
		$day = 60*60*24;
		$expires = 1 * $day;

		// For an explanation on how the expires header and the etag header work together,
		// please see http://stackoverflow.com/a/500103/426224
		header("Expires: " . gmdate('D, d M Y H:i:s \G\M\T', time()+$expires));
		header("Pragma: cache");
		header("Cache-Control: max-age=$expires");
		if (is_string($etag)) {
			// Fix for https://github.com/infostreams/mbtiles-php/issues/11
			header("ETag: \"{$etag}\"");
		}
	}

	protected function imageTile() {
		$etag = $this->etag("img");
		$this->checkCache($etag);

		if ($this->is_tms) {
			$this->tileset = substr($this->tileset, 0, strlen($this->tileset) - 4);
		}

		try {
			$this->openDB();

			$result = $this->db->query('select tile_data as t from tiles where zoom_level=' . $this->z . ' and tile_column=' . $this->x . ' and tile_row=' . $this->y);
			$data = $result->fetchColumn();

			if (!isset($data) || $data === FALSE) {

				// did not find a tile - return an empty (transparent) tile
				$png = imagecreatetruecolor(256, 256);
				imagesavealpha($png, true);
				$trans_colour = imagecolorallocatealpha($png, 0, 0, 0, 127);
				imagefill($png, 0, 0, $trans_colour);
				header('Content-type: image/png');
				$this->cachingHeaders($etag);
				imagepng($png);

			} else {

				// Hooray, found a tile!
				// - figure out which format (jpeg or png) it is in
				$result = $this->db->query('select value from metadata where name="format"');
				$resultdata = $result->fetchColumn();
				$format = isset($resultdata) && $resultdata !== FALSE ? $resultdata : 'png';
				if ($format == 'jpg') {
					$format = 'jpeg';
				}

				// - serve the tile
				header('Content-type: image/' . $format);
				$this->cachingHeaders($etag);
				print $data;

			}

			// done
			$this->closeDB();
		}
		catch( PDOException $e ) {
			$this->closeDB();
			$this->error(500, 'Error querying the database: ' . $e->getMessage());
		}
	}

	protected function getUTFgrid() {
		$this->openDB();

		try {
			$flip = true;
			if ($this->is_tms) {
				$this->tileset = substr($this->tileset, 0, strlen($this->tileset) - 4);
				$flip = false;
			}

			$result = $this->db->query('select grid as g from grids where zoom_level=' . $this->z . ' and tile_column=' . $this->x . ' and tile_row=' . $this->y);

			$data = $result->fetchColumn();
			if (!isset($data) || $data === FALSE) {
				// nothing found - return empty JSON object
				return "{}";
			} else {
				// get the gzipped json from the database
				$grid = gzuncompress($data);
				
				// manually add the data for the interactivity layer by means of string manipulation
				// to prevent a bunch of costly calls to json_encode & json_decode
				//
				// first, strip off the last '}' character
				$grid = substr(trim($grid),0,-1);
				// then, add a new key labelled 'data'
				$grid .= ',"data":{';

				// stuff that key with the actual data
				$result = $this->db->query('select key_name as key, key_json as json from grid_data where zoom_level=' . $this->z . ' and tile_column=' . $this->x . ' and tile_row=' . $this->y);
				while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
					$grid .= '"' . $row['key'] . '":' . $row['json'] . ',';
				}

				// finish up
				$grid = rtrim($grid,',') . "}}";

				// done
				return $grid;
			}
		}
		catch( PDOException $e ) {
			$this->closeDB();
			$this->error(500, 'Error querying the database: ' . $e->getMessage());
		}
	}

	public function tileJson($layer, $callback) {
		$this->layer = $layer;
		$this->openDB();
		try {
			$tilejson = array();
			$tilejson['tilejson'] = "2.0.0";
			$tilejson['scheme'] = "xyz";

			$result = $this->db->query('select name, value from metadata');
			while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
				$key = trim($row['name']);
				$value = $row['value'];
				if (in_array($key, array('maxzoom', 'minzoom'))) {
					$value = intval($value);
				}
				$tilejson[$key] = $value;
			}
			if (array_key_exists('bounds', $tilejson)) {
				$tilejson['bounds'] = array_map('floatval', explode(',', $tilejson['bounds']));
			}
			if (array_key_exists('center', $tilejson)) {
				$tilejson['center'] = array_map('floatval', explode(',', $tilejson['center']));
			}

			// find out the absolute URL to this script
			$protocol = empty($_SERVER["HTTPS"])?"http":"https";
			$server_url = $protocol . "://" . $_SERVER["HTTP_HOST"] . dirname($_SERVER["REQUEST_URI"]);

			$tilejson['tiles'] = array(
				$server_url . "/" . urlencode($layer) . "/{z}/{x}/{y}.png"
			);
			$tilejson['grids'] = array(
				$server_url . "/" . urlencode($layer) . "/{z}/{x}/{y}.json"
			);

			// Include a (mandatory) link to the webpage this map is included on
			// Perhaps use referrer instead?
			// 'Fixes' https://github.com/infostreams/mbtiles-php/issues/12
			$tilejson['webpage'] = $server_url;

			if ($callback !== null) {
				$json = "$callback(" . json_encode($tilejson) . ")";
			} else {
				$json = json_encode($tilejson);
			}

			ini_set('zlib.output_compression', 'Off');
			header('Content-Type: application/json');
			header('Content-Length: ' . strlen($json));
			$this->cachingHeaders();

			echo $json;
		}
		catch( PDOException $e ) {
			$this->closeDB();
			$this->error(500, 'Error querying the database: ' . $e->getMessage());
		}
	}

}

/**
 * Implements a TileMapService that returns XML information on the provided
 * services.
 *
 * @see http://wiki.osgeo.org/wiki/Tile_Map_Service_Specification
 * @author zverik (https://github.com/Zverik)
 * @author E. Akerboom (github@infostreams.net)
 */
class TileMapServiceController extends BaseClass {

	public function __construct() {
		$this->server_name = "PHP TileMap server";
		$this->server_version = "1.0.0";
	}

	public function root() {
		$base = $this->getBaseUrl();

		header('Content-type: text/xml');
		echo <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<Services>
	<TileMapService title="{$this->server_name}" version="{$this->server_version}" href="${base}{$this->server_version}/" />
</Services>
EOF;
	}

	public function service() {
		$base = $this->getBaseUrl();

		header('Content-type: text/xml');
		echo "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>";
		echo "\n<TileMapService version=\"1.0.0\" services=\"$base\">";
		echo "\n\t<Title>{$this->server_name} v{$this->server_version}</Title>";
		echo "\n\t<Abstract />";

		echo "\n\t<TileMaps>";

		if ($handle = opendir('.')) {
			while (($file = readdir($handle)) !== false) {
				if (preg_match('/^[\w\d_-]+\.mbtiles$/', $file) && is_file($file)) {
					try {
						$db = new PDO('sqlite:' . $file);
						$params = $this->readparams($db);
						$name = htmlspecialchars($params['name']);
						$identifier = str_replace('.mbtiles', '', $file);
						echo "\n\t\t<TileMap title=\"$name\" srs=\"OSGEO:41001\" profile=\"global-mercator\" href=\"${base}1.0.0/$identifier\" />";
					}
					catch( PDOException $e ) {
						// nothing
					}
				}
			}
		}

		echo "\n\t</TileMaps>";
		echo "\n</TileMapService>";
	}

	function resource($layer) {
		try {
			$this->layer = $layer;
			$this->openDB();
			$params = $this->readparams($this->db);

			$title = htmlspecialchars($params['name']);
			$description = htmlspecialchars($params['description']);
			$format = $params['format'];

			switch (strtolower($format)) {
				case "jpg" :
				case "jpeg" :
					$mimetype = "image/jpeg";
					break;

				default :
				case "png" :
					$format = "png";
					$mimetype = "image/png";
					break;
			}

			$base = $this->getBaseUrl();
			header('Content-type: text/xml');
			echo <<<EOF
<?xml version="1.0" encoding="UTF-8" ?>
<TileMap version="1.0.0" tilemapservice="{$base}1.0.0/">
	<Title>$title</Title>
	<Abstract>$description</Abstract>
	<SRS>OSGEO:41001</SRS>
	<BoundingBox minx="-180" miny="-90" maxx="180" maxy="90" />
	<Origin x="0" y="0"/>
	<TileFormat width="256" height="256" mime-type="$mimetype" extension="$format"/>
	<TileSets profile="global-mercator">
EOF;
			foreach ($this->readzooms($this->db) as $zoom) {
				$href = $base . "1.0.0/" . $this->layer . "/" . $zoom;
				$units_pp = 78271.516 / pow(2, $zoom);

				echo "<TileSet href=\"$href\" units-per-pixel=\"$units_pp\" order=\"$zoom\" />";
			}
			echo <<<EOF

	</TileSets>
</TileMap>
EOF;
		}
		catch( PDOException $e ) {
			$this->error(404, "Incorrect tileset name: " . $this->layer);
		}
	}

	function readparams($db) {
		$params = array();
		$result = $db->query('select name, value from metadata');
		while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
			$params[$row['name']] = $row['value'];
		}
		return $params;
	}

	function readzooms($db) {
		$params = $this->readparams($db);
		$minzoom = $params['minzoom'];
		$maxzoom = $params['maxzoom'];

		return range($minzoom, $maxzoom);
	}

	function getBaseUrl() {
		$protocol = empty($_SERVER["HTTPS"])?"http":"https";
		return $protocol . '://' . $_SERVER['HTTP_HOST'] . preg_replace('/\/(1.0.0\/)?[^\/]*$/', '/', $_SERVER['REQUEST_URI']);
	}
}




/**
 * Rails like routing for PHP
 *
 * Based on http://blog.sosedoff.com/2009/09/20/rails-like-php-url-router/
 * but extended in significant ways:
 *
 * 1. Can now be deployed in a subdirectory, not just the domain root
 * 2. Will now call the indicated controller & action. Named arguments are
 *	converted to similarly method arguments, i.e. if you specify :id in the
 *	URL mapping, the value of that parameter will be provided to the method's
 *	'$id' parameter, if present.
 * 3. Will now allow URL mappings that contain a '?' - useful for mapping JSONP urls
 * 4. Should now correctly deal with spaces (%20) and other stuff in the URL
 *
 * @version 2.0
 * @author Dan Sosedoff <http://twitter.com/dan_sosedoff>
 * @author E. Akerboom <github@infostreams.net>
 */
define('ROUTER_DEFAULT_CONTROLLER', 'home');
define('ROUTER_DEFAULT_ACTION', 'index');

class Router extends BaseClass {
	public $request_uri;
	public $routes;
	public $controller, $controller_name;
	public $action, $id;
	public $params;
	public $route_found = false;

	public function __construct() {
		$request = $this->get_request();

		$this->request_uri = $request;
		$this->routes = array();
	}

	public function get_request() {
		// find out the absolute path to this script
		$here = str_replace("\\", "/", realpath(rtrim(dirname(__FILE__), '/')) . "/");

		// find out the absolute path to the document root
		$document_root = str_replace("\\", "/", realpath($_SERVER["DOCUMENT_ROOT"]) . "/");

		// let's see if we can return a path that is expressed *relative* to the script
		// (i.e. if this script is in '/sites/something/router.php', and we are
		// requesting /sites/something/here/is/my/path.png, then this function will 
		// return 'here/is/my/path.png')
		if (strpos($here, $document_root) !== false) {
			$relative_path = "/" . str_replace($document_root, "", $here);

			# fix for https://github.com/infostreams/mbtiles-php/issues/4
			$path = $_SERVER["REQUEST_URI"];
			if ($relative_path === '/') {
				$path = preg_replace('/^\/+/', '', $path);
			} else {
				$path = urldecode(str_replace($relative_path, "", $_SERVER["REQUEST_URI"]));
			}

			return $path;
		}

		// nope - we couldn't get the relative path... too bad! Return the absolute path
		// instead.
		return urldecode($_SERVER["REQUEST_URI"]);
	}

	public function map($rule, $target = array(), $conditions = array()) {
		$this->routes[$rule] = new Route($rule, $this->request_uri, $target, $conditions);
	}

	public function default_routes() {
		$this->map(':controller');
		$this->map(':controller/:action');
		$this->map(':controller/:action/:id');
	}

	private function set_route($route) {
		$this->route_found = true;
		$params = $route->params;
		$this->controller = $params['controller']; unset($params['controller']);
		$this->action = $params['action']; unset($params['action']);
		if (isset($params['id'])) { 
			$this->id = $params['id'];
		}
		$this->params = array_merge($params, $_GET);

		if (empty($this->controller)) {
			$this->controller = ROUTER_DEFAULT_CONTROLLER;
		}
		if (empty($this->action)) {
			$this->action = ROUTER_DEFAULT_ACTION;
		}
		if (empty($this->id)) {
			$this->id = null;
		}

		// determine controller name
		$this->controller_name = implode(array_map('ucfirst', explode('_', $this->controller . "_controller")));
	}

	public function match_routes() {
		foreach ($this->routes as $route) {
			if ($route->is_matched) {
				$this->set_route($route);
				break;
			}
		}
	}

	public function run() {
		$this->match_routes();

		if ($this->route_found) {
			// we found a route!
			if (class_exists($this->controller_name)) {
				// ... the controller exists
				$controller = new $this->controller_name();
				if (method_exists($controller, $this->action)) {
					// ... and the action as well! Now, we have to figure out
					//	 how we need to call this method:

					// iterate this method's parameters and compare them with the parameter names
					// we defined in the route. Then, reassemble the values from the URL and put
					// them in the same order as method's argument list.
					$m = new ReflectionMethod($controller, $this->action);
					$params = $m->getParameters();
					$args = array();
					foreach ($params as $i=>$p) {
						if (isset($this->params[$p->name])) {
							$args[$i] = urldecode($this->params[$p->name]);
						} else {
							// we couldn't find this parameter in the URL! Set it to 'null' to indicate this.
							$args[$i] = null;
						}
					}

					// Finally, we call the function with the resulting list of arguments
					call_user_func_array(array($controller, $this->action), $args);
				} else {
					$this->error(404, "Action " . $this->controller_name . "." . $this->action . "() not found");
				}
			} else {
				$this->error(404, "Controller " . $this->controller_name . " not found");
			}
		} else {
			$this->error(404, "Page not found");
		}
	}

}

class Route {
	public $is_matched = false;
	public $params;
	public $url;
	private $conditions;

	function __construct($url, $request_uri, $target, $conditions) {
		$this->url = $url;
		$this->params = array();
		$this->conditions = $conditions;
		$p_names = array();
		$p_values = array();

		// extract pattern names (catches :controller, :action, :id, etc)
		preg_match_all('@:([\w]+)@', $url, $p_names, PREG_PATTERN_ORDER);
		$p_names = $p_names[0];

		// make a version of the request with and without the '?x=y&z=a&...' part
		$pos = strpos($request_uri, '?');
		if ($pos) {
			$request_uri_without = substr($request_uri, 0, $pos);
		} else {
			$request_uri_without = $request_uri;
		}

		foreach (array($request_uri, $request_uri_without) as $request) {
			$url_regex = preg_replace_callback('@:[\w]+@', array($this, 'regex_url'), $url);
			$url_regex .= '/?';

			if (preg_match('@^' . $url_regex . '$@', $request, $p_values)) {
				array_shift($p_values);
				foreach ($p_names as $index=>$value) {
					$this->params[substr($value, 1)] = urldecode($p_values[$index]);
				}
				foreach ($target as $key=>$value) {
					$this->params[$key] = $value;
				}
				$this->is_matched = true;
				break;
			}
		}

		unset($p_names);
		unset($p_values);
	}

	function regex_url($matches) {
		$key = str_replace(':', '', $matches[0]);
		if (array_key_exists($key, $this->conditions)) {
			return '(' . $this->conditions[$key] . ')';
		} else {
			return '([a-zA-Z0-9_\+\-%]+)';
		}
	}
}
?>
