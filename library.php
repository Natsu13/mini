<?php
spl_autoload_register(function ($class) {    
    $file = str_replace('\\', DIRECTORY_SEPARATOR, $class).'.php';

    if (file_exists($file)) {
        require_once $file;
    }else {
        throw new Exception("File not found: ".$file);
    }
});

use Models\User;

define("ROOT", str_replace("\\", "/", getcwd()));

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

enum PaginatorType {
    case Page;
    case Prev;
    case Next;
    case Text;
    case Dots;
}

class Paginator {
    private int $totalItems;
    private int $itemsPerPage;
    private int $currentPage;
    private string $urlPattern;
    private array $options;    

    public function __construct(int $totalItems, int $itemsPerPage, string $urlPattern, array $options = []) {
        $this->totalItems = $totalItems;
        $this->itemsPerPage = $itemsPerPage;
        
        $this->options = array_merge([
            'pageParam' => 'page',
            'showTotal' => true,
            'totalPosition' => 'right',
            'prevText' => '&laquo;',
            'nextText' => '&raquo;',
            'containerClass' => 'pagination',
            'itemClass' => 'page-item',
            'linkClass' => 'page-link',
            'textClass' => 'page-text',
            'activeClass' => 'active',
            'displayCount' => 7
        ], $options);

        $this->urlPattern = $this->processUrlPattern($urlPattern);
        $this->currentPage = $this->getCurrentPage();
    }

    private function processUrlPattern(string $url): string {
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '';
        $query = [];
        
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }
        
        unset($query[$this->options['pageParam']]);
        
        $baseUrl = $path;
        
        if (!empty($query)) {
            $baseUrl .= '?' . http_build_query($query) . '&';
        } else {
            $baseUrl .= '?';
        }
        
        return $baseUrl . $this->options['pageParam'] . '=(:page)';
    }

    private function getCurrentPage(): int {
        $page = $_GET[$this->options['pageParam']] ?? 1;
        return max(1, min((int)$page, $this->getTotalPages()));
    }

    private function getTotalPages(): int {
        return max(1, ceil($this->totalItems / $this->itemsPerPage));
    }

    private function getVisiblePages(): array {
        $totalPages    = $this->getTotalPages();
        $currentPage   = $this->currentPage;
        // Určete počet prostředních čísel, které chcete v okně (bez prvních a posledních)
        // Např. pokud chcete celkem 9 číslic, pak: 1 a poslední zabírají 2 místa a okno by mělo mít 7 míst.
        $displayCount  = $this->options["displayCount"]; 
        // Z toho odvodíme počet čísel mezi prvním a posledním (pokud bychom nepoužili tečky)
        $innerCount    = $displayCount - 2; 
    
        $result = [];
    
        // Pokud je celkový počet stránek menší nebo roven požadovanému počtu, zobrazíme všechny:
        if ($totalPages <= $displayCount) {
            for ($i = 1; $i <= $totalPages; $i++) {
                $result[] = ["val" => $i, "type" => PaginatorType::Page];
            }
        } else {
            // Pokud máme více stránek, bude zobrazení následující:
            // První stránka se zobrazí vždy.
            $result[] = ["val" => 1, "type" => PaginatorType::Page];
    
            /*
             * Určíme, zda jsme blízko začátku, konce nebo jsme "uprostřed".
             * Pro tento účel si stanovíme horní a dolní hranici prostředního okna.
             */
            $halfInner = floor($innerCount / 2);
    
            // Pokud je aktuální stránka dostatečně blízko začátku, nebude se kreslit levá tečka.
            if ($currentPage <= ($halfInner + 2)) {
                $start = 2;
                $end   = $innerCount + 1;
                for ($i = $start; $i <= $end; $i++) {
                    $result[] = ["val" => $i, "type" => PaginatorType::Page];
                }
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
            }
            // Pokud je aktuální stránka dostatečně blízko konce, nebude se kreslit pravá tečka.
            elseif ($currentPage >= $totalPages - ($halfInner + 1)) {
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
                $start = $totalPages - $innerCount;
                for ($i = $start; $i < $totalPages; $i++) {
                    $result[] = ["val" => $i, "type" => PaginatorType::Page];
                }
            }
            // Jinak jsme uprostřed a kreslíme obě tečky.
            else {
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
                $start = $currentPage - $halfInner;
                $end   = $currentPage + $halfInner;
                // Pokud je počet prostředních míst lichý a chceme střed přesně u aktuální stránky,
                // můžeme doladit hranici:
                if (($end - $start + 1) < $innerCount) {
                    $end++;
                }
                for ($i = $start; $i <= $end; $i++) {
                    $result[] = ["val" => $i, "type" => PaginatorType::Page];
                }
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
            }
    
            // Poslední stránka se zobrazí vždy.
            $result[] = ["val" => $totalPages, "type" => PaginatorType::Page];
        }
    
        // Volitelně můžeme přidat tlačítka "prev" a "next":
        if ($currentPage != 1) {
            array_unshift($result, ["val" => $this->options["prevText"], "type" => PaginatorType::Prev]);
        }
        if ($currentPage != $totalPages) {
            $result[] = ["val" => $this->options["nextText"], "type" => PaginatorType::Next];
        }
    
        return $result;
    }

    private function renderLink(string|int $value, PaginatorType $type): string {
        $itemClass = $this->options['itemClass'];
        $linkClass = $this->options['linkClass'];
        $textClass = $this->options['textClass'];

        if($type == PaginatorType::Page) {
            $page = intval($value);            
            $url = str_replace('(:page)', (string)$page, $this->urlPattern);

            if($page == $this->getCurrentPage())
                $itemClass .= ' ' . $this->options['activeClass'];

            return sprintf(
                '<li class="%s"><a href="%s" class="%s">%s</a></li>',
                $itemClass,
                $url,
                $linkClass,
                $page
            );
        } else if($type == PaginatorType::Dots) {
            $itemClass .= ' ' . $textClass;
            return sprintf(
                '<li class="%s">...</li>',
                $itemClass
            );
        } else if($type == PaginatorType::Prev || $type == PaginatorType::Next) {
            $page = $this->getCurrentPage();
            if($type == PaginatorType::Prev)
                $page--;
            else
                $page++;

            $url = str_replace('(:page)', (string)$page, $this->urlPattern);

            return sprintf(
                '<li class="%s"><a href="%s" class="%s">%s</a></li>',
                $itemClass,
                $url,
                $linkClass,
                $value
            );
        }

        return sprintf("<li>%s</li>", $value);
    }

    public function render(): string {
        $html = [];
        $html[] = sprintf('<nav><ul class="%s">', $this->options['containerClass']);

        if ($this->options['showTotal'] && $this->options['totalPosition'] !== 'right') {
            $html[] = sprintf(
                '<li class="%s"><span class="%s">Celkem: %d</span></li>',
                $this->options['itemClass'],
                $this->options['linkClass'],
                $this->totalItems
            );
        }

        foreach ($this->getVisiblePages() as $page) {
            $html[] = $this->renderLink(
                $page["val"],
                $page["type"]
            );
        }

        if ($this->options['showTotal'] && $this->options['totalPosition'] === 'right') {
            $html[] = sprintf(
                '<li class="%s"><span>Celkem: %d</span></li>',
                $this->options['textClass'],
                $this->totalItems
            );
        }

        $html[] = '</ul></nav>';

        return implode('', $html);
    }

    public function getOffset(): int {
        return ($this->currentPage - 1) * $this->itemsPerPage;
    }

    public function getLimit(): int {
        return $this->itemsPerPage;
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

    public static function getInstance(): Container {
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
    private array $routes = [];
    private ?string $matchedUrl = null;
    private ?array $routeData = null;
    private string $baseUrl;

    public function __construct() {
        $this->baseUrl = $this->generateBaseUrl();
    }

    public static function url(bool $full = false, bool $_request = false): string {
        $url = $_GET['url'] ?? '';
        $url = rtrim($url, '/');
        $requestUri = $_SERVER['REQUEST_URI'];
        $requestUri = rtrim($requestUri, '/') . '/';

        $http = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $requestParts = explode('?', $requestUri);
        $requestPath = $requestParts[0];
        $port = self::getPort();

        if ($full) {
            $query = $_request && isset($requestParts[1]) ? '?' . $requestParts[1] : '';
            $ret = "{$http}://{$_SERVER['SERVER_NAME']}{$port}{$requestPath}{$query}";
        } else {
            $ret = "{$http}://{$_SERVER['SERVER_NAME']}{$port}" . str_replace("/{$url}", '/', $requestPath);
        }

        return rtrim($ret, '/');
    }

    public static function getPort(): string {
        $port = $_SERVER['SERVER_PORT'];
        if ($port == 80 || $port == 443) {
            return '';
        }
        return ":{$port}";
    }

    public function add(string $path, string|callable $handler, bool $redirect = false): void {
        $pattern = $this->buildRegexPattern($path);
        
        $this->routes[] = [
            'pattern' => $path,
            'regex' => $pattern,
            'handler' => $handler,
            'redirect' => $redirect,
            'status' => null,
            'variables' => null,
            'module' => null
        ];
    }

    public function redirect(string $path): void {
        $url = self::url() . '/' . ltrim($path, '/');
        header("Location: $url");
        exit();
    }

    public function start(): void {
        $url = $this->getCurrentUrl();
        $this->matchedUrl = $url;

        $this->processQueryParameters();

        foreach ($this->routes as $key => $route) {
            if ($this->matchRoute($url, $route, $key)) {
                $matchedRoute = $this->routes[$key];
                
                if (is_callable($matchedRoute['handler'])) {
                    call_user_func($matchedRoute['handler'], $matchedRoute['variables'] ?? []);
                    return;
                } elseif ($matchedRoute['redirect']) {
                    $this->redirect($matchedRoute['handler']);
                } else {
                    $this->processRouteHandler($matchedRoute, $key);
                }
                break;
            }
        }
    }

    private function processRouteHandler(array $route, int $key): void {
        if (!is_string($route['handler'])) return;

        $handler = $route['handler'];
        if ($route['variables']) {
            foreach ($route['variables'] as $name => $value) {
                $handler = str_replace("<{$name}>", $value, $handler);
            }
        }
        
        parse_str($handler, $params);
        foreach ($params as $param => $value) {
            $_GET[$param] = $value;
            $this->routeData[$param] = [$value, true];
        }
    }

    public function get(string $name): string {
        return $this->routeData[$name][0] ?? '';
    }

    public function getData(): array {
        return [
            'match' => $this->matchedUrl,
            'routes' => $this->routes
        ];
    }

    private function getCurrentUrl(): string {
        $url = $_GET['url'] ?? '';
        return trim($url, '/');
    }

    private function matchRoute(string $url, array $route, int $key): bool {
        if ($route['regex'] === $url || (preg_match('/^' . $route['regex'] . '$/U', $url) && $route['regex'] !== '')) {
            $this->routes[$key]['status'] = 'matched';
            $this->extractRouteVariables($url, $route, $key);
            return true;
        }
        return false;
    }

    private function buildRegexPattern(string $path): string {
        // First, escape forward slashes
        $pattern = str_replace('/', '\\/', $path);
        
        // Replace optional sections [/something] with non-capturing group
        $pattern = preg_replace_callback('/\[(.*?)\]/', function($match) {
            return '(?:' . $match[1] . ')?';
        }, $pattern);
        
        // Replace parameters <param=default> with named capturing groups
        $pattern = preg_replace_callback('/\<([^>]+)\>/', function($match) {
            $parts = explode('=', $match[1]);
            return '([^\/]+)'; // Capture anything except forward slash
        }, $pattern);
        
        return $pattern;
    }

    private function extractRouteVariables(string $url, array $route, int $key): void {
        $params = [];
        preg_match_all('/\<([^>]+)\>/', $route['pattern'], $paramMatches);
        
        foreach ($paramMatches[1] as $param) {
            if (str_contains($param, '=')) {
                [$name, $default] = explode('=', $param, 2);
                $params[$name] = $default;
            } else {
                $params[$param] = null;
            }
        }

        // Match URL against pattern and get values
        preg_match('/^' . $route['regex'] . '$/U', $url, $matches);
        array_shift($matches); // Remove full match

        // Get parameter names in order they appear in the pattern
        $orderedParams = array_keys($params);
        
        // Assign values to parameters, respecting the URL structure
        foreach ($matches as $index => $value) {
            if (isset($orderedParams[$index])) {
                $paramName = $orderedParams[$index];
                $params[$paramName] = $value !== '' ? $value : $params[$paramName];
            }
        }

        if (!empty($params)) {
            $this->routes[$key]['variables'] = $params;
        }
    }

    private function processParameter(string $param, array &$variables): void {
        if (str_contains($param, '=')) {
            [$name, $default] = explode('=', $param, 2);
            $variables[$name] = $default;
        } else {
            $variables[$param] = null;
        }
    }

    private function processQueryParameters(): void {
        $queryString = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($queryString) {
            parse_str($queryString, $queryParams);
            foreach ($queryParams as $key => $value) {
                $_GET[$key] = urldecode($value);
            }
        }
    }

    private function generateBaseUrl(): string {
        $protocol = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $host = $_SERVER['SERVER_NAME'];
        $port = self::getPort();
        return "{$protocol}://{$host}{$port}";
    }

    public function dump(): void {
        echo '<div style="padding:7px;">Matching URL: ' . htmlspecialchars($this->matchedUrl) . '</div>';
        echo '<table style="width:100%; border-collapse:collapse; table-layout:fixed;">';
        echo '<tr style="border:1px solid black;">
                <th style="width:60px;">Status</th>
                <th>Pattern</th>
                <th>Regex</th>
                <th style="width:150px;">Module</th>
                <th>Variables</th>
              </tr>';

        foreach ($this->routes as $route) {
            $status = match ($route['status']) {
                'matched' => '<span style="color:#33a76c;font-weight:bold;">Yes</span>',
                null => '<span style="color:black;">No</span>',
                default => '<span style="color:#406b8c;">Maybe</span>'
            };

            echo "<tr style='border:1px solid black;'>
                    <td>{$status}</td>
                    <td>" . htmlspecialchars($route['pattern']) . "</td>
                    <td>" . htmlspecialchars($route['regex']) . "</td>
                    <td>" . htmlspecialchars($route['module'] ?? '') . "</td>
                    <td>";

            if ($route['status'] === 'matched' && !empty($route['variables'])) {
                foreach ($route['variables'] as $key => $value) {
                    echo "<b>" . htmlspecialchars($key) . "</b> = ";
                    echo "<span style='color:green'>" . htmlspecialchars($value) . "</span><br>";
                }
            } else {
                echo "<i>No variables</i>";
            }

            echo "</td></tr>";
        }
        echo '</table>';
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
				$this->lastDebugMessage = "We catching controll token '".$this->printToken($token)."' at ".$this->printTokenInfo($token);
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
	
	public static function delete($name): bool {
		if(gettype($name) == "array"){
			for($i = 0; $i < count($name); $i++){
				Cookies::set($name[$i], "", "-1 hour");                
			}
            return true;
		}
        
        return Cookies::set($name, "", "-1 hour");
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
        $this->connection = new PDO("mysql:host=".$host.";dbname=".$database, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        ]);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getConnection(): PDO {
        if($this->connection == null) 
            throw new Exception("Connection was not opened");

        return $this->connection;
    }

    public static function getPdoParamType($value): int {
        if (is_int($value)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }
        if (is_null($value)) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }
}

enum ModelState {
    case New;       //You created the model
    case Loaded;    //Model was loaded from database
    case Changed;   //After model saved
}

abstract class Model {
    protected static string $table;
    protected static string $primaryKey;
    protected static bool $primaryKeyAutoIncrement = true;

    protected $mappings = [];
    protected Database $database;
    protected $attributes = [];
    protected ModelState $state = ModelState::New;

    public function __construct(array $data = []) {
        $this->database = Container::getInstance()->get(Database::class);
        $this->loadDefaults();
        $this->populate($data);
    }

    private function populate(array $data) {
        $this->mappings = $this->getColumnMappings();

        if(!empty($data)) 
            $this->state = ModelState::Loaded;

        foreach ($data as $column => $value) {
            $property = array_search($column, $this->mappings, true);
            if ($property !== false) {
                $this->$property = $value;
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

    private function getColumnMappings(): array {
        $reflector = new ReflectionClass($this);

        $docComment = $reflector->getDocComment();
        if (preg_match('/@table\("([^"]+)"\)/', $docComment, $matches)) {
            static::$table = $matches[1];
        }

        if(static::$table == null) {
            throw new Exception("Table name is not set for model ".get_class($this));
        }

        $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);
        $mappings = [];
        
        foreach ($properties as $property) {
            $docComment = $property->getDocComment();
            $column = $property->getName();

            if (strpos($docComment, '@primaryKey') !== false) {
                static::$primaryKey = $column;

                if (strpos($docComment, '@autoIncrementDisabled') !== false) {
                    static::$primaryKeyAutoIncrement = false;
                }
            }

            if (preg_match('/@column\("([^"]+)"\)/', $docComment, $matches)) {
                $column = $matches[1];
            }

            $propName = $property->getName();
            $mappings[$propName] = $column;
            $mappings[$column] = $propName;
        }

        return $mappings;
    }

    public static function findById(int|string $id): ?self {
        $obj = (new static);
        return $obj->where([static::$primaryKey => $id])->fetchSingle();
    }

    public static function fetchAll(): ?array {
        $obj = (new static);
        $db = $obj->database->getConnection();
        $stmt = $db->prepare("SELECT * FROM ".static::$table);  
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $objects = [];
        foreach ($rows as $row) {
            $objects[] = new static($row);
        }
        return $objects;
    }

    public static function where($condition, $params = []): QueryBuilder {
        $obj = (new static);
        $builder = new QueryBuilder($obj->database->getConnection(), static::$table, static::class);
        return $builder->where($condition, $params);
    }

    public function save(): bool {
        $db = $this->database->getConnection();
        $mappings = $this->getColumnMappings();
        $columns = array_map(fn($key) => $mappings[$key], array_keys($this->attributes));
        $primaryKey = static::$primaryKey;

        if($primaryKey == null) {
            throw new Exception("Primary key is not set for model ".get_class($this)." so we can't save the model");
        }

        $columns = [];
        $values = [];
        foreach ($mappings as $property => $column) {
            if ($property !== $primaryKey) { 
                $columns[] = $column;
                $values[] = $this->$property;
            }
        }
        
        if ($this->$primaryKey === null || $this->state === ModelState::New) {
            // INSERT
            $placeholders = array_fill(0, count($columns), '?');
            $stmt = $db->prepare("INSERT INTO " . static::$table . " (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")");

            if ($stmt->execute($values)) {
                if (static::$primaryKeyAutoIncrement) {
                    $this->$primaryKey = $db->lastInsertId();
                }

                $this->state = ModelState::Changed;
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

            $this->state = ModelState::Changed;
            return $stmt->execute($values);
        }

        return false;
    }

    private $relationCache = [];

    private function getRelationships(): array {
        $reflector = new ReflectionClass($this);
        $methods = $reflector->getMethods(ReflectionMethod::IS_PRIVATE);
        $relationships = [];

        foreach ($methods as $method) {
            $docComment = $method->getDocComment();
            if (!$docComment) continue;

            if (preg_match('/@hasMany\("([^"]+)"\s*,\s*"?([^"]*)"?\)/', $docComment, $matches)) {
                $relationships[$method->getName()] = [
                    'type' => 'hasMany',
                    'class' => $matches[1],
                    'foreignKey' => !empty($matches[2]) ? $matches[2] : strtolower(get_class($this)) . '_id'
                ];
            }
            else if (preg_match('/@(hasOne|belongsTo)\("([^"]+)"\s*,?\s*"?([^"]*)"?\)/', $docComment, $matches)) {
                $relationships[$method->getName()] = [
                    'type' => 'belongsTo',
                    'class' => $matches[2],
                    'foreignKey' => !empty($matches[3]) ? $matches[3] : strtolower($matches[2]) . '_id'
                ];
            }
        }

        return $relationships;
    }

    protected function loadRelationship(string $method) {
        if (isset($this->relationCache[$method])) {
            return $this->relationCache[$method];
        }        

        $relationships = $this->getRelationships();
        if (!isset($relationships[$method])) {
            throw new Exception("Relationship $method not defined");
        }

        $relation = $relationships[$method];
        $result = null;

        $foreignKey = $this->mappings[$relation['foreignKey']];
        if ($relation['type'] === 'hasMany') {
            $result = (new $relation['class'])
                ->where([$foreignKey => $this->{static::$primaryKey}])
                ->fetchAll();
        }
        else if ($relation['type'] === 'belongsTo') {
            $foreignValue = $this->{$foreignKey};
            $className = "Models\\". $relation['class'];
            $result = $foreignValue ? ($className)::findById($foreignValue) : null;
        }

        $this->relationCache[$method] = $result;
        return $result;
    }

    public function __call($method, $args) {
        try {
            $relationships = $this->getRelationships();
            
            if (isset($relationships[$method])) {
                return $this->loadRelationship($method);
            }

            return self::__call($method, $args);
        } catch (Error $e) {
            throw new Exception("Method $method not found in " . get_class($this));
        }
    }
}

enum DataTableLike {
    case Both;
    case Left;
    case Right;
}

/**
 * @template T of Model
 */
class QueryBuilder {
    private PDO $connection;
	private $table = "";
	private $where = [];
	private $items = [];
	private $joins = [];
	private $having = [];
	private $orderBy = null;
	private $pageLimit = 0;
	private $currentPage = 1;
	private $sql = [];
	private $debugSql = [];
	private $takeAllFieldsFromTable = false;
    private $bindCounter = 0;
    private $className;

    /**
     * @param PDO $connection The PDO connection instance.
     * @param string $table The name of the table.
     * @param class-string<T>|null $className The name of the model class, or null if not specified.
     */
	public function __construct(PDO $connection, string $table = "", $className = null) {
		$this->table = $table;
        $this->connection = $connection;
        $this->className = $className;
	}

	public function table(string $name): self {
		$this->table = $name;
		return $this;
	}

	public function having(): self{
		$this->having[] = array("value" => func_get_args());
		return $this;
	}

	private function generateBindName() {
        $this->bindCounter++;
        return ":param" . $this->bindCounter;
    }

    public function where($condition, $params = []): self {
        if(is_array($condition)) {
            foreach($condition as $key => $cond) {
                $bindName = $this->generateBindName();
                $this->where[] = [
                    'type' => count($this->where) > 0 ? 'AND' : '',
                    'value' => "$key = $bindName",
                    'binds' => [$bindName => $cond],
                ];                
            }

            return $this;
        }

        if (!empty($params)) {
            $this->where[] = [
                'type' => count($this->where) > 0 ? 'AND' : '',
                'value' => $condition,
                'binds' => $params,
            ];
            return $this;
        }
    
        $binds = [];
        $regex = '/(\w+)\s*(=|>|<|>=|<=|!=)\s*(\d+|\'[^\']*\'|"[^"]*")/'; 
    
        if (preg_match_all($regex, $condition, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $column = $match[1];
                $operator = $match[2];
                $value = trim($match[3], '\'"');
                $bindName = $this->generateBindName();
                $condition = str_replace($match[0], "$column $operator $bindName", $condition);
                $binds[$bindName] = is_numeric($value) ? (float)$value : $value;
            }
        }
    
        $this->where[] = [
            'type' => count($this->where) > 0 ? 'AND' : '',
            'value' => $condition,
            'binds' => $binds,
        ];
        return $this;
    }

	public function whereOr($condition, $params = []): self{
        $change = (count($this->where) > 0);
        $this->where($condition, $params);
        if($change)
		    $this->where[count($this->where) - 1]["type"] = "OR";

		return $this;
	}

	/**
     * replace {table} as name of original table
     * {join} as table name of join
     */
    public function join($table, $condition, $name = ""): self {
		if($name == "") $name = $table;
        $this->joins[] = array("type" => "LEFT", "table" => $table, "name" => $name, "condition" => $condition);
        return $this;
    }

	/**
	 * DataTableLike::$BOTH = 0
	 * DataTableLike::$LEFT = 1
	 * DataTableLike::$RIGHT = 2
	 */
	public function like($column, $value, $type = 0, $isOr = false): self {
		//"_to LIKE %~like~", $filter["receiver"]
		$like = "%~like~";
		if($type == DataTableLike::Left) $like = "%~like";
		else if($type == DataTableLike::Right) $like = "%like~";

		if($isOr)
			$this->whereOr($column." LIKE ".$like, $value);
		else
			$this->where($column." LIKE ".$like, $value);

		return $this;
	}

	public function likeOr($column, $value, $type = 0): self {
		return $this->like($column, $value, $type, true);
	}

	public function count(): int {
        $buildData = $this->buildSql(true);
		$stmt = $this->connection->prepare($buildData["sql"]);
        foreach ($buildData["binds"] as $name => $value) {
            $stmt->bindValue($name, $value, Database::getPdoParamType($value));
        }

        $stmt->execute();
        $result = $stmt->fetchColumn();
		
        $this->debugCapture($stmt, "count");

		return $result;
	}

	public function fetch(): array {
        $buildData = $this->buildSql(false);
		$stmt = $this->connection->prepare($buildData["sql"]);
        foreach ($buildData["binds"] as $name => $value) {
            $stmt->bindValue($name, $value, Database::getPdoParamType($value));
        }        

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->debugCapture($stmt);

        if ($this->className) {
            $objects = [];
            foreach ($rows as $row) {
                $objects[] = new $this->className($row);
            }
            return $objects;
        } else {
            return $rows;
        }
	}

    /**
     * @return T|null Returns an instance of the specified class or null if the record does not exist.
     */
    public function fetchSingle(): ?Model {
        $buildData = $this->buildSql(false);
		$stmt = $this->connection->prepare($buildData["sql"]);
        foreach ($buildData["binds"] as $name => $value) {
            $stmt->bindValue($name, $value, Database::getPdoParamType($value));
        }        

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->debugCapture($stmt, "querySingle");
        
        if ($this->className) {
            return $result ? new $this->className($result) : null;
        } else {
            return $result;
        }
    }

    private function debugCapture(\PDOStatement | false $stmt, string $debugName = "query"){
        if (defined('DEBUG')) {
            ob_start();
            $stmt->debugDumpParams();
		    $this->debugSql[$debugName] = ob_get_clean();
        }else{
            $this->debugSql[$debugName] = $stmt->queryString;
        }
    }

	public function order(string $value): self {
		$this->orderBy = $value;
		return $this;
	}

	public function limit(int $value): self {
		$this->pageLimit = $value;
		return $this;
	}

	public function page(int $value): self {
		$this->currentPage = $value;
		return $this;
	}

	public function takAllFields(): self {
		$this->takeAllFieldsFromTable = true;
		return $this;
	}

	public function item($value, $name = ""): self {
		$this->items[] = array("value" => $value, "name" => $name, "must" => false);
		return $this;
	}

	public function itemMust($value, $name): self {
		$this->items[] = array("value" => $value, "name" => $name, "must" => true);
		return $this;
	}

	public function items($items): self{
		$list = explode(",", $items);
		foreach($list as $item) {
			$data = explode("as", $item);
			if(count($data) == 1) {
				$this->item(trim($item));
			}else{
				$this->item(trim($data[0]), trim($data[1]));
			}
		}
        return $this;
	}

	public function getSql($isCount = false, $isDebug = true): string {
		if($isCount) {
			if($isDebug) {
				return $this->debugSql["count"];
			}else{
				return $this->sql["count"];
			}
		}

		if($isDebug) {
			return $this->debugSql["query"];
		}else{
			return $this->sql["query"];
		}
	}

    public function buildSql($isCount = false) {
        $sql = [];
        $binds = [];
        $queryType = $isCount ? "SELECT COUNT(*)" : "SELECT";

        if (count($this->items) === 0) {
            $sql[] = $queryType . " *";
        } else {
            $columns = [];
            if (!$isCount && $this->takeAllFieldsFromTable) {
                $columns[] = "*";
            }

            foreach ($this->items as $item) {
                if ($isCount && (!isset($item["must"]) || !$item["must"])) {
                    continue;
                }

                $value = str_replace("{table}", $this->table, $item["value"]);
                if (strpos($value, " ") !== false) {
                    $value = "($value)";
                }

                $column = $value;
                if (!empty($item["name"])) {
                    $column .= " AS " . $item["name"];
                }
                $columns[] = $column;
            }

            $sql[] = $queryType . " " . implode(", ", $columns);
        }

        $sql[] = "FROM " . $this->table;

        foreach ($this->joins as $join) {
            $condition = str_replace(
                ["{table}", "{join}"],
                [$this->table, $join["name"]],
                $join["condition"]
            );
            $sql[] = "{$join["type"]} JOIN {$join["table"]} AS {$join["name"]} ON $condition";
        }

        if (!empty($this->where)) {
            $whereParts = [];
            foreach ($this->where as $where) {
                $whereParts[] = ($where["type"] ?? "") . " " . $where["value"];
                $binds = array_merge($binds, $where["binds"]);
            }
            $sql[] = "WHERE " . implode(" ", $whereParts);
        }

        if (!empty($this->having)) {
            $havingParts = [];
            foreach ($this->having as $having) {
                $condition = str_replace("{table}", $this->table, $having["value"]);
                $havingParts[] = $condition;
            }
            $sql[] = "HAVING " . implode(" ", $havingParts);
        }

        if (!$isCount) {
            if (!empty($this->orderBy)) {
                $sql[] = "ORDER BY " . str_replace("{table}", $this->table, $this->orderBy);
            }
            if ($this->pageLimit) {
                $sql[] = "LIMIT :limit OFFSET :offset";
                $binds[':limit'] = $this->pageLimit;
                $binds[':offset'] = ($this->currentPage - 1) * $this->pageLimit;
            }
        }

        $finalSql = implode(" ", $sql);

        return [
            'sql' => $finalSql,
            'binds' => $binds,
        ];
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

        $user = User::find($login, $email);
        if($user == null) return UserServiceCheck::Ok;

        if($user->login == $login) return UserServiceCheck::LoginExists;
        if($user->email == $email) return UserServiceCheck::EmailExists;

        return UserServiceCheck::Unknown;
    }

    public function register(string $login, string $password, string $email): User | UserServiceCheck {
        $state = $this->check($login, $email);

        if($state == UserServiceCheck::Ok) {
            $user = new Models\User();
            $user->login = $login;
            $user->password = sha1($password);
            $user->email = $email;
            $user->save();

            return $user;
        }

        return $state;
    }

    /**
     * Check if current user is authentificated
     * @return bool
     */
    public function isAuthentificated(): bool {
        if(Cookies::security_check("userId")) {
            $userId = $_COOKIE["userId"];
            $user = User::findById($userId);
            if($user != null) return true;            
        }
        return false;
    }

    public function login(string $login, string $password): UserServiceLogin {
        $user = User::find($login, $login);

        if($user == null) return UserServiceLogin::WrongLogin;
        if($user->password != sha1($password)) return UserServiceLogin::WrongPassword;

        Cookies::set("userId", $user->id, "+24 hours");

        return UserServiceLogin::Ok;
    }

    public function logout() {
        Cookies::delete("userId");
    }

    public function current(): ?User {
        if(!$this->isAuthentificated()) return null;
        return User::findById($_COOKIE["userId"]);
    }
}

class Http {
	private $curl;
	private $returnTransfer;
	private $executed;
	private $response;
	private $expectedStatus;
	private $userpwd;
	private $url;
	private $originalUrl;
	private $headers;
	private $lastCurlDebug = null;
	private $lastErrno = 0;
	private $lastError = "unknown";

	public function __construct(){
		$this->init();
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
	}

	public function init(){
		$this->curl = curl_init();
		$this->returnTransfer = true;
		$this->executed = false;
		$this->response = NULL;
		$this->expectedStatus = 200;
		$this->userpwd = NULL;
		$this->url = "";
		$this->originalUrl = "";
		$this->lastCurlDebug = null;
		$this->lastErrno = 0;
		$this->headers = array(
			"Accept" => "application/json",
			"Content-Type" => "application/x-www-form-urlencoded"
		);
	}

	public function setReturnTransfer($state = true): self {
		$this->returnTransfer = $state;
        return $this;
	}

	public function setAccept($accept = "application/json"): self {
		$this->headers["Accept"] = $accept;
        return $this;
	}

	/**
	 * application/json
	 * application/x-www-form-urlencoded
	 */
	public function setContentType($contentType = "application/x-www-form-urlencoded"): self {
		$this->headers["Content-Type"] = $contentType;
        return $this;
	}

	public function setExpectedStatus($expectedStatus = 200): self {
		$this->expectedStatus = $expectedStatus;
        return $this;
	}

	public function setAuthorization(string $token): self {
		$this->headers["Authorization"] = $token;
        return $this;
	}

	public function setAuthorizationBearer(string $token): self {
		$this->setAuthorization("Bearer ".$token);
        return $this;
	}

	public function setUserPwd(string $user, string $pwd): self {
		curl_setopt($this->curl, CURLOPT_USERPWD, $user . ":" . $pwd);
        return $this;
	}

	private function formatQuery(string $url) {
		$urlParts = parse_url($url);
		parse_str($urlParts['query'], $params);
	
		$_params = [];
		foreach ($params as $key => $value) {
			$_params[] = $key."=".rawurlencode($value);
		}
	
		//$urlParts['query'] = http_build_query($params);
		$url = $urlParts['scheme'] . '://' . $urlParts['host'] . $urlParts['path'];
		return $url . (count($_params) > 0? ('?' . join("&", $_params)):"");
	}

	private function sendRequest(string $url, mixed $data, int $contentLength, string $requestType): self {
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, $this->returnTransfer);
		curl_setopt($this->curl, CURLOPT_URL, $this->formatQuery($url));
		if($data != NULL)
			curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $requestType);		
		if($contentLength > 0)
			$this->headers["Content-Length"] = $contentLength;
		curl_setopt($this->curl, CURLOPT_HTTPHEADER, $this->getHeaders());

		$this->originalUrl = $url;
		$this->url = $this->formatQuery($url);

        return $this;
	}

	public function postJson(string $url, string $json = null): self {
		$this->headers["Content-Type"] = "application/json";
		$this->sendRequest($url, $json, strlen($json), "POST");
        return $this;
	}

	private function addQuery(string $url, string $name, string $value = ""): string {
		$ure = explode("?", $url);
		$param = $name;
		if($value != NULL && $value != "") {
			$param.="=".$value;
		}
		if(!isset($ure[1])) {
			return $url."?".$param;
		}
		return $url."&".$param;
	}

	public function postQuery(string $url, array $query, string $data = ""): self {
		$json = json_encode($data);
		foreach($query as $key => $value) {
			$url = $this->addQuery($url, $key, $value);
		}
		$this->sendRequest($url, $json, strlen($json), "POST");
        return $this;
	}

	public function post(string $url, array $data = []): self {
		$data_query = [];
		foreach($data as $key => $value) {
			$data_query[] = $key."=".urlencode($value);
		}
		$result_query = implode("&", $data_query);
		$this->sendRequest($url, $result_query, strlen($result_query), "POST");
        return $this;
	}

	public function getJson(string $url): self {
		$this->headers["Content-Type"] = "application/json";
		$this->sendRequest($url, null, 0, "GET");
        return $this;
	}

	public function get(string $url): self {
		$this->sendRequest($url, null, 0, "GET");
        return $this;
	}

	public function addHeader(string $name, string $value): self {
		$this->headers[$name] = $value;
        return $this;
	}

	public function getHeaders(): array {
		$headers = [];
		foreach($this->headers as $key => $value) {
			$headers[] = $key.": ".$value;
		}
		return $headers;
	}

	public function exec(): self {
		if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')){
			curl_setopt($this->curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
		}

		$this->response = curl_exec($this->curl);
		$this->lastCurlDebug = curl_getinfo($this->curl);
		$this->lastErrno = curl_errno($this->curl);
		$this->executed = true;

		if($this->lastErrno == 3) {
			$this->lastError = "CURLE_URL_MALFORMAT";
		}else if($this->lastErrno == 60) {
			$this->lastError = "CURLE_PEER_FAILED_VERIFICATION";
		}

        return $this;
	}

	public function getDebug(): string {
		return $this->lastCurlDebug;
	}

	public function getErrno(): int {
		return $this->lastErrno;
	}

    public function getLastError(): string {
        return $this->lastError;
    }

	public function getUrl(): string {
		return $this->url;
	}

    public function isExecuted(): bool {
        return $this->executed;
    }

	public function getResponse($forseJsonEncode = false): mixed  {
		if(!$this->executed) {
            $this->exec();
        }

		if($forseJsonEncode || $this->headers["Accept"] == "application/json")
			return json_decode($this->response, true);

		return $this->response;
	}

	public function getResponseStatusCode(): bool | int {
		if(!$this->executed) return false;

		return $this->response->code;
	}

	public function isError(): bool {
		if(!$this->executed) return false;

		if (!$this->response || !isset($this->response->code) || $this->response->code !== $this->expectedStatus) {
			return true;
		}

		return false;
	}
}

class Request {
    private array $headers = [];

    public function __construct(){
        $this->headers = getallheaders();
    }

    public function get($key) : string | null {
        return isset($_GET[$key])? $_GET[$key]: null;
    }

    public function post($key) : string | null {
        return isset($_POST[$key])? $_POST[$key]: null;
    }

    public function var($key) : string | null {
        if(isset($_GET[$key])) return $_GET[$key];
        if(isset($_POST[$key])) return $_POST[$key];
        return null;
    }

    public function server($key) : string | null {
        return isset($_SERVER[$key]) ? $_SERVER[$key] : null;
    }

    public function header($key) : string | null {
        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    public function is($type): bool {
        return strtolower($_SERVER['REQUEST_METHOD']) === trim(strtolower($type));
    }

    public function isSecure(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }    
}

class Response {
    private Request $request;
    
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_INTERNAL_SERVER_ERROR = 500;

    public function __construct(Request $request){
        $this->request = $request;
    }

    public function setHeader(string $name, string $value, bool $replace = true) {
        header("$name: $value", $replace);
    }

    public function noCache() {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
    }

    public function enableCors() {
        if ($this->request->is('OPTIONS')) {
			header('Access-Control-Allow-Origin: *');
			header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
			header('Access-Control-Allow-Headers: token, Content-Type');
			header('Access-Control-Max-Age: 1728000');
			header('Content-Length: 0');
			header('Content-Type: text/plain');
			die();
		}
	
		header('Access-Control-Allow-Origin: *');
		header('Content-Type: application/json');
    }

    public function status(?int $status = 0) : int | bool {
        return http_response_code($status);
    }
}

define("CACHE", 1);

$container = Container::getInstance();
$container->setSingleton(Router::class);
$container->setSingleton(Page::class);
$container->setSingleton(Layout::class);
$container->setSingleton(Database::class);
$container->setSingleton(UserService::class);
$container->setSingleton(Request::class);
$container->setSingleton(Response::class);