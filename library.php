<?php

class Utilities {
    public static function vardump($object, $level = 0) {
        echo "<div style='margin-left: " . ($level * 10) . "px;' class='var-dump level-" . $level . "'>";
        $move = 0;
        if (is_array($object)) {
            echo "<div class=type>array(" . count($object) . ")</div>";
            $move = 10;

            foreach ($object as $n => $o) {
                echo "<div class=prop style='padding-left: " . $move . "px;'><span class=name>";
                if (is_numeric($n)) {
                    echo $n;
                } else {
                    echo "'" . $n . "'";
                }
                echo "</span> => ";
                if (is_null($o)) {
                    echo "<span class=typev>NULL</span> ";
                } elseif (is_array($o)) {
                    Utilities::vardump($o, $level + 1);
                } elseif (is_object($o)) {
                    Utilities::vardump($o, $level + 1);
                } else {
                    $type = gettype($o);
                    echo "<span class=typev>" . $type . "</span> ";
                    echo "<span class='value type-" . $type . "'>";
                    if ($type == "string") {
                        echo "'" . htmlentities($o) . "'";
                    } elseif ($type == "boolean") {
                        if ($o == true) {
                            echo "true";
                        } else {
                            echo "false";
                        }
                    } else {
                        echo $o;
                    }
                    echo "</span>";
                    if ($type == "string") {
                        echo " <span class=string-len>(length=" . strlen($o) . ")</span>";
                    }
                }
                echo "</div>";
            }
        } else if ($object != null) {
            echo "<div class=prop style='padding-left: " . $move . "px;'><span class=name>";
            $type = gettype($object);
            echo "<span class=typev>" . $type . "</span> ";
            echo "<span class='value type-" . $type . "'>";
            if ($type == "string") {
                echo "'" . htmlentities($object) . "'";
            } else if(!is_object($object)) {
                echo $object;
            }else {
                Utilities::vardump(get_object_vars($object));
            }
            echo "</span>";
            if ($type == "string") {
                echo " <span class=string-len>(length=" . strlen($object) . ")</span>";
            }
            echo "</div>";
        } else {
            echo "<div class=prop style='padding-left: " . $move . "px;'><span class=name>";
            echo "<span class=typev>NULL</span>";
            echo "</div>";
        }
        echo "</div>";
    }

    public static function random(int $length): string {
		$output = "";
		$characters = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';		
		$charactersCount = strlen($characters);
		for ($i = 0; $i < $length; $i++) {
			$output.= $characters[mt_rand(0, $charactersCount - 1)];
		}
		return $output;
	}

    public static function ip(bool $pure = false): string {
        if(isset($_SERVER["HTTP_X_FORWARDED_FOR"])){
			$ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
		}else if(isset($_SERVER["HTTP_FORWARDED_FOR"])){
			$ip = $_SERVER["HTTP_FORWARDED_FOR"];
		}else{
			$ip = $_SERVER["REMOTE_ADDR"];
		}
		if($pure) return $ip;

		if($ip == "::1"){ $ip = "127.0.0.1"; }
		if(strpos($ip, ",") !== false){
			$ip = explode(",", $ip);
			return trim($ip[0]);
		}
		
		return $ip;
    }

    public static function isEmail(string $email): bool {
		if(filter_var($email, FILTER_VALIDATE_EMAIL)){
			return true;
		}else{
			return false;
		}
	}
}

class Url {
    public static function addParam(string $url, string $name, string $value = ""): string  {
        $parsedUrl = parse_url($url);
        $queryParams = [];
        
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        $queryParams[$name] = $value;

        $queryString = http_build_query($queryParams);
        $resultUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}{$parsedUrl['path']}?$queryString";

        return $resultUrl;
    }

    public static function addParams(string $url, array $params): string  {
        $parsedUrl = parse_url($url);
        $queryParams = [];

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $queryParams);
        }

        $queryParams = array_merge($queryParams, $params);
        $queryString = http_build_query($queryParams);
        $resultUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}{$parsedUrl['path']}?$queryString";

        return $resultUrl;
    }
}

enum Lifetime {
    case Singleton;
    case NewInstance;
    case LazyLoading;
}

class Container {
    private static $instance = null;

    private $services = [];
    private $instances = [];
    private $lazyServices = [];

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Container();
        }
        return self::$instance;
    }

    public function set(string $class, Lifetime $lifetime = Lifetime::NewInstance) {
        $this->services[$class] = [
            'class' => $class,
            'lifetime' => $lifetime
        ];
    }

    public function setSingleton(string $class) {
        $this->set($class, Lifetime::Singleton);
    }

    public function setLazy(string $class) {
        $this->set($class, Lifetime::LazyLoading);
    }

    /**
     * @template T
     * @param class-string<T> $class The name of the model class, eg Layout
     * @return T|null Returns an instance of the specified class or null if the record does not exist
     */
    public function get(string $class) {
        if (isset($this->services[$class])) {
            if ($this->services[$class]['lifetime'] === Lifetime::LazyLoading) {
                if (!isset($this->lazyServices[$class])) {
                    $this->lazyServices[$class] = function () use ($class) {
                        return $this->createInstance($class);
                    };
                }
                return $this->lazyServices[$class]();
            }

            if ($this->services[$class]['lifetime'] === Lifetime::Singleton) {
                if (!isset($this->instances[$class])) {
                    $this->instances[$class] = $this->createInstance($class);
                }
                return $this->instances[$class];
            }

            return $this->createInstance($class);
        }
        throw new Exception("Service not found: $class");
    }

    private function createInstance(string $class) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor) {
            $params = [];
            foreach ($constructor->getParameters() as $param) {
                $paramClass = $param->getType();
                if ($paramClass && !$paramClass->isBuiltin()) {
                    $params[] = $this->get($paramClass->getName());
                }
            }
            return $reflection->newInstanceArgs($params);
        }

        return new $class();
    }
}

class Router {
    private $router = null;
    public  $_get = array();
    public  $_url = null;
    public  $url_ = null;
    public  $_data = null;
    public  $url = "http://localhost/";

    public function __construct() {
        if (!isset($_GET["action"])) $_GET["action"] = null;
    }

    public function add(string $router_condition, string | Closure $url_parameters, bool $redirect = false) {
        $buffer = $router_condition;
        $buffer = preg_replace("/\[(.*?)\]/", "($1)?", $buffer);
        $buffer = preg_replace("/\<(.*?)\>/", "(.*?)", $buffer);
        $buffer = str_replace("/", "\\/", $buffer);
        $this->router[] = array(
            "condition"     => $router_condition,  //0
            "regex"         => $buffer,            //1
            "parameters"    => $url_parameters,    //2
            "phase"         => null,               //3
            "variables"     => null,               //4
            "module"        => null,               //5
            "redirect"      => $redirect           //6
        );
    }

    public function redirect(string $url) {
        header("location:".Router::url().$url);
        exit();
    }

    public static function getPort(): string {
        $port = $_SERVER['SERVER_PORT'];
        if ($port == 80 || $port == 448) $port = "";
        else $port = ":" . $port; //not add default ports to the url but this we can support running web on different port
        return $port;
    }

    public static function url(bool $full = false, bool $_request = false): string {
        $url = $_GET["url"] ?? "";
        $url = rtrim($url, "/");
        $requestUri = $_SERVER["REQUEST_URI"];
        $requestUri = rtrim($requestUri, "/") . "/";

        $http = $_SERVER["REQUEST_SCHEME"] ?? "http";
        $requestParts = explode("?", $requestUri);
        $requestPath = $requestParts[0];
        $port = Router::getPort();

        if ($full) {
            $query = $_request && isset($requestParts[1]) ? "?" . $requestParts[1] : "";
            $ret = "{$http}://{$_SERVER['SERVER_NAME']}{$port}{$requestPath}{$query}";
        } else {
            $ret = "{$http}://{$_SERVER['SERVER_NAME']}{$port}" . str_replace("/{$url}", "/", $requestPath);
        }

        return rtrim($ret, "/");
    }

    public function start() {
        if (!isset($_GET["url"])) {
            $url = "";
        } else {
            $url = $_GET["url"];
        }

        $url_ = explode("?", $url);
        $url = $url_[0];
        if (substr($url, -1) == "/") {
            $url = substr($url, 0, -1);
        }
        $d = explode("?", $_SERVER["REQUEST_URI"], 2);
        $_SERVER["REQUEST_URI_NEW"] = $d[0];
        if (substr($_SERVER["REQUEST_URI_NEW"], -1) != "/") {
            $_SERVER["REQUEST_URI_NEW"] .= "/";
        }
        $port = Router::getPort();

        $this->_url = $url;
        $this->_get = $url;
        $this->url_ = str_replace("/" . $url . "/", "/", $_SERVER["REQUEST_URI_NEW"]);
        if (isset($_SERVER["REQUEST_SCHEME"])) {
            $http = $_SERVER["REQUEST_SCHEME"];
        } else {
            $http = "http";
        }
        $this->url = $http . "://" . $_SERVER["SERVER_NAME"] . $port . str_replace("/" . $url . "/", "/", $_SERVER["REQUEST_URI_NEW"]);

        //Standart get
        $get = explode("?", $_SERVER["REQUEST_URI"], 2);
        if (count($get) > 1) {
            $get = explode("&", $get[1]);
            for ($i = 0; $i < count($get); $i++) {
                $ma = explode("=", $get[$i]);
                if (count($ma) == 1) {
                    $_GET[$ma[0]] = "";
                } else {
                    $_GET[$ma[0]] = urldecode($ma[1]);
                }
            }
        }

        foreach ($this->router as $key => $routa) {
            if ($routa["regex"] == $url || (preg_match("/^" . $routa["regex"] . "$/U", $url) && $routa["regex"] != "")) {
                $this->router[$key]["phase"] = 1;
                $this->router[$key]["variables"] = NULL;
                $variables = [];
                $unuseable = null;
                preg_match_all("/(\<(.*?)\>|\[(.*?)\])/", $routa["condition"], $names);
                preg_match_all("/^" . $routa["regex"] . "$/", $url, $values);

                for ($i = 0; $i < count($names[2]); $i++) {
                    if ($names[2][$i] == "") {
                        preg_match_all("/\<(.*?)\>/", $names[3][$i], $parser);
                        if ($parser[1] != null) {
                            $variables[] = "";
                            $unuseable[] = true;
                            foreach ($parser[1] as $additional) {
                                $variables[] = $additional;
                                $unuseable[] = false;
                            }
                        } else {
                            $variables[] = $names[3][$i];
                            $unuseable[] = true;
                        }
                    } else {
                        $variables[] = $names[2][$i];
                        $unuseable[] = false;
                    }
                }

                for ($i = 0; $i < count($variables); $i++) {
                    if (!$unuseable[$i]) {
                        $nam = explode("=", $variables[$i]);
                        if (count($nam) == 1) {
                            $default = null;
                        } else {
                            $default = $nam[1];
                        }
                        $variables[$i] = $nam[0];
                        if (!isset($values[($i + 1)][0])) {
                            $values[($i + 1)][0] = $default;
                        } else if ($values[($i + 1)][0] == "") {
                            $values[($i + 1)][0] = $default;
                        }
                        $this->router[$key]["variables"][$variables[$i]] = $values[($i + 1)][0];
                        
                        if(!is_callable($this->router[$key]["parameters"])) {
                            $this->router[$key]["parameters"] = str_replace("<" . $variables[$i] . ">", $values[($i + 1)][0] != null ? $values[($i + 1)][0] : "", $this->router[$key]["parameters"]);
                            $this->_url = $this->router[$key]["parameters"];
                        }
                    }
                }
            } else {
                $this->router[$key]["phase"] = 0;
            }

            if(!is_callable($this->router[$key]["parameters"])) {
                $data = explode("&", $this->router[$key]["parameters"]);
                foreach ($data as $aq) {
                    $mas = explode("=", $aq);
                    if ($mas[0] == "module") {
                        if (!isset($mas[1])) {
                            $mas[1] = $this->router[$key]["variables"][$mas[0]];
                        } else if ($mas[1] == "" or $mas[1] == null) {
                            $mas[1] = $this->router[$key]["variables"][$mas[0]];
                        }
                        $this->router[$key]["module"] = $mas[1];
                    }
                }
            }
        }

        for ($i = count($this->router) - 1; $i >= 0; $i--) {
            $routa = $this->router[$i];
            if ($routa["phase"] == 1) {
                if (substr_count($routa["condition"], "/") >= substr_count($url, "/")) {
                    $this->router[$i]["phase"] = 2;
                    if (is_callable($this->router[$i]["parameters"])) {
                        call_user_func($this->router[$i]["parameters"], $this->router[$i]["variables"]);
                    }
                    else if ($this->router[$i]["redirect"]) {
                        echo "Redirecting... to <a href='" . $this->router[$i]["parameters"] . "'>" . $this->router[$i]["parameters"] . "</a>";
                        header("location:" . $this->router[$i]["parameters"]);
                        exit();
                    } else {
                        $data = explode("&", $routa["parameters"]);
                        foreach ($data as $aq) {
                            $from_url = false;
                            $mas = explode("=", $aq);
                            if (isset($routa["variables"][$mas[0]])) {
                                $mas[1] = $routa["variables"][$mas[0]];
                                $from_url = true;
                            }
                            if (!isset($mas[1])) {
                                $mas[1] = "";
                            }
                            $_GET[$mas[0]] = $mas[1];
                            $this->_data[$mas[0]] = array($mas[1], $from_url);
                        }
                    }
                    $i = -1;
                }
            }
        }
    }

    public function get(string $name): string {
        return $this->_data[$name][0];
    }

    public function getData(): array {
        return array(
            "match" => $this->_get,
            "routes" => $this->router
        );
    }

    public function dump(){
		echo "<div style='padding:7px;'>Matching url: ".$this->_get."</div>";
		echo "<table style='table-layout: fixed; border-collapse: collapse;width: 100%;' border=0 class='snowLeopard'>";
		echo "<tr style='border: 1px solid black;'><th width=60>Match?</th><th>Mask</th><th>Regex</th><th width=150>Module</th><th>Request</th></tr>";
		foreach($this->router as $key => $routa){
			if($routa["phase"] == 0){$m = "<font color=black>No</font>";}
			else if($routa["phase"] == 1){$m = "<font style='color:#406b8c'>Maybe</font>";}
			else {$m = "<font style='color: #33a76c;font-weight: bold;'>Yes</font>";}
			echo "<tr style='border: 1px solid black;'><td valign=top>".$m."</td><td valign=top><span>".($routa["condition"] == "" ? "<span class=desc>[empty]</span>" : htmlspecialchars($routa["condition"]))."</span> </td><td>".htmlspecialchars($routa["regex"])."</td></td><td valign=top>".$routa["module"]."</td><td>";
			if($routa["phase"] == 2){
				if($routa["variables"] == NULL){
					echo "<i>Without parameters</i>";
				}else{
					foreach($routa["variables"] as $key => $aq){
						echo "<b>" . $key . "</b> = ";
						if($aq == NULL)
							echo "<font color=black>NULL</font><br>";
						else
							echo "<font color=green>".$aq."</font><br>";
					}
				}
			}
			echo "</td></tr>";
		}
		echo "</table>";
	}
}

class Page {
    private $router;
    public string $title = "Untitled";
    public string $description = "";
    public string $charset = "utf-8";    
    public string $language = "cs";
    public string $author = "";
    public string $keywoards = "";
    public string $browserColor = "";

    private $scripts = [];
    private $styles = [];

    public function __construct(Router $router) {
        $this->router = $router;
    }

    public function head() {
        echo "<head>".PHP_EOL;
		echo '<title>' . $this->title . '</title>'.PHP_EOL;
		echo '<meta http-equiv="Content-Type" content="text/html; charset=' . $this->charset . '">'.PHP_EOL;

        if($this->description != "")
		    echo '<meta name="description" content="' . $this->description . '">'.PHP_EOL;
        if($this->language != "")
		    echo '<meta http-equiv="Content-language" content="' . $this->language . '">'.PHP_EOL;
        if($this->author != "")
		    echo '<meta name="author" content="' . $this->author . '">'.PHP_EOL;
        if($this->keywoards != "")
		    echo '<meta name="keywords" content="' . $this->keywoards . '">'.PHP_EOL;

		echo '<meta name="viewport" content="width=device-width, initial-scale=1">'.PHP_EOL;
		if($this->browserColor != "")
			echo '<meta name="theme-color" content="'.$this->browserColor.'">'.PHP_EOL;
		
		echo '<link href="' . Router::url() . '/favicon.ico?cache='.CACHE.'" rel="icon">'.PHP_EOL;		

		if($this->scripts != null) {
			foreach($this->scripts as $script) {
				if($script["inhead"])
					echo '<script type="text/javascript" src="' . Url::addParam($script["url"], "cache", CACHE) . '"></script>'.PHP_EOL;
			}
		}
		if($this->styles != null) {
			foreach($this->styles as $style) {
				echo '<link rel="stylesheet" '.($style["type"]!=""?"type='".$style["type"]."'":"").' href="' . Url::addParam($style["url"], "cache", CACHE) . '" media="screen" />'.PHP_EOL;
			}
		}
		echo "</head>".PHP_EOL;
    }

    public function footer() {
		echo PHP_EOL;
		if($this->scripts != null){
			foreach($this->scripts as $script){
				if(!$script["inhead"])
					echo '<script type="text/javascript" src="' . $script["url"] . '?cache='.CACHE.'"></script>'.PHP_EOL;
			}
		}
	}

    /**
	 * Add style to the page
	 * $type = "text/css" | define the style type
	 */
	public function addStyle($url, $type = "text/css") {
		$this->styles[] = array("url" => $url, "type" => $type);
	}

	/**
	 * Add script to page 
	 * $inhead = true | the script will be loaded in header else at end of the page
	 */
	public function addScript($url, $inhead = true) {
		$this->scripts[] = array("url" => $url, "inhead" => $inhead);
	}
}

class Layout {
    public function __construct() {
        $this->prepare();
    }

    private function prepare() {
		if(!file_exists(ROOT."/temp/templates/")) {
			if (!mkdir(ROOT."/temp/templates/", 0777, true)) {
				throw new Exception('Failed to create directorie /temp/templates/');
			}
		}

		//Remove old temp files
		$timeMaxOld = date("Y-m-d", strtotime("-1 week"));
		foreach (glob(ROOT."/temp/templates" . "/*.{*,*}", GLOB_BRACE) as $file) {
			if (filemtime($file) < strtotime($timeMaxOld)) {
				unlink($file);
			}
		}
	}

    public function render($filename, $model = NULL, $onlycompile = false, &$outputFile = null): bool {		
		if (!file_exists($filename)) {
			throw new Exception("Failed to load template \"".$filename."\"");
			return false;
		}	

		$name = pathinfo($filename, PATHINFO_FILENAME);
		$content = file_get_contents($filename);
		$hash = sha1($content);
		if (defined('DEBUG')) {
			$hash = 'debug';
		}
		
		$file = ROOT."/temp/templates/".$name.".".$hash.".template.php";
		$outputFile = $file;

		if(file_exists($file) && !defined('DEBUG')){
			if(!$onlycompile)
				include($file);

			return false;
		}

		$template = new TemplaterV2($content, $filename);
		$template->process();
		$out = $template->getOutput();

		file_put_contents($file, $out);
		if(!$onlycompile)
			include($file);

		return true;
	}
}

class TemplaterV2 {
    private $content = [];
	private $fileName = "";
    private $contentLen = 0;
    private $tokens = [];
    private $tokenPos = 0;
    private $contentPos = 0;
    private $output = "";
	private $outputCache = [""];
	private $outputCacheIndex = 0;
    private $lineRow = 0;
    private $lineColumn = 0;
	private $safeBreak = 999999999;
	private $elementWithoutPair = ["link", "input", "img", "br", "!doctype"];
	private $controllTokensDefinition = null;
	private $lastTokenIndex = 0;
	private $eatTokenSafeCounter = 0;
	private $safeBreakTokenCounter = 30;
	private $lastDebugMessage = "";
	private $openControllTokens = [];

    public function __construct($content, $fileName = "inline"){
		if($this->controllTokensDefinition == null) {
			$this->controllTokensDefinition = [TokenType::$LBRACKET, TokenType::$RBRACKET];
		}

        $content = str_replace("\r", "", $content);
        $this->content = $this->utf8Split($content);
        $this->contentLen = count($this->content);
		$this->fileName = $fileName;
    }

    private function printToken($token) {
        return TokenType::print($token["type"])." '".$token["value"]."'";
    }

    private function printTokenInfo($token) {
        if($token["type"] == TokenType::$EOF) return "";
        return "(Line: ".($token["info"]["line"]["row"] + 1).":".($token["info"]["line"]["col"] + 1).")";
    }

    public function process(){
        $safe = 0;
        do {
            $token = $this->makeToken();
            $this->tokens[] = $token;

            if($safe++ > $this->safeBreak) break;
        } while($token["type"] != TokenType::$EOF);

		$this->lastTokenIndex = 0;
		$this->eatTokenSafeCounter = 0;
		$this->openControllTokens = [];
        $this->parseBlock();
		$this->checkRegisteredControllTokens();

		//fix some stuff
		$this->output = $this->outputCache[0];
		$this->output = str_replace(" ?><?php " , " ", $this->output);
    }

    private function eat($tokenType = "", $infoToken = null) {
		$token = $this->getToken();

		if($this->lastTokenIndex != $this->tokenPos) {
			$this->lastTokenIndex = $this->tokenPos;
			$this->eatTokenSafeCounter = 0;
		}else{
			$this->eatTokenSafeCounter++;
			if($this->eatTokenSafeCounter > $this->safeBreakTokenCounter) {
				throw new Exception("Infinite loop occured! Token: " . $this->printToken($token)." ".$this->printTokenInfo($token));
			}
		}

        if($tokenType == "" || $tokenType == $token["type"]) {
            if($this->tokenPos < count($this->tokens)) 
                $this->tokenPos++;
            return $token;
        }

        $message = ($tokenType != ""? "want type '".TokenType::print($tokenType)."'": "");
		if($infoToken != null) {
			$message.=", start token was '".$this->printToken($infoToken)."' ".$this->printTokenInfo($infoToken);
		}
        $this->assertToken($token, false, $message);
    }

    private function eatSpaces() {
        while(trim(($token = $this->eat())["value"]) != "") {}
        return $token;
    }

    private function getToken($offset = 0) {
        if($this->tokenPos + $offset < count($this->tokens))
            return $this->tokens[$this->tokenPos + $offset];
        return $this->tokens[count($this->tokens) - 1];
    }

    private function assertToken($token, $condition, $message = "", $reportedOnly = false){        
        if(!$condition) {
			if($message != "") $message = ", ".$message;
            throw new Exception(($reportedOnly?"Reported token":"Unkown token")." type '".TokenType::print($token["type"])."' ".$this->printTokenInfo($token).$message."; File: " . $this->fileName."\n");
		}
    }

    private function rollback(){
        $this->tokenPos--;
    }

    private function getTokenWithoutSpace() {
        $i = 0;
        while(trim(($token = $this->getToken($i++))["value"]) != "") {}
        return $this->getToken($i);
    }

    /*
    * This function will go and recursive build output
    */
    private function parseBlock($end = -1, $endData = null, $pureValue = false) {
        //rewrite this to $this->getToken();
        while(($token = $this->eat())["type"] != TemplaterBuildEnd::$EOF) {
            if($end == TemplaterBuildEnd::$EOF && $token["type"] == TokenType::$EOF) break;
            if($end == TemplaterBuildEnd::$DIV && $token["type"] == TokenType::$LESS_THEN) {                
                if( $this->getToken(0)["type"] == TokenType::$SLASH &&
                    $this->getToken(1)["type"] == TokenType::$TEXT &&
                    $this->getToken(2)["type"] == TokenType::$GREAT_THEN) {            
                        $this->rollback(); //we want back the TokenType::$LESS_THEN
                        break;
                    }
            } 
			if($end == TemplaterBuildEnd::$CONTROLL && $token["type"] == TokenType::$LBRACKET) {
				if ( $this->getToken(0)["type"] == TokenType::$SLASH &&
					 $this->getToken(1)["value"] == $endData &&
					 $this->getToken(2)["type"] == TokenType::$RBRACKET) {
						$this->rollback();
						break;
					 }
			}

			if($pureValue) {
				$this->printOutput($token["value"]);				
				continue;
			}
            
            if($token["type"] == TokenType::$LESS_THEN) {
                //<div>
                $token = $this->eat(TokenType::$TEXT);
                $elementToken = $token;
                $elementType = $token["value"];

                //parse arguments x=5 x='xxx' {if $x}xx{/if}
                $params = "";
                while(($token = $this->getToken())["type"] != TokenType::$GREAT_THEN) {       
                    if($token["type"] == TokenType::$SLASH) break;
					if($token["type"] == TokenType::$NEW_LINE) {
						$params .= "\n";
						$this->eat();
						continue;
					}

					if($token["type"] == $this->controllTokensDefinition[0]) {
						$params .= $this->parseControll();
						$this->eat();
						continue;
					}

                    $elementParameter = $token;
                    $this->eat(TokenType::$TEXT);

                    if($params != "") $params.=" ";
					$paramName = trim($elementParameter["value"]);

                    $next = $this->getToken();
                    if($next["type"] == TokenType::$ASSIGN) {
                        $this->eat(TokenType::$ASSIGN);
						$out = $this->printOutputParameter();
						
						if(substr(trim($out), 1 , 5) == "<?php" && substr($out, strlen($out) - 3, 2) == "?>" && substr(trim($out), 7 , 4) == "echo" && substr_count($out, "<?php") == 1) {
							$cage = substr($out, 0, 1);
							if(!($cage == "\"" || $cage == "'")) {
								$this->assertToken($next, "We expecting the parameter of element be closed inside (\"\") or ('') but you used (".$cage.$cage.")");
								$params .= "";
								continue;
							}
							$pcage = $cage == "\""? "'": "\"";
							$result = trim(substr($out, 11, strlen($out) - 16));
							$params .= "<?php if((is_bool(".$result.") && (".$result.")) || !is_bool(".$result.")) { echo \" ".$paramName."=\\\"\".(".$result.").\"\\\"\"; } ?>";
						} else{
                        	$params .= $paramName."=".$out;
						}
                    } else {
						$params .= $paramName;
					}
                }

                //check if this is />
				$elementHasNoPair = in_array($elementType, $this->elementWithoutPair);
                if($this->getToken()["type"] == TokenType::$SLASH || $elementHasNoPair) {                    
					if($this->getToken()["type"] == TokenType::$SLASH)
                    	$this->eat(TokenType::$SLASH);
						
                    $this->printOutput("<".$elementType.($params != ""?" ".trim($params):"")." />");
                    $this->eat();
                    continue;
                }

                $this->eat(TokenType::$GREAT_THEN);
                $this->printOutput("<".$elementType.($params != ""?" ".trim($params):"").">");

                $this->parseBlock(TemplaterBuildEnd::$DIV);

                //</div>
                $this->eat(TokenType::$LESS_THEN);
                $this->eat(TokenType::$SLASH, $elementToken);
                $next = $this->getToken();                
                $this->assertToken($next, trim($next["value"]) == trim($elementType), "you start with element '<".$elementType.">' ".$this->printTokenInfo($elementToken).", but ended with element '</".$next["value"].">' ".$this->printTokenInfo($next), true);
                $this->eat(TokenType::$TEXT);                
                $this->eat(TokenType::$GREAT_THEN);

                $this->printOutput("</".$elementType.">");
            } else if($token["type"] == $this->controllTokensDefinition[0]) {
                $this->rollback();
                $output = $this->parseControll();
                $this->eat();
                $this->printOutput($output);
            } else {
                $this->printOutputText($token);
            } 
        }
    }

    private function parseControllTokens($controllTokens){
        // text text {$model} text text
        $output = "";        
                
        for($index = 0; $index < count($controllTokens); $index++) {
            $token = $controllTokens[$index];

            if($token["type"] == $this->controllTokensDefinition[0]) { // {
				$this->lastDebugMessage = "We catching controll token '".$this->printTOken($token)."' at ".$this->printTokenInfo($token);
                $input = "";
                $ctokens = [];
                $index++;
				$open = 0;
                for(;$index < count($controllTokens); $index++) {
                    $ct = $controllTokens[$index];
					if($ct["type"] == $this->controllTokensDefinition[0]) $open++;
					if($ct["type"] == $this->controllTokensDefinition[1]) { 
						if($open == 0) break;
						$open--;
					}

                    $input.= $ct["value"];
                    $ctokens[] = $ct;
                }
                
                //echo "[".$input."]";
                if(trim($input) == "") $output.= ""; //TODO: wtf?
                if($this->notControll($input)) {
                    if(strpos($input, "{") !== false) {
                        $output.= "{".$this->parseControllTokens($ctokens)."}";
                        continue;
                    }
                    $output.= "{".$input."}";
                }else{
                    $output.= $this->renderControll($input, $token);
                }
            }else{
                $output.= $token["value"];
            }
        }

        //return "{".$output."}";
		return $output;
    }

	private function parserParams($params, $len = null){
		$outpar = [];
		$open = null;
		$index = 0;
		$buffer = "";
		for($i = 0; $i < strlen($params); $i++) {
			$c = substr($params, $i, 1);
			if($open != null && $c != $open){
				$buffer.= $c;
				continue;
			}
			if($c=="\"" || $c=="'" || $c=="("){
				if($c == $open){
					$open = null;
					$buffer.= $c;
					continue;
				}
				$buffer.= $c;
				$open = $c;

				if($open == "(") $open = ")";
				continue;
			}

			if($c != ",")
				$buffer.= $c;

			if($c == "," || $i == strlen($params)){
				$outpar[$index++] = trim($buffer);
				$buffer = "";
				if($len != null && $len == $index) {
					$outpar[$index++] = trim(substr($params, $i+1));
					break;
				}
				continue;
			}
		}

		if($buffer != ""){
			$outpar[$index++] = trim($buffer);
		}

		return $outpar;
	}

	private function eatControll() {
		$this->eat(TokenType::$LBRACKET);	//{
		$this->eat(TokenType::$SLASH);		//\
		$this->eat();						//name
		$this->eat(TokenType::$RBRACKET);	//}
	}

	private function registerOpenControll($name, $token) {
		$this->openControllTokens[] = ["type" => $name, "token" => $token];
	}

	private function unregisterControllToken($name) {
		$lastTokenInfo = array_pop($this->openControllTokens);
		$this->assertToken($lastTokenInfo["token"], $lastTokenInfo["type"] == $name, "You must first close '{".$lastTokenInfo["type"]."}' before you close '{".$name."}'", true);
	}

	private function checkRegisteredControllTokens(){
		if(count($this->openControllTokens) > 0) {
			$message = "You don't close all your controll tokens!";
			foreach($this->openControllTokens as $t) {
				$message.= "\n{".$t["type"]."} - ".$this->printTokenInfo($t["token"]);
			}
			$message.= "\nFile: " . $this->fileName;
			throw new Exception($message."\n");
		}
	}

    private function renderControll($input, $token) {
        $controll = explode(" ", $input, 2);
		$type = trim($controll[0]);
        $value = trim($controll[1]);

        if(substr($type[0], 0, 1) == "~" ){
			$url = trim(substr($input, 1, strlen($input) - 1));
			if(substr($url, 0, 1) == "/") 
				$url = substr($url, 1);
            return "<?php echo Router::url(); ?>/".$url;
		} else if(substr($type[0], 0, 1) == "^" ){
			$variable = trim(substr($input, 1, strlen($input) - 1));
            return "<?php echo Utilities::vardump(".$variable."); ?>";
		} else if($type == "if") {
			$this->registerOpenControll("if", $token);
            return "<?php if(".$value.") { ?>";
        } else if($type == "comment") {
			$this->registerOpenControll("comment", $token);
            return "<!--";
        } else if($type == "/comment") {
			$this->unregisterControllToken("comment");
            return "-->";
        } else if($type == "for") {
			$this->registerOpenControll("for", $token);
            if(strpos($value, " as ") !== false) {
				return "<?php foreach(".$value.") { ?>";
			}else{
				return "<?php for(".$value.") { ?>";
			}
        } else if($type == "continueif") {
            return "<?php if(".$value.") { continue; } ?>";
        } else if($type == "breakif") {
            return "<?php if(".$value.") { break; } ?>";
        } else if($type == "isset") {
            return "<?php if(isset(".$value.")) { ?>";
        } else if($type == "elseif") {
            return "<?php } else if(".$value.") { ?>";
        } else if($type == "else") {
            return "<?php } else { ?>";
        } else if($type == "while") {
			$this->registerOpenControll("while", $token);
            return "<?php while(".$value.") { ?>";
        } else if($type == "default") {
			$ro = explode("=", $value, 2);
            return "<?php if(!isset(".$ro[0].")) { ".$ro[1]." } ?>";
        } else if($type == "include") {
            throw new Exception("Reimplmenent the include!");
			$params = $this->parserParams($value, 1);
			return "<?php Bootstrap::\$self->getContainer()->get('page')->template_parse(_ROOT_DIR . \"/views/\".".$params[0].".\".view\", array(".$params[1].")); ?>";
        } else if($type == "capture") {
			$output = "<?php ob_start(); ?>";

			$this->outputIncrease();
			$this->eat();
			$this->parseBlock(TemplaterBuildEnd::$CONTROLL, "capture");
			$output.= $this->outputDecrease();

			$name = $value;
			if(substr($name, 0, 1) == "'" || substr($name, 0, 1) == '"'){
				$name = substr($name, 1, strlen($name) - 2);
				$output.= "<?php \$".$name." = ob_get_contents(); ob_end_clean(); ?>";
			}else{
				$output.= "<?php define('".$name."', ob_get_contents()); ob_end_clean(); ?>";
			}

			$this->eatControll(); //{/capture}

			return $output;
        } else if(substr($input, 0, strlen("function")) == "function") {			
			$isPurePhp = substr($input, strlen("function"), 1) == "!";

			$name = $value;
			$n = explode("(", $name, 2);

			$alias = $n[0];
			//$name = $alias."_".$this->random(5);

			$output = "";
			if(count($n) == 1){
				$output.= "<?php function ".$alias."() {";
			}else{
				$output.= "<?php function ".$alias."(".$n[1]." {";
			}

			if($isPurePhp){ $output.= "?> "; }

			$this->outputIncrease();
			$this->eat();
			$this->parseBlock(TemplaterBuildEnd::$CONTROLL, "function", !$isPurePhp);
			$output.= $this->outputDecrease();

			if($isPurePhp){ $output.= "<?php "; }
			$output.= "} ?>\n";	

			$this->eatControll(); //{/function}

			return $output;
		}else if($type == "var") {
			//@todo: maybe like dot as start or :
			$addEnd = !(substr($value, strlen($value) -1, 1) == ";");
            return "<?php ".$value.($addEnd? ";": "")." ?>";
        } else if(substr($input, 0, 1) == "%" && substr($input, strlen($input) - 1, 1) == "%") {
            return "<?php /*".substr($input, 1, strlen($input) - 2)."*/ ?>";
        } else if(in_array($type, ["/if", "/for", "/while"])) {
			$this->unregisterControllToken(substr($type, 1));
            return "<?php } ?>";
        }

        return "<?php echo ".$input."; ?>";
    }

    private function parseControll(){
        $input = "";
        $controllTokens = [];		

        $open = 0;
		$startToken = $this->getToken();
		$this->lastDebugMessage = "";

        $this->eat($this->controllTokensDefinition[0]);
        while((($token = $this->getToken())["type"] != $this->controllTokensDefinition[1] || $open > 0) && $token["type"] != TokenType::$EOF) {
            if($token["type"] == $this->controllTokensDefinition[0]) $open++;
            if($token["type"] == $this->controllTokensDefinition[1]) $open--;

            $input.= $token["value"];  
            $controllTokens[] = $token;          
            $this->eat();
        }

		if($open > 0 || $token["type"] == TokenType::$EOF) {
			$this->assertToken($startToken, false, "We run in problem while parsing controll token ".($open > 0?", you dont close all controll tokens! missing '".TokenType::print($this->controllTokensDefinition[1])."'":", we don't found closing token '".TokenType::print($this->controllTokensDefinition[1])."' ".($this->lastDebugMessage != ""?", this message should help you: '".$this->lastDebugMessage."'":"")), true);
		}

        if(trim($input) == "") return "{}";
        if($this->notControll($input)) {
            if(strpos($input, "{") !== false) {
                return "{".$this->parseControllTokens($controllTokens)."}";
            }
            return "{".$input."}";
        }

        //try check if this is valid control or just regular {} content like {"test": "text"}
        //for($i = 0; $i < strlen($input); $i++) {
        //    $ch = $input[$i];
        //}

        //do php transform
        $output = $this->renderControll($input, $token);

        $this->assertToken($token, $token["type"] == $this->controllTokensDefinition[1]);
        return $output;
    }

	private function notControll($input) {
		return /*strpos($input, "\n") !== false || */(substr($input, 0, 1) == "\"" && strpos($input, "\n") !==false) || substr($input, 0, 1) == " " || substr($input, 0, 1) == "\n" || trim($input) == "";
	}

	//param="x"
    private function printOutputParameter(){
        $token = $this->getToken();
        $endWith = "";
        if($token["type"] == TokenType::$APOSTROPE) {
            $endWith = TokenType::$APOSTROPE;
        }else if($token["type"] == TokenType::$QUOTEMARK) {
            $endWith = TokenType::$QUOTEMARK;
        }else {
            $this->eat();
            return $token["value"];
        }        

        $this->eat();
        if($endWith == "") return;

        $text = "";
        while(($token = $this->getToken())["type"] != TokenType::$EOF) {
            if($token["type"] == $endWith) break; 
            if($token["type"] == $this->controllTokensDefinition[0]) {
                $text.= $this->parseControll();
            }
            else if($token["type"] == TokenType::$BACK_SLASH && $this->getToken(1)["type"] == $endWith) { //"x\"x"
                $this->eat();
                $text.= "\\";
                $token = $this->getToken();
                $text.= $token["value"];
            }else {
                $text.= $token["value"];
            }
            
            $this->eat();
        }

        $this->eat($endWith);
        $in = TokenType::print($endWith);        
        return $in.$text.$in;
    }

    private function printOutputText($token){
        $this->printOutput($token["value"]);
    }

	private function outputIncrease() {
		$this->outputCacheIndex++;
		$this->outputCache[$this->outputCacheIndex] = "";
	}

	private function outputDecrease() {
		$index = $this->outputCacheIndex;
		$this->outputCacheIndex--;
		if($this->outputCacheIndex < 0) {
			throw new Exception("Output cache index is less than zero!");
		}
		return $this->outputCache[$index];
	}

    private function printOutput($text) {
        $this->outputCache[$this->outputCacheIndex] .= $text;
    }

    public function getOutput(){
        return $this->output;
    }

    private function utf8Split($str, $len = 1): array {
        $arr = array();
        $strLen = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $strLen; $i++)
        {
            $arr[] = mb_substr($str, $i, $len, 'UTF-8');
        }
        return $arr;
    }

    private function getNextCharacter($offset = 0) {
        if($this->contentPos + $offset >= $this->contentLen) return "";
        return $this->content[$this->contentPos + $offset];
    }

    private function makeToken() {
        $value = "";
        $type = "null";

        $i = $this->contentPos;
        $startPos = $i;  
        if($i >= $this->contentLen) return ["type" => TokenType::$EOF, "value" => ""];

        $char = $this->content[$i];
        $value = $char;

        $column = $this->lineColumn;
        $line = $this->lineRow;
        $this->lineColumn++;

		//this can deactivate the controll token!
		if(($char == "\\" && $this->content[$i+1] == TokenType::print($this->controllTokensDefinition[0])) || ($char == "\\" && $this->content[$i+1] == TokenType::print($this->controllTokensDefinition[1]))) {
			$type = TokenType::$TEXT;
			$i++;
			$value = $this->content[$i];
		}
        else if($char == "\n") {
            $this->lineColumn = 0;
            $this->lineRow++;
            $startPos = 0;

            $type = TokenType::$NEW_LINE;
        } else if($char == '<') {
            $type = TokenType::$LESS_THEN;
        } else if($char == '>') {
            $type = TokenType::$GREAT_THEN;
        } else if($char == '/') {
            $type = TokenType::$SLASH;
        } else if($char == '\\') {
            $type = TokenType::$BACK_SLASH;
        } else if($char == '{') {
            $type = TokenType::$LBRACKET;
        } else if($char == '}') {
            $type = TokenType::$RBRACKET;
        } else if($char == '=') {
            $type = TokenType::$ASSIGN;
        } else if($char == '\'') {
            $type = TokenType::$APOSTROPE;
        } else if($char == '"') {
            $type = TokenType::$QUOTEMARK;
        } else {
            $type = TokenType::$TEXT;
            $i++;
            $columns = 0;
            for(; $i < count($this->content); $i++) {
                $char = $this->content[$i];

                if($char == "\n") {
                    $this->lineColumn = $columns = 0;
                    $this->lineRow++;
                }                
                else if(!$this->isCharacter($char)) {
                    break;
                }
                $value .= $char;                
                $columns++;
            }
            $i--;
            $this->lineColumn += $columns;
        }

        $this->contentPos = ++$i;

        return [
            "type" => $type, 
            "value" => $value, 
            "info" => [
                "line" => ["row" => $line, "col" => $column]
            ]
        ];
    }

    private function isCharacter($char){
        $o = ord($char);
        return  ($o >= 65 && $o <= 90)  || 
                ($o >= 97 && $o <= 122) ||
                ($o >= 48 && $o <= 57)  ||
                ($char == '_') || ($char == '-');
    }
}

class TemplaterBuildEnd {
    public static $EOF = -1;
    public static $DIV = 0;
	public static $CONTROLL = 1;
}

class TokenType {
    public static $EOF = -1;
    public static $LESS_THEN = 0;
    public static $GREAT_THEN = 1;
    public static $TEXT = 2;
    public static $SLASH = 3;
    public static $LBRACKET = 4;
    public static $RBRACKET = 5;
    public static $ASSIGN = 6;
    public static $APOSTROPE = 7;
    public static $QUOTEMARK = 8;
    public static $NEW_LINE = 9;
    public static $BACK_SLASH = 10;

    public static function print($type) {
        switch($type) {
            case TokenType::$EOF: return "EOF";
            case TokenType::$LESS_THEN: return "<";
            case TokenType::$GREAT_THEN : return ">";
            case TokenType::$TEXT : return "(text)";
            case TokenType::$SLASH : return "/";
            case TokenType::$BACK_SLASH : return "\\";
            case TokenType::$LBRACKET : return "{";
            case TokenType::$RBRACKET : return "}";
            case TokenType::$ASSIGN : return "=";
            case TokenType::$APOSTROPE : return "'";
            case TokenType::$QUOTEMARK : return "\"";
            case TokenType::$NEW_LINE : return "(new line)";
        }
        return "unknown";
    }

    public static function name($type) {
        switch($type) {
            case TokenType::$EOF: return "EOF";
            case TokenType::$LESS_THEN: return "LESS_THEN";
            case TokenType::$GREAT_THEN : return "GREAT_THEN";
            case TokenType::$TEXT : return "TEXT";
            case TokenType::$SLASH : return "SLASH";
            case TokenType::$BACK_SLASH : return "BACK_SLASH";
            case TokenType::$LBRACKET : return "LBRACKET";
            case TokenType::$RBRACKET : return "RBRACKET";
            case TokenType::$ASSIGN : return "ASSIGN";
            case TokenType::$APOSTROPE : return "APOSTROPE";
            case TokenType::$QUOTEMARK : return "QUOTEMARK";
            case TokenType::$NEW_LINE : return "NEW_LINE";
        }
        return "unknown";
    }
}

class Cookies {		
	/*
		Example :
		Cookies::set( array("name" => "test", "permision" => "admin"), "+24 hour", false );
		Cookies::set( array("name", "permision"), array("test", "admin"), "+24 hour" );
		Cookies::set( array("name", "permision"), "admin", "+24 hour" );
		Cookies::set( "name", "test", "+24 hour" );
	*/
	public static function set($name, $value = 1, $time = "+1 hour"): bool {
		if($time == false){
			if($value == 1){ $value = "+1 hour"; } 
			foreach($name as $key => $val){
				Cookies::set($key, $val, $value);
			}
		}elseif(gettype($name) == "array" && gettype($value) == "array"){
			for($i = 0; $i < count($name); $i++){
				Cookies::set($name[$i], $value[$i], $time);
			}
		}else if(gettype($name) == "array" && gettype($value) != "array"){
			for($i = 0; $i < count($name); $i++){
				Cookies::set($name[$i], $value, $time);
			}
		}else{
			if(substr($time, 0, 1) != "-"){
				$_COOKIE[$name] = $value;
			}else{
				unset($_COOKIE[$name]);
			}
			$cookie = setcookie($name, $value, strtotime($time), "/");					

			if(substr($time, 0, 1) != "-"){				
				$hash = sha1($name.$value);
				$time = strtotime($time);
				$url  = Router::url();
				setcookie("SECURITY_".$name, $hash.";;".time().";;".$url, $time, "/");
			}else{				
				setcookie("SECURITY_".$name, "", strtotime("-1 hour"), "/");
				return false;
			}
		}

        return true;
	}
	
	public static function delete($name) {
		if(gettype($name) == "array"){
			for($i = 0; $i < count($name); $i++){
				Cookies::set($name[$i], "", "-1 hour");
			}
		}else {
			return Cookies::set($name, "", "-1 hour");
		}
	}
	
	public static function exists($name): bool {
		if(isset($_COOKIE[$name])){
			return true;
		}else return false;
	}
	
	public static function security_check($name): bool {
		$security = Cookies::security_get($name);
		if($security != false){			
			if(sha1($name.$_COOKIE[$name]) == $security["hash"])
				return true;
			else
				return false;
		}else 
            return false;
	}
	
	public static function security_get($name): array | bool {
		if(Cookies::exists($name)){
			$data = explode(";;",$_COOKIE["SECURITY_".$name],3);
			return array(
				"hash" 		=> $data[0],
				"create" 	=> $data[1],
				"url"		=> $data[2]
			);
		}else return false;
	}
	
	public static function security_delete(){
		foreach($_COOKIE as $key => $value){
			$dt = explode("_",$key,2);
			if(count($dt)>1){ 
				if($dt[0] == "SECURITY"){ 
					if(!Cookies::exists($dt[1])){ 
						setcookie($key, "", strtotime("-1 hour")); 			
					} 
				} 
			}
		}
	}

	public static function create_ifnotExists($name, $value = "", $time = "+1 hour"): bool {
		if(Cookies::exists($name)){
			return true;
		}
		Cookies::set($name, $value, $time);
		return true;
	}
	
	public static function dump($onlyName = false) {
		echo "<table style='table-layout: fixed; border-collapse: collapse;width:100%;' border=0 class='snowLeopard'>";
		if($onlyName){
			echo "<tr style='border: 1px solid black;'><th>Key</th></tr>";
			foreach($_COOKIE as $key => $value){
				$dt = explode("_",$key,2);
				if(count($dt)>1){ if($dt[0] == "SECURITY"){ if(!Cookies::exists($dt[1])){ $value="<i>DELETED SECURITY COOKIES</i>"; } } }
				echo "<tr><td style='".($dt[0] == "SECURITY"?"color:red;font-weight:bold;":"")."'>".$key."<br><small>".$value."</small></td></tr>";
			}
		}else{
			echo "<tr style='border: 1px solid black;'><th width=300>Key</th><th width=600>Value</th></tr>";
			foreach($_COOKIE as $key => $value){
				$dt = explode("_",$key,2);
				if(count($dt)>1){ if($dt[0] == "SECURITY"){ if(!Cookies::exists($dt[1])){ $value="<i>DELETED SECURITY COOKIES</i>"; } } }
				echo "<tr><td style='".($dt[0] == "SECURITY"?"color:red;font-weight:bold;":"")."'>".$key."</td><td>".$value."</td></tr>";
			}
		}
		if(count($_COOKIE) == 0)
			echo "<tr><td colspan=2>Žádné cookies...</td></tr>";
		echo "</table>";
	}
}

class Database {
    private $connection = null;

    public function connect($host, $database, $username, $password) {
        $this->connection = new PDO("mysql:host=".$host.";dbname=".$database, $username, $password);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getConnection(): PDO {
        if($this->connection == null) 
            throw new Exception("Connection was not opened");

        return $this->connection;
    }
}

abstract class Model {
    protected static string $table;
    protected static string $primaryKey;
    protected Database $database;
    protected $attributes = [];

    public function __construct(array $data = []) {
        $this->database = Container::getInstance()->get(Database::class);
        $this->loadDefaults();
        $this->populate($data);
    }

    private function populate(array $data) {
        $mappings = $this->getColumnMappings();  // Získáme mapování vlastností na sloupce

        foreach ($data as $column => $value) {
            $property = array_search($column, $mappings, true);  // Najdeme odpovídající vlastnost
            if ($property !== false) {
                $this->$property = $value;  // Nastavíme hodnotu pro odpovídající vlastnost
            }
        }
    }

    private function loadDefaults() {
        $reflector = new ReflectionClass($this);
        $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $docComment = $property->getDocComment();
            $defaultValue = null;

            $type = $property->getType();

            if (preg_match('/@default\(([^)]+)\)/', $docComment, $matches)) {
                if($type->getName() != 'string')
                    throw new Exception("Default can be set only for string date types"); 

                $defaultValue = trim($matches[1], '"\'');
            } else {
                if ($type instanceof ReflectionNamedType) {
                    if ($type->allowsNull()) {
                        $defaultValue = null;
                    } else {
                        switch ($type->getName()) {
                            case 'int':
                                $defaultValue = 0;
                                break;
                            case 'float':
                                $defaultValue = 0.0;
                                break;
                            case 'string':
                                $defaultValue = "";
                                break;
                            case 'bool':
                                $defaultValue = false;
                                break;
                        }
                    }
                }
            }

            $this->$propertyName = $defaultValue;
        }
    }

    /*public function __get($name) {
        return $this->attributes[$name] ?? null;
    }

    public function __set($name, $value) {
        Utilities::vardump([$name, $value]);
        $this->attributes[$name] = $value;
    }*/

    private function getColumnMappings(): array {
        $reflector = new ReflectionClass($this);

        $docComment = $reflector->getDocComment();
        if (preg_match('/@table\("([^"]+)"\)/', $docComment, $matches)) {
            static::$table = $matches[1];
        }

        $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);
        $mappings = [];
        
        foreach ($properties as $property) {
            $docComment = $property->getDocComment();
            $column = $property->getName();

            if (strpos($docComment, '@primaryKey') !== false) {
                static::$primaryKey = $column;
            }

            if (preg_match('/@column\("([^"]+)"\)/', $docComment, $matches)) {
                $column = $matches[1];
            }

            $mappings[$property->getName()] = $column;
        }

        return $mappings;
    }

    public static function findById(int|string $id): ?self {
        $obj = (new static);
        $mappings = $obj->getColumnMappings();
        $primaryKeyColumn = $mappings[static::$primaryKey];
        
        $db = $obj->database->getConnection();
        $stmt = $db->prepare("SELECT * FROM " . static::$table . " WHERE $primaryKeyColumn = :id");
        $stmt->bindParam(':id', $id, is_int($id)? PDO::PARAM_INT: PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? new static($result) : null;
    }

    public function save(): bool {
        $db = $this->database->getConnection();
        $mappings = $this->getColumnMappings();
        $columns = array_map(fn($key) => $mappings[$key], array_keys($this->attributes));
        $primaryKey = static::$primaryKey;

        $columns = [];
        $values = [];
        foreach ($mappings as $property => $column) {
            if ($property !== $primaryKey) { 
                $columns[] = $column;
                $values[] = $this->$property;
            }
        }
        
        if ($this->$primaryKey === null) {
            // INSERT
            $placeholders = array_fill(0, count($columns), '?');
            $stmt = $db->prepare("INSERT INTO " . static::$table . " (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")");

            if ($stmt->execute($values)) {
                $this->$primaryKey = $db->lastInsertId();
                return true;
            }
        } else {
            // UPDATE
            $setColumns = [];
            foreach ($columns as $column) {
                $setColumns[] = "$column = ?";
            }
            $stmt = $db->prepare("UPDATE " . static::$table . " SET " . implode(", ", $setColumns) . " WHERE $primaryKey = ?");
            $values[] = $this->$primaryKey;

            return $stmt->execute($values);
        }

        return false;
    }
}

enum UserServiceCheck {
    case Ok;
    case EmailExists;
    case EmailInvalid;
    case LoginExists;
    case PasswordBad;    
    case Unknown;
}

enum UserServiceLogin {
    case Ok;
    case WrongPassword;
    case WrongLogin;
}

class UserService {
    private Router $router;

    public function __construct(Router $router) {
        $this->router = $router;
    }

    public function check(string $login, string $email): UserServiceCheck {
        if(!Utilities::isEmail($email)) return UserServiceCheck::EmailInvalid;

        $user = db\User::find($login, $email);
        if($user == null) return UserServiceCheck::Ok;

        if($user->login == $login) return UserServiceCheck::LoginExists;
        if($user->email == $email) return UserServiceCheck::EmailExists;

        return UserServiceCheck::Unknown;
    }

    public function register(string $login, string $password, string $email): db\User | UserServiceCheck{
        $state = $this->check($login, $email);

        if($state == UserServiceCheck::Ok) {
            $user = new db\User();
            $user->login = $login;
            $user->password = sha1($password);
            $user->email = $email;
            $user->save();

            return $user;
        }

        return $state;
    }

    public function isAuthentificated(): bool {
        if(Cookies::security_check("userId")) {
            $userId = $_COOKIE["userId"];
            $user = db\User::findById($userId);
            if($user != null) return true;            
        }
        return false;
    }

    public function login(string $login, string $password): UserServiceLogin {
        $user = db\User::find($login, $login);

        if($user == null) return UserServiceLogin::WrongLogin;
        if($user->password != sha1($password)) return UserServiceLogin::WrongPassword;

        Cookies::set("userId", $user->id, "+24 hours");

        return UserServiceLogin::Ok;
    }

    public function logout() {
        Cookies::delete("userId");
    }

    public function current(): ?db\User {
        if(!$this->isAuthentificated()) return null;
        return db\User::findById($_COOKIE["userId"]);
    }
}

define("CACHE", 1);

$container = Container::getInstance();
$container->setSingleton(Router::class);
$container->setSingleton(Page::class);
$container->setSingleton(Layout::class);
$container->setSingleton(Database::class);
$container->setSingleton(UserService::class);