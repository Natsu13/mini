<?php
define("USE_USERS", 1);
define("USE_EXCEPTION_HANDLER", 1);

if(!defined("DEBUG")) {
    error_reporting(E_ERROR | E_PARSE);
}

define("ROOT", str_replace("\\", "/", getcwd()));

/**
 * $autoloader->addLib("PhpSpreadsheet", "PhpOffice", "libs");
 * Debuging the libs
 * $autoloader = \Container::getInstance()->get(\Autoload::class);     
 * Utilities::vardump($autoloader->debugClass('PhpOffice\PhpSpreadsheet\Spreadsheet'));
 * Utilities::vardump($autoloader->getLibraryPaths());
 */
class Autoload {
    private string $cacheFile;
    private array $classMap = [];
    private array $loadedFiles = [];
    private array $libraryPaths = [];

    public function __construct() {
        $this->cacheFile = __DIR__ . '/autoload.cache.json';
        $this->loadCache();
    }

    private function loadCache(): void {
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $data = json_decode($content, true) ?? [];
            $this->classMap = $data['classMap'] ?? [];
            $this->libraryPaths = $data['libraryPaths'] ?? [];
        }
    }

    private function saveCache(): void {
        // Před zápisem znovu načteme cache pro zajištění konzistence
        $currentCache = [];
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $currentCache = json_decode($content, true) ?? [];
        }

        // Sloučíme aktuální cache s naší lokální kopií
        $mergedClassMap = array_merge($currentCache['classMap'] ?? [], $this->classMap);
        $mergedLibraryPaths = array_merge($currentCache['libraryPaths'] ?? [], $this->libraryPaths);

        $mergedCache = [
            'classMap' => $mergedClassMap,
            'libraryPaths' => $mergedLibraryPaths
        ];

        // Pokusíme se o atomický zápis pomocí lock
        $lockFile = $this->cacheFile . '.lock';
        $lockHandle = fopen($lockFile, 'w');
        
        if (flock($lockHandle, LOCK_EX)) {
            try {
                file_put_contents(
                    $this->cacheFile,
                    json_encode($mergedCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    LOCK_EX
                );
                $this->classMap = $mergedClassMap;
                $this->libraryPaths = $mergedLibraryPaths;
            } finally {
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                @unlink($lockFile);
            }
        } else {
            fclose($lockHandle);
            @unlink($lockFile);
        }
    }

    private function getDeclaredClassSet(): array {
        return array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
    }

    public function register(): void {
        spl_autoload_register([$this, 'loadClass'], /* throw = */ true, /* prepend = */ false);
    }

    /**
     * Přidá knihovnu s vlastním namespace a cestou
     * @param string $name Název knihovny (např. "PhpSpreadsheet")
     * @param string $namespace Root namespace knihovny (např. "PhpOffice")
     * @param string $path Cesta ke knihovně (např. "libs/PhpSpreadsheet")
     */
    public function addLib(string $name, string $namespace = '', string $path = ''): void {
        // Pokud není zadán namespace, použije se název knihovny
        if (empty($namespace)) {
            $namespace = $name;
        }
        
        // Pokud není zadána cesta, použije se libs/{name}
        if (empty($path)) {
            $path = 'libs/' . $name;
        }

        // Normalize namespace (odstraní leading/trailing backslashes)
        $namespace = trim($namespace, '\\');
        
        // Normalize path
        $path = rtrim($path, '/\\');

        $this->loadCache();
        $this->libraryPaths[$namespace] = $path;
        $this->saveCache();
    }

    /**
     * Najde soubor pro danou třídu na základě registrovaných knihoven
     */
    private function findFileForClass(string $class): ?string {
        foreach ($this->libraryPaths as $namespace => $basePath) {
            if (strpos($class, $namespace . '\\') === 0) {
                // Odstraníme root namespace z názvu třídy
                $relativePath = substr($class, strlen($namespace) + 1);
                
                // Převedeme namespace na cestu
                $filePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath) . '.php';
                $fullPath = $basePath . DIRECTORY_SEPARATOR . $filePath;
                
                // Debug - můžeš toto dočasně odkomentovat pro testování
                // echo "Trying: $fullPath\n";
                
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }
        }
        
        return null;
    }

    public function loadClass(string $class): void {
        // Před každým pokusem o načtení aktualizujeme cache
        $this->loadCache();

        if (isset($this->classMap[$class])) {
            $file = $this->classMap[$class];
            if (!in_array($file, $this->loadedFiles, true)) {
                if (file_exists($file)) {
                    require_once $file;
                    $this->loadedFiles[] = $file;
                    return;
                } else {
                    // Soubor neexistuje, odebereme ho z cache
                    unset($this->classMap[$class]);
                    $this->saveCache();
                }
            }            
        }

        // Nejprve zkusíme najít soubor pomocí registrovaných knihoven
        $file = $this->findFileForClass($class);
        
        // Pokud nenajdeme v knihovnách, zkusíme standardní cestu
        if (!$file) {
            $standardFile = strtolower(str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php');
            if (file_exists($standardFile)) {
                $file = $standardFile;
            }
        }

        if (!$file || !file_exists($file)) {
            //throw new Exception("Autoloader: File for class '$class' does not exist. Tried library paths and standard path.");
            return;
        }

        $before = $this->getDeclaredClassSet();
        require_once $file;
        $after = $this->getDeclaredClassSet();

        $newClasses = array_diff($after, $before);
        $hasNewClasses = false;

        foreach ($newClasses as $newClass) {
            if (!isset($this->classMap[$newClass])) {
                $this->classMap[$newClass] = $file;
                $hasNewClasses = true;
            }
        }

        // Cache ukládáme pouze pokud máme nové třídy
        if ($hasNewClasses) {
            $this->saveCache();
        }

        if (!in_array($class, $newClasses, true)
            && !class_exists($class, false)
            && !interface_exists($class, false)
            && !trait_exists($class, false)) {
            throw new Exception("Autoloader: Class '$class' could not be loaded from file '$file'.");
        }
    }

    // Metoda pro ruční přidání tříd do cache
    public function addClass(string $class, string $file): void {
        $this->loadCache();
        $this->classMap[$class] = $file;
        $this->saveCache();
    }

    // Metoda pro vyčištění neexistujících souborů z cache
    public function cleanCache(): void {
        $this->loadCache();
        $cleaned = false;
        
        foreach ($this->classMap as $class => $file) {
            if (!file_exists($file)) {
                unset($this->classMap[$class]);
                $cleaned = true;
            }
        }
        
        if ($cleaned) {
            $this->saveCache();
        }
    }

    // Getter pro debug účely
    public function getClassMap(): array {
        $this->loadCache();
        return $this->classMap;
    }

    // Getter pro registrované knihovny
    public function getLibraryPaths(): array {
        $this->loadCache();
        return $this->libraryPaths;
    }

    /**
     * Odstraní knihovnu z registrovaných cest
     */
    public function removeLib(string $namespace): void {
        $this->loadCache();
        unset($this->libraryPaths[$namespace]);
        $this->saveCache();
    }

    /**
     * Debug metoda - ukáže kde hledá soubor pro danou třídu
     */
    public function debugClass(string $class): array {
        $this->loadCache();
        $debug = [
            'class' => $class,
            'in_cache' => isset($this->classMap[$class]),
            'library_attempts' => []
        ];
        
        foreach ($this->libraryPaths as $namespace => $basePath) {
            if (strpos($class, $namespace . '\\') === 0) {
                $relativePath = $class;//substr($class, strlen($namespace) + 1);
                $filePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath) . '.php';
                $fullPath = $basePath . DIRECTORY_SEPARATOR . $filePath;
                
                $debug['library_attempts'][] = [
                    'namespace' => $namespace,
                    'path' => $fullPath,
                    'exists' => file_exists($fullPath)
                ];
            }
        }
        
        $standardPath = strtolower(str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php');
        $debug['standard_path'] = [
            'path' => $standardPath,
            'exists' => file_exists($standardPath)
        ];
        
        return $debug;
    }
}

class DocParser {
    /**
     * The parse method processes the doc string and calls the resolver callback for each annotation found.
     *
     * @param string   $docString Documentation block text.
     * @param callable $resolver  A callback that receives a method name and an array of arguments.
     */
    public static function parse(string $docString, callable $resolver): void {
        $docString = trim($docString);
        $lines = preg_split('/\R/', $docString);
        
        foreach ($lines as $line) {
            $line = trim($line, " *\t\n\r\0\x0B");
            
            if (strpos($line, '@') === 0) {
                // Regex captures the method name and any arguments
                // Possible formats:
                //   @method("arg1", "arg2")   -> contains arguments in parentheses (group 2)
                //   @primaryKey               -> without arguments
                //   @get index                -> argument without parentheses (group 3)
                if (preg_match('/^@(\w+)(?:\(([^)]*)\))?(?:\s+(.+))?$/', $line, $matches)) {
                    $methodName = strtolower($matches[1]);
                    $argString = "";
                    
                    if (isset($matches[2]) && $matches[2] !== "") {
                        $argString = $matches[2];
                    } elseif (isset($matches[3]) && $matches[3] !== "") {
                        $argString = $matches[3];
                    }
                    
                    $arguments = [];
                    if (!empty($argString)) {
                        $arguments = str_getcsv($argString, ',', '"');
                        $arguments = array_map('trim', $arguments);
                    }
                    
                    call_user_func($resolver, $methodName, $arguments);
                }
            }
        }
    }

    /**
     * Resolves a given string value to its corresponding enum case.
     *
     * @param string $enumClass The fully qualified enum class name (e.g. Method::class).
     * @param string $value     The value to convert into an enum case.
     *
     * @return object The matching enum case.
     *
     * @throws Exception If the enum class does not exist or the value does not match any case.
     */
    public static function resolveEnum(string $enumClass, string $value): object {
        if (!enum_exists($enumClass)) {
            throw new Exception("Enum class $enumClass does not exist.");
        }
        
        // Loop through the enum cases and perform a case-insensitive match
        foreach ($enumClass::cases() as $case) {
            if (strtoupper($case->name) === strtoupper($value)) {
                return $case;
            }
        }
        
        throw new Exception("Value '$value' not found in enum $enumClass.");
    }

    /**
     * Resolves a function from a string definition.
     *
     * The definition must be in the format "Class::method" or "::method".
     * If the class part is omitted (i.e. "::method"), an instance must be provided.
     *
     * If an instance is not provided, the class will be created using
     * Container::getInstance()->create($class).
     *
     * The method is returned as a ReflectionMethod.
     *
     * @param string            $definition The function definition (e.g. "::Save" or "Admin::EditPost").
     * @param object|null       $instance   Optional instance of the class. If omitted, the instance will be created.
     * @param string            $namespace  The namespace to prepend to the class name.
     * @param string            $inheritClass The class that the class must inherit from.
     *
     * @return ReflectionMethod The resolved method as a ReflectionMethod.
     *
     * @throws Exception        If the definition is invalid, or the class/method cannot be resolved.
     */
    public static function resolveFunction(string $definition, ?object $instance = null, string $namespace = "Controllers", string $inheritClass = "Controller"): ReflectionMethod {
        // Split the definition string into class and method parts using "::" as delimiter.
        $parts = explode("::", $definition);
        if (count($parts) !== 2) {
            throw new Exception("Definition must be in the format 'Class::method' or '::method'.");
        }
        
        // Trim and extract parts.
        $classPart = trim($parts[0]);
        $method = trim($parts[1]);
        
        // If the class part is omitted, we expect an instance to be passed.
        if ($classPart === '') {
            if ($instance === null) {
                throw new Exception("No class provided in definition and no instance was passed for method '$method'.");
            }
            //$class = get_class($instance);
        } else {
            $originalClass = $classPart;
            $class = $classPart;
            
            $namespace = $namespace."\\";
            // If the class name does not start with "Controllers\", prepend it.
            if (strpos($class, $namespace) !== 0) {
                $class = $namespace . $class;
            }
            
            // If the class does not exist, try appending "Controller"
            if (!class_exists($class) && class_exists($class . $inheritClass)) {
                $class .= $inheritClass;
            }
            
            if (!class_exists($class)) {
                throw new Exception("Class '$originalClass' does not exist.");
            }
            
            if (!is_subclass_of($class, $inheritClass)) {
                throw new Exception("Class '$originalClass' does not implement '$inheritClass'.");
            }
            
            $instance = Container::getInstance()->create($class);
        }
        
        // Check if the method exists on the instance.
        if (!method_exists($instance, $method)) {
            throw new Exception("Method '$method' not found in class '" . get_class($instance) . "'.");
        }
        
        // Get the ReflectionMethod of the method.
        $reflectionMethod = new ReflectionMethod($instance, $method);
        
        return $reflectionMethod;
    }
}

if(defined("DEBUG")) {
    class DebugTimer {
        private static array $timers = [];

        public static function start(string $name): void {
            if (isset(self::$timers[$name])) {
                throw new Exception("Timer '$name' is already running.");
            }
            self::$timers[$name] = microtime(true);
        }

        public static function stop(string $name): float {
            if (!isset(self::$timers[$name])) {
                throw new Exception("Timer '$name' was not started.");
            }

            $duration = microtime(true) - self::$timers[$name];
            return $duration;
        }

        public static function dump(int $precision = 4): void {
            echo "Debuging times: <br/>";
            foreach(self::$timers as $name => $time) {
                $duration = microtime(true) - $time;
                echo "⏱ Timer '$name': " . round($duration, $precision) . " seconds<br/>";
            }
        }
    }
}else{
    class DebugTimer {
        public static function start(string $name): void {}
        public static function stop(string $name): float { return 0.0; }
        public static function dump(int $precision = 4): void { }
    }
}

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
                } else if (is_array($o)) {
                    Utilities::vardump($o, $level + 1);
                } else if (is_object($o)) {
                    Utilities::vardump($o, $level + 1);
                } else {
                    $type = gettype($o);
                    echo "<span class=typev>" . $type . "</span> ";
                    echo "<span class='value type-" . $type . "'>";
                    if ($type == "string") {
                        echo "'" . htmlentities($o) . "'";
                    } else if ($type == "boolean") {
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

    public static function roundToDecimal(float $number, int $decimals = 2): float {
        if ($decimals < 0) {
            throw new InvalidArgumentException("Number of decimals must be non-negative.");
        }
        $factor = pow(10, $decimals);
        return round($number * $factor) / $factor;
    }
}

class Date {
    private static int $timezoneOffset = 0; // Default timezone offset in hours

    /**
     * Sets the global timezone offset (in hours).
     *
     * @param int $offset The number of hours to shift the timezone (e.g. +1, -1).
     */
    public static function setTimezoneOffset(int $offset): void {
        self::$timezoneOffset = $offset;
    }

    public static function month($val): string {
		return date("F", mktime(0, 0, 0, $val, 1, 2024));
	}
	
	public static function day($val){
		switch($val){
			case 1: return "Monday";
			case 2: return "Tuesday";
			case 3: return "Wednesday";
			case 4: return "Thursday";
			case 5: return "Friday";
			case 6: return "Saturday";
			case 7: return "Sunday";
		}
		return "Unknown";
	}

    /**
     * Formats a Unix timestamp into a human-readable date/time string in English.
     *
     * @param int  $timestamp    The Unix timestamp.
     * @param bool $noConvert    If true, the method will use special conversion rules 
     *                           (e.g. "Today at", "Yesterday at", "Tomorrow at").
     *                           If false, a standard format "day. month at hour:minute" is returned.
     * @param bool $withoutTime  If true, returns only the date (day, month, year) without time.
     * @return string            The formatted date/time string.
     */
    public static function toString(int $timestamp, bool $noConvert = true, bool $withoutTime = false): string {
        // Create a DateTime object from the timestamp
        $dt = (new DateTime())->setTimestamp($timestamp);

        // Apply global timezone offset if set
        if (self::$timezoneOffset !== 0) {
            $modifier = (self::$timezoneOffset > 0 ? "+" . self::$timezoneOffset . " hours" : self::$timezoneOffset . " hours");
            $dt->modify($modifier);
        }

        $day      = $dt->format('j');
        $monthNum = $dt->format('m');
        $year     = $dt->format('Y');
        $hour     = $dt->format('H');
        $minute   = $dt->format('i');

        // Get the English name of the month
        $month = self::month($monthNum);

        // Return only the date if requested
        if ($withoutTime) {
            return $day . ". " . $month . " " . $year;
        }

        // If no special conversion is requested, return the standard format
        if (!$noConvert) {
            return $day . ". " . $month . " at " . $hour . ":" . $minute;
        }

        // Create a DateTime object for the current moment
        $now = new DateTime();

        // Compare only the calendar dates (Y-m-d)
        $nowDate = $now->format('Y-m-d');
        $dtDate  = $dt->format('Y-m-d');

        // Create DateTime objects for the start of the day (00:00:00) for both dates
        $todayStart = new DateTime($nowDate);
        $dtStart    = new DateTime($dtDate);

        // Calculate the difference in days, preserving the sign (%r)
        $diffInterval = $todayStart->diff($dtStart);
        $diffDays = (int)$diffInterval->format('%r%a');

        // Decide how to format the string based on the difference in calendar days
        if ($diffDays === 0) {
            // Today
            $formatted = "Today at " . $hour . ":" . $minute;
        } else if ($diffDays === -1) {
            // Yesterday
            $formatted = "Yesterday at " . $hour . ":" . $minute;
        } else if ($diffDays === 1) {
            // Tomorrow
            $formatted = "Tomorrow at " . $hour . ":" . $minute;
        } else {
            // For other days
            if ($year === $now->format('Y')) {
                // If within the current year, include time but not the year
                $formatted = $day . " " . $month . " at " . $hour . ":" . $minute;
            } else {
                // Otherwise, display full date without time
                $formatted = $day . ". " . $month . " " . $year;
            }
        }

        return $formatted;
    }

    /**
     * @param string $datumText date in format j.n.Y H:i or specify by $format
     * @param string $format format of the date
     */
    public static function toTimestamp(string $datumText, string $format = 'j.n.Y H:i'): int {
        $dt = DateTime::createFromFormat($format, $datumText);
        if (!$dt) {
            throw new Exception("Invalid date format: $datumText");
        }
        return $dt->getTimestamp();
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
            'totalOutside' => true,
            'totalPosition' => 'right',
            'prevText' => '&laquo;',
            'nextText' => '&raquo;',
            'containerClass' => 'pagination',
            'itemClass' => 'page-item',
            'linkClass' => 'page-link',
            'textClass' => 'page-text',
            'activeClass' => 'active',
            'displayCount' => 7,
            'jsCallback' => null,
            'currentPage' => null
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

    public function getCurrentPage(): int {
        $page = $this->options['currentPage'] == null? ($_GET[$this->options['pageParam']] ?? 1): intval($this->options['currentPage']);
        return max(1, min((int)$page, $this->getTotalPages()));
    }

    public function getTotalPages(): int {
        return max(1, ceil($this->totalItems / $this->itemsPerPage));
    }

    private function getVisiblePages(): array {
        $totalPages    = $this->getTotalPages();
        $currentPage   = $this->currentPage;
        $displayCount  = $this->options["displayCount"]; 
        $innerCount    = $displayCount - 2; 
    
        $result = [];
    
        if ($totalPages <= $displayCount) {
            for ($i = 1; $i <= $totalPages; $i++) {
                $result[] = ["val" => $i, "type" => PaginatorType::Page];
            }
        } else {
            $result[] = ["val" => 1, "type" => PaginatorType::Page];
    
            $halfInner = floor($innerCount / 2);
    
            if ($currentPage <= ($halfInner + 2)) {
                $start = 2;
                $end   = $innerCount + 1;
                for ($i = $start; $i <= $end; $i++) {
                    $result[] = ["val" => $i, "type" => PaginatorType::Page];
                }
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
            }
            else if ($currentPage >= $totalPages - ($halfInner + 1)) {
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
                $start = $totalPages - $innerCount;
                for ($i = $start; $i < $totalPages; $i++) {
                    $result[] = ["val" => $i, "type" => PaginatorType::Page];
                }
            }
            
            else {
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
                $start = $currentPage - $halfInner;
                $end   = $currentPage + $halfInner;
                
                if (($end - $start + 1) < $innerCount) {
                    $end++;
                }
                for ($i = $start; $i <= $end; $i++) {
                    $result[] = ["val" => $i, "type" => PaginatorType::Page];
                }
                $result[] = ["val" => "...", "type" => PaginatorType::Dots];
            }
    
            $result[] = ["val" => $totalPages, "type" => PaginatorType::Page];
        }
    
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

        $jsCallback = null;
        if($this->options["jsCallback"] != null) {
            $jsCallback = " onclick=\"return ".$this->options["jsCallback"].";\"";
        }

        if($type == PaginatorType::Page) {
            $page = intval($value);            
            $url = str_replace('(:page)', (string)$page, $this->urlPattern);

            if($page == $this->getCurrentPage())
                $itemClass .= ' ' . $this->options['activeClass'];

            return sprintf(
                '<li class="%s"><a href="%s" class="%s"%s>%s</a></li>',
                $itemClass,
                $url,
                $linkClass,
                $jsCallback == null? "": sprintf($jsCallback, $page),
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
                '<li class="%s"><a href="%s" class="%s"%s>%s</a></li>',
                $itemClass,
                $url,
                $linkClass,
                $jsCallback == null? "": sprintf($jsCallback, $page),
                $value
            );
        }

        return sprintf("<li>%s</li>", $value);
    }

    public function render(): string {
        $html = [];

        if($this->options["totalOutside"] && $this->options['showTotal'] && $this->options['totalPosition'] !== 'right') {
            $html[] = sprintf(
                '<span class="total-counter">Total: <span>%d</span></span>',
                $this->totalItems
            );
        }

        $html[] = sprintf('<nav class="%s"><ul>', $this->options['containerClass']);

        if (!$this->options["totalOutside"] && $this->options['showTotal'] && $this->options['totalPosition'] !== 'right') {
            $html[] = sprintf(
                '<li class="%s">Total: <span>%d</span></li>',
                $this->options['textClass'],
                $this->totalItems
            );
        }

        foreach ($this->getVisiblePages() as $page) {
            $html[] = $this->renderLink(
                $page["val"],
                $page["type"]
            );
        }

        if (!$this->options["totalOutside"] && $this->options['showTotal'] && $this->options['totalPosition'] === 'right') {
            $html[] = sprintf(
                '<li class="%s">Total: <span>%d</span></li>',
                $this->options['textClass'],
                $this->totalItems
            );
        }

        $html[] = '</ul></nav>';

        if($this->options["totalOutside"] && $this->options['showTotal'] && $this->options['totalPosition'] === 'right') {
            $html[] = sprintf(
                '<span class="total-counter">Total: <span>%d</span></span>',
                $this->totalItems
            );
        }

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

    /**
     * Create instance of the specified class with the given arguments.
     * On end of the constructor can be specified arguments of the registered classed in the container.
     * 
     * @template T
     * @param class-string<T> $class The name of the model class, eg Layout
     * @return T|null Returns an instance of the specified class or null if the record does not exist
     */
    public function create(string $class, ...$args) {
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
    
        if (!$constructor) {
            return new $class(...$args);
        }
    
        $params = $constructor->getParameters();
        $resolvedArgs = [];
    
        $containerParamsCount = 0;
        for ($i = count($params) - 1; $i >= 0; $i--) {
            $param = $params[$i];
            $type = $param->getType();      
            if ($type && !$type->isBuiltin() && isset($this->services[$type->getName()])) {
                $containerParamsCount++;
            } else {
                break;
            }
        }
                
        $manualArgsCount = count($params) - $containerParamsCount;
        if ($manualArgsCount < 0) {
            throw new Exception("Too few arguments were passed.");
        }
        $manualArgs = array_slice($args, 0, $manualArgsCount);
        $containerOverrides = array_slice($args, $manualArgsCount);
    
        $manualIndex = 0;
        $containerIndex = 0;
    
        foreach ($params as $param) {
            $type = $param->getType();            
            if ($type && !$type->isBuiltin() && isset($this->services[$type->getName()])) {
                
                if (isset($containerOverrides[$containerIndex])) {
                    $resolvedArgs[] = $containerOverrides[$containerIndex++];
                } else {
                    $resolvedArgs[] = $this->get($type->getName());
                }
            } else {
                if (isset($manualArgs[$manualIndex])) {
                    $resolvedArgs[] = $manualArgs[$manualIndex++];
                } elseif ($param->isDefaultValueAvailable()) {
                    $resolvedArgs[] = $param->getDefaultValue();
                } else {
                    throw new Exception("Missing value for parameter '{$param->getName()}'");
                }
            }
        }

        return $reflection->newInstanceArgs($resolvedArgs);
    }
}

enum Method {
    case UNKNOWN;
    case GET;
    case POST;
    case PUT;
    case DELETE;    
}

class Router {
    private array $routes = [];
    private ?string $matchedUrl = null;
    private ?array $routeData = null;
    private string $baseUrl;
    private string $cacheFile = 'routes.cache.json';
    private array $controllers = [];
    private array $procesed = [];

    private ?ControllerAction $controllerData = null;
    private Layout $layout;
    private Request $request;
    private AuthentificatorProvider $authenticationProvider;    

    public function __construct(Layout $layout, Request $request, AuthentificatorProvider $authenticationProvider) {
        $this->baseUrl = $this->generateBaseUrl();
        $this->layout = $layout;
        $this->request = $request;
        $this->authenticationProvider = $authenticationProvider;
        $this->controllers = glob("controllers/*.php");

        $this->loadRoutes();
    }

    /* router cache from controller */

    private function isCacheValid(): bool {
        if (!file_exists($this->cacheFile)) {
            return false;
        }

        $cache = json_decode(file_get_contents($this->cacheFile), true);

        if (!isset($cache['hashes'])) {
            return false;
        }

        foreach ($this->controllers as $file) {
            $path = str_replace('\\', '/', $file); // normalize paths
            if (!isset($cache['hashes'][$path]) || $cache['hashes'][$path] !== md5_file($file)) {
                return false;
            }
        }

        return true;
    }

    private function loadFromCache() {
        $cache = json_decode(file_get_contents($this->cacheFile), true);
        foreach($cache['routes'] as $route) {
            $this->add($route['route'], [$route['controller'], $route['method']]);
        }
    }

    private function saveCache($routes) {
        $hashes = [];
        foreach ($this->controllers as $file) {
            $path = str_replace('\\', '/', $file);
            $hashes[$path] = md5_file($file);
        }

        $cache = [
            'hashes' => $hashes,
            'routes' => $routes
        ];

        file_put_contents($this->cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
    }

    private function loadRoutes() {
        DebugTimer::start('router.controller.loading');

        if (!defined("DEBUG") && $this->isCacheValid()) {
            $this->loadFromCache();
        } else {
            $routes = $this->generateRoutes();
            foreach($routes as $route) {
                $this->add($route['route'], [$route['controller'], $route['method']]);
            }
            $this->saveCache($routes);
        }

        DebugTimer::stop('router.controller.loading');
    }

    private function generateRoutes(): array {
        $routes = [];

        foreach ($this->controllers as $controller) {
            $controllerName = basename($controller, ".php");
            $controllerClass = "Controllers\\" . $controllerName;

            if (class_exists($controllerClass)) {
                $reflection = new ReflectionClass($controllerClass);

                if ($reflection->isSubclassOf(Controller::class)) {
                    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                    foreach ($methods as $method) {
                        $docComment = $method->getDocComment();

                        if ($docComment !== false) {                                
                            DocParser::parse($docComment, function (string $annotation, array $arguments) use ($controllerClass, $method, &$routes) {
                                if ($annotation === 'route' && !empty($arguments)) {
                                    $route = $arguments[0];
                                    $priority = isset($arguments[1]) ? (int)$arguments[1] : 0;
                                    
                                    $routes[] = [
                                        'route' => $route,
                                        'controller' => $controllerClass,
                                        'method' => $method->getName(),
                                        'priority' => $priority
                                    ];
                                }
                            });
                        }
                    }
                }
            }
        }

        usort($routes, function($a, $b) {
            $priorityComparison = $b['priority'] <=> $a['priority'];
            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }
            
            $routeA = preg_replace('/\[.*?\]/', '', $a['route']);
            $routeB = preg_replace('/\[.*?\]/', '', $b['route']);
            
            $segmentsA = count(array_filter(explode('/', trim($routeA, '/')), 'strlen'));
            $segmentsB = count(array_filter(explode('/', trim($routeB, '/')), 'strlen'));
            
            return $segmentsA <=> $segmentsB;
        });

        return $routes;
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

    private function combineOptionalSegments(string $pattern): string {
        while (preg_match('/(\[\/<[^>]+>\])\s*(\[(\/<[^>]+>)\])/', $pattern, $matches)) {
            $first = $matches[1];
            $second = $matches[2];
            
            $firstInner = substr($first, 1, -1); 
            $secondInner = substr($second, 1, -1);
            
            $combined = '[' . $firstInner . '(?:' . $secondInner . ')?]';
            
            $pattern = str_replace($first . $second, $combined, $pattern);
        }
        return $pattern;
    }

    public function add(string $path, string|callable|array $handler, bool $redirect = false): void {
        $path = $this->combineOptionalSegments($path);
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
        ob_clean();
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
                $this->procesed[] = "Match route: $url, $key";
                
                if (is_callable($matchedRoute['handler'])) {       
                    $this->procesed[] = "Called by callable handler";
                    call_user_func($matchedRoute['handler'], $matchedRoute['variables'] ?? []);
                    return;
                } else if (is_array($matchedRoute['handler'])) {
                    $this->procesed[] = "Called by array handler";
                    $this->processVariables($matchedRoute['variables']);
                    $this->callController($matchedRoute['handler'], $matchedRoute['variables']);
                } else if ($matchedRoute['redirect']) {
                    $this->procesed[] = "Called by redirect";
                    $this->redirect($matchedRoute['handler']);
                } else {
                    $this->procesed[] = "Called by other";
                    $this->processRouteHandler($matchedRoute, $key);
                }
                break;
            }
        }
    }

    private function processVariables($variables) {
        if($variables == null || !is_array($variables)) return;
        foreach($variables as $name => $value) {
            $_GET[$name] = $value;
        }
    }

    private function callController($definition, $variables){
        if(!is_array($definition)) throw new Exception("Definition must be array [class, method]");        
        $class = $originalClass = $definition[0];
        $methodNameOrCallback = count($definition) > 1? $definition[1]: "index";        
        
        if(substr($class, 0, strlen("Controllers\\")) != "Controllers\\") $class = "Controllers\\". $class;          
        if(!class_exists($class) && class_exists($class."Controller")) $class .= "Controller";

        if(!class_exists($class)) 
            throw new Exception("Class $originalClass not exists");
        if(!is_subclass_of($class, "Controller")) 
            throw new Exception("Class $originalClass not implement class \"Controller\"");

        $instance = Container::getInstance()->create($class);

        //TODO: problem like "sort" etc.. functions is callable :/ maybe we can make that it must be anonymouse method
        if(is_callable($methodNameOrCallback)){
            $method = $methodNameOrCallback($variables);            
        }else {
            $method = $methodNameOrCallback;
        }

        if($method == null || $method == "") $method = "index";
        
        if(!method_exists($instance, $method)) 
            throw new Exception("Method $method not found in class $class");

        $reflectionMethod = new ReflectionMethod($instance, $method);
        $methodName = $this->request->method()->name;
        $methodsToRedirect = [];        

        $methodsToRedirect[] = "$reflectionMethod->class::$reflectionMethod->name";
        while($reflectionMethod != null) {
            $requiredMethod = Method::UNKNOWN;
            $replaceMethodOn = [];

            $docComment = $reflectionMethod->getDocComment();
            DocParser::parse($docComment, function($name, $args) use (&$requiredMethod, &$replaceMethodOn, $instance) {            
                if($name == "method") {                
                    $requiredMethod = DocParser::resolveEnum(Method::class, $args[0]);
                }
                else if($name == "get") {
                    $replaceMethodOn[Method::GET->name] = DocParser::resolveFunction($args[0], $instance);
                }
                else if($name == "post") {
                    $replaceMethodOn[Method::POST->name] = DocParser::resolveFunction($args[0], $instance);
                }
            });

            if($requiredMethod != Method::UNKNOWN && !$this->request->is($requiredMethod)) {
                if(isset($replaceMethodOn[$methodName])) {                                    
                    $reflectionMethod = $replaceMethodOn[$methodName];
                     
                    $fullName = "$reflectionMethod->class::$reflectionMethod->name";
                    if(in_array($fullName, $methodsToRedirect)) {
                        throw new Exception("Circual redirection detected, ".implode(" -> ", $methodsToRedirect)." -> $fullName");
                    }                    
                    $methodsToRedirect[] = $fullName;
                    continue;
                } else {
                    throw new ControllerMethodNotAllowedException($requiredMethod);
                }            
            }
            
            $methodParams = $reflectionMethod->getParameters();
            $resolvedParams = [];
            foreach ($methodParams as $param) {
                $name = $param->getName();
                if (isset($_GET[$name])) {
                    $resolvedParams[] = $_GET[$name];
                } elseif (isset($_POST[$name])) {
                    $resolvedParams[] = $_POST[$name];
                } elseif (isset($_SERVER[$name])) {
                    $resolvedParams[] = $_SERVER[$name];
                } elseif (isset($_FILES[$name])) {
                    $resolvedParams[] = $_FILES[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $resolvedParams[] = $param->getDefaultValue();
                } else {
                    throw new Exception("Missing value for parameter '$name' of method $method in class $class");
                }
            }
             
            if($this->needAuthentification($reflectionMethod)){                
                $this->redirectToLoginIfNeeded();
            }

            $resultModel = $reflectionMethod->invokeArgs($instance, $resolvedParams);
            if($resultModel == null) {
                throw new ControllerReturnedNullException($reflectionMethod->class, $reflectionMethod->name);
            }
    
            foreach($methodsToRedirect as $mtr){
                $this->procesed[] = "--> $mtr";
            }
            $this->controllerData = $resultModel;
            break;
        }
    }

    public function redirectToLoginIfNeeded(){
        $authentication = $this->authenticationProvider->get();                
        if(!$authentication->isAuthentificationRequired() || $authentication->isAuthenticated()) return;        
        
        if($this->request->isJsonRequest()) {
            ob_clean();
            http_response_code(401);
            echo json_encode([
                'status' => 'need_login',
                'message' => 'You need to be loged in to view this page'
            ]);
            exit();
        }

        $authUrl = $authentication->getAuthentificationUrl();
        if(!empty($authUrl)) {
            $this->redirect($authUrl);
        } else {
            throw new Exception("Authentication required, but no URL provided.");
        }
    }

    public function needAuthentification(ReflectionMethod $reflectionMethod): bool {
        $allowAnonymous = false;

        $docComment = (new ReflectionClass($reflectionMethod->class))->getDocComment();
        DocParser::parse($docComment, function($name, $args) use (&$allowAnonymous) {        
            if($name == "allowanonymous") {                
                $allowAnonymous = true;
            }
        });
        if($allowAnonymous) return false;

        $docComment = $reflectionMethod->getDocComment();
        DocParser::parse($docComment, function($name, $args) use (&$allowAnonymous) {
            if($name == "allowanonymous") {                
                $allowAnonymous = true;
            }
        });
        if($allowAnonymous) return false;

        return true;
    }

    public function tryProcessController(): bool {
        if($this->controllerData == null)
            return false;        

        if($this->controllerData->getType() == ControllerActionType::View) {
            $class = $this->controllerData->getClass();
            $view = $this->controllerData->getView();
            $model = $this->controllerData->getModel();      
            $shouldClear = $this->controllerData->shouldClearContent();                  
            
            $viewFile = !file_exists(ROOT."/views/{$class}/{$view}.view")? ROOT."/views/{$view}.view": ROOT."/views/{$class}/{$view}.view";
            if(file_exists($viewFile)) {
                if($shouldClear) {
                    ob_clean(); 
                }
                
                $this->layout->render($viewFile, $model);

                if($shouldClear) exit();
            } else {
                throw new Exception("View file not found: /views/{$class}/{$view}.view, /views/{$view}.view");
            }

            return true;
        }
        else if($this->controllerData->getType() == ControllerActionType::Json) {           
            ob_clean(); 
            header('Content-Type: application/json');
            echo $this->controllerData->getJson();
            exit();
        }
        else if($this->controllerData->getType() == ControllerActionType::Redirect) {
            $this->redirect($this->controllerData->getRedirectUrl());
        }
        else if($this->controllerData->getType() == ControllerActionType::None) {
            //continue return type
        }

        return false;
    }

    private function processRouteHandler(array $route, int $key): void {
        if (!is_string($route['handler'])) return;

        $handler = $route['handler'];
        if (!empty($route['variables'])) {
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
        $pattern = str_replace('/', '\\/', $path);
        
        $pattern = preg_replace_callback('/\[(.*?)\]/', function($match) {
            return '(?:' . $match[1] . ')?';
        }, $pattern);
        
        $pattern = preg_replace_callback('/\<([^>]+)\>/', function($match) {
            $parts = explode('=', $match[1]);
            $name = trim($parts[0]);
            return '(?P<' . $name . '>[^\/]+)';
        }, $pattern);
        
        return $pattern;
    }

    private function extractRouteVariables(string $url, array $route, int $key): void {
        if (preg_match('/^' . $route['regex'] . '$/U', $url, $matches)) {            
            $params = [];
            foreach ($matches as $keyName => $value) {
                if (is_string($keyName)) {
                    $params[$keyName] = $value !== '' ? $value : null;
                }
            }
            if (!empty($params)) {
                $this->routes[$key]['variables'] = $params;
            }
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
        echo "<div style='padding:7px;'>";
        foreach($this->procesed as $p) {
            echo "<div>$p</div>";
        }
        echo "</div>";
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

        $rootUrl = Router::url();
		if($this->scripts != null) {
			foreach($this->scripts as $script) {
				if($script["inhead"]) {
                    if(substr($script["url"], 0, strlen($rootUrl)) == $rootUrl)
					    echo '<script type="text/javascript" src="' . Url::addParam($script["url"], "cache", CACHE) . '"></script>'.PHP_EOL;
                    else
                        echo '<script type="text/javascript" src="' . $script["url"] . '"></script>'.PHP_EOL;
                }
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

class TemplaterV2Exception extends \Exception {
    protected $col;
    protected $row;

    public function __construct($message, $file = null, $col = null, $row = null) {
        parent::__construct($message);

        if ($file !== null) {
            $this->file = $file;
        }

        $this->col = $col;
        $this->row = $row;
    }

    public function getCol() {
        return $this->col;
    }

    public function getRow() {
        return $this->row;
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
    private $printHtmlComments = true;

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
				//throw new Exception("Infinite loop occured! Token: " . $this->printToken($token)." ".$this->printTokenInfo($token));
                $this->throwException($token, "Infinite loop occured! Token: " . $this->printToken($token)." ".$this->printTokenInfo($token));
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
            $this->throwException($token, ($reportedOnly?"Reported token":"Unkown token")." type '".TokenType::print($token["type"])."' ".$this->printTokenInfo($token).$message."; File: " . $this->fileName."\n");
		}
    }

    private function throwException($token, $message) {
        throw new TemplaterV2Exception(
                $message,
                $this->fileName,
                $token["type"] != -1? $token["info"]["line"]["col"]: 0,
                $token["type"] != -1? $token["info"]["line"]["row"]: 0
            );
    }

    private function rollback(){
        $this->tokenPos--;
    }

    private function getTokenWithoutSpace() {
        $i = 0;
        while(trim(($token = $this->getToken($i++))["value"]) != "") {}
        return $this->getToken($i);
    }

    private function tryEatHtmlComment(){
        $content = "";
        $next = $this->getToken(0);
        if($next["value"] == "!--") {
            $this->eat(TokenType::$TEXT);
            while($this->getToken(0)["type"] != TemplaterBuildEnd::$EOF && $this->getToken(0)["value"] != "--" && $this->getToken(1)["value"] != ">") {
                $content.= $this->getToken(0)["value"];
                $this->eat();
            }
            $this->eat();
            $this->eat();
            if($this->printHtmlComments) {
                $this->printOutput("<!-- ".trim($content)." -->");
            }
            return true;
        }         
        return false;
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
                if($this->tryEatHtmlComment()) {
                    continue;
                }
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

                    //$elementParameter = $token;
                    $elementParameter = $this->consumeElementParameter($token);
                    //$this->eat(TokenType::$TEXT);

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

    private function consumeElementParameter($token) {
        $value = $token["value"];
        $this->eat();
        
        while(true) {
            $next = $this->getToken();
            
            if($next["type"] == TokenType::$GREAT_THEN || 
            $next["type"] == TokenType::$ASSIGN || 
            $next["type"] == TokenType::$SLASH ||
            $next["type"] == TokenType::$NEW_LINE ||
            $next["type"] == TokenType::$EOF ||
            $next["type"] == $this->controllTokensDefinition[0]) {
                break;
            }
            
            if(trim($next["value"]) == "" && $next["type"] != TokenType::$TEXT) {
                break;
            }
            
            if($next["type"] == TokenType::$TEXT) {
                $value .= $next["value"];
                $this->eat();
            } else {
                $specialChars = ['.', ':', '-', '@'];
                if(in_array($next["value"], $specialChars)) {
                    $value .= $next["value"];
                    $this->eat();
                } else {
                    break;
                }
            }
        }
        
        return [
            "type" => TokenType::$TEXT,
            "value" => $value,
            "info" => $token["info"]
        ];
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
            $lastToken = $this->openControllTokens[count($this->openControllTokens) - 1]["token"];
			//throw new Exception($message."\n");
            $this->throwException($lastToken, $message);
		}
	}

    private function renderControll($input, $token) {
        $controll = explode(" ", $input, 2);
		$type = trim($controll[0]);
        $value = count($controll) > 1? trim($controll[1]): "";

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
			$params = $this->parserParams($value, 1);
            $name = $this->toPhp($params[0]);
            $parameters = $this->toPhp($params[1]);
			return "<?php Container::getInstance()->get(Layout::class)->render(ROOT . \"/views/".$name.".view\", ".$parameters."); ?>";
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
        } else if($type == "use") {
            return "<?php use ".$value."; ?>";
		} else if($type == "var") {
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

    private function toPhp($code) {
        $code = trim($code);

        if(substr($code, 0, 1) == "[" && substr($code, strlen($code) -1, 1) == "]") {
            return "array(".substr($code, 1, strlen($code) - 2).")";
        }  
        if(strpos($code, ",") !== false || strpos($code, "=>") !== false) {
            return "array(".$code.")";
        }
        if(substr($code, 0, 1) == "\"" && substr($code, strlen($code) -1, 1) == "\"") {
            return substr($code, 1, strlen($code) - 2);
        }
        if(substr($code, 0, 1) == "'" && substr($code, strlen($code) -1, 1) == "'") {
            return substr($code, 1, strlen($code) - 2);
        }              
        return "\".".$code."\".";
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
			//throw new Exception("Output cache index is less than zero!");
            $this->throwException(["type" => TokenType::$EOF, "value" => ""], "Output cache index is less than zero!");
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
		}else if(gettype($name) == "array" && gettype($value) == "array"){
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

            if($time === 0) {
                setcookie($name, $value, 0, "/");
            }else {
                setcookie($name, $value, (is_int($time)? time() + $time: strtotime($time)), "/");
            }

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
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
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

enum ModelState: string {
    case New = 'new';           //You created the model
    case Loaded = 'loaded';     //Model was loaded from database
    case Changed = 'changed';   //After model saved
}

class ModelDefinition {
    public string $tableName;
    public string $primaryKey;
    public bool $primaryKeyAutoIncrement = true;
}

abstract class Model {
    private string $table;
    private string $primaryKey;
    private bool $primaryKeyAutoIncrement = true;
    
    protected static $definitions = [];

    protected $mappings = [];
    protected $mappingsBack = [];
    protected $columnsDefinition = [];    

    protected Database $database;
    protected $attributes = [];
    protected ModelState $state = ModelState::New;

    public function __construct(array $data = []) {
        $this->database = Container::getInstance()->get(Database::class);
        $this->loadDefaults();
        $this->populate($data);
    }

    protected static function getTableDefinition(): ModelDefinition {
        $class = static::class;
        if(isset(Model::$definitions[$class])) 
            return Model::$definitions[$class];

        $object = new $class();
        
        $def = new ModelDefinition();
        $def->tableName = $object->table;
        $def->primaryKey = $object->primaryKey;
        $def->primaryKeyAutoIncrement = $object->primaryKeyAutoIncrement;

        Model::$definitions[$class] = $def;
        return $def;
    }

    private static function getConnection(): PDO {
        return Container::getInstance()->get(Database::class)->getConnection();
    }

    private function populate(array $data) {
        $this->getColumnMappings();

        if(!empty($data)) 
            $this->state = ModelState::Loaded;        
        else if (method_exists($this, 'onCreated'))
            $this->onCreated();

        $reflector = new ReflectionClass($this);
        
        foreach ($data as $column => $value) {
            $propertyName = array_search($column, $this->mappings, true);
            if ($propertyName !== false) {
                $property = $reflector->getProperty($propertyName);
                $type = $property->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();

                    if (enum_exists($typeName)) {
                        $refEnum = new ReflectionEnum($typeName);
                        if ($refEnum->isBacked()) {
                            $enumValue = $typeName::tryFrom($value);
                            if ($enumValue !== null) {
                                $this->$propertyName = $enumValue;
                            } else {
                                throw new \UnexpectedValueException("Neplatná hodnota '$value' pro enum $typeName");
                            }
                            continue;
                        }
                    }
                }

                $this->$propertyName = $value;
            }
        }
    }

    public function isNew(): bool {
        return $this->state == ModelState::New;
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
                    $typeName = $type->getName();

                    if ($type->allowsNull()) {
                        $defaultValue = null;
                    } else if(!$type->isBuiltin() && class_exists($typeName)) {
                        $refClass = new ReflectionClass($typeName);
                        if ($refClass->isEnum()) {
                            $enumCases = $typeName::cases();
                            if (count($enumCases) > 0) {
                                $defaultValue = $enumCases[0];
                            } else {
                                throw new Exception("Enum $typeName has no defined values");
                            }
                        } else {
                            throw new Exception("Class $typeName is not an enum");
                        }
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

    private function getColumnMappings() {
        $reflector = new ReflectionClass($this);

        $docComment = $reflector->getDocComment();
        if (preg_match('/@table\("([^"]+)"\)/', $docComment, $matches)) {
            $this->table = $matches[1];
        }

        if($this->table == null) {
            throw new Exception("Table name is not set for model ".get_class($this));
        }

        $properties = $reflector->getProperties(ReflectionProperty::IS_PUBLIC);
        $this->mappings = [];
        $this->mappingsBack = [];
        $this->columnsDefinition = []; 
        
        foreach ($properties as $property) {
            $docComment = $property->getDocComment();
            $column = $property->getName();
            $length = null;

            if (strpos($docComment, '@primaryKey') !== false) {
                $this->primaryKey = $column;

                if (strpos($docComment, '@autoIncrementDisabled') !== false) {
                    $this->primaryKeyAutoIncrement = false;
                }
            }

            if (preg_match('/@column\("([^"]+)"\)/', $docComment, $matches)) {
                $column = $matches[1];
            }

            if (preg_match('/@length\((\d+)\)/', $docComment, $lengthMatch)) {
                $length = (int)$lengthMatch[1];
            }

            $propName = $property->getName();
            $this->mappings[$propName] = $column;
            $this->mappingsBack[$column] = $propName;
            $this->columnsDefinition[$column] = [
                "length" => $length
            ];
        }
    }

    public static function findById(int|string $id): ?static {
        $definition = self::getTableDefinition();
        return self::where([$definition->primaryKey => $id])->fetch();
    }

    public static function fetchAll(): ?array {
        $definition = self::getTableDefinition();
        $db = self::getConnection();
        $stmt = $db->prepare("SELECT * FROM ".$definition->tableName);  
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $objects = [];
        foreach ($rows as $row) {
            $objects[] = new static($row);
        }
        return $objects;
    }

    public static function where($condition, $params = []): QueryBuilder {
        return self::toQuery()->where($condition, $params);
    }

    public static function toQuery(): QueryBuilder {
        $definition = self::getTableDefinition();
        return new QueryBuilder(self::getConnection(), $definition->tableName, static::class);
    }

    public static function toList(int $limit = 20, ?string $orderBy = null, ?int $page = null, array $options = [], ?QueryBuilder $query = null): ModelListResult {
        if($query == null) $query = self::toQuery();        

        if($page != null) $options["currentPage"] = $page;
        $paginator = new \Paginator($query->count(), $limit, \Router::url(true), $options);
        if($orderBy) $query = $query->order($orderBy);

        $result = $query->limit($limit)->page($paginator->getCurrentPage());

        $model = new ModelListResult();
        $model->query = $result;
        $model->paginator = $paginator;
        return $model;
    }

    /**
     * Delete the row in table by id
     */
    public function delete(): string {
        $db = $this->database->getConnection();
        $primaryKey = $this->primaryKey;

        if($primaryKey == null || $this->state === ModelState::New) {
            throw new Exception("Primary key is not set for model ".get_class($this).", or the model was not saved so we can't delete the model");
        }

        //TODO: delete all childs
        $stmt = $db->prepare("DELETE FROM " . $this->table . " WHERE $primaryKey = ?");
        $stmt->execute([$this->$primaryKey]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Save the model to database if the model is new it will insert the row, if the model is loaded it will update the row
     */
    public function save(): bool {
        $db = $this->database->getConnection();
        $columns = array_map(fn($key) => $this->mappings[$key], array_keys($this->attributes));
        $primaryKey = $this->primaryKey;

        if($primaryKey == null) {
            throw new Exception("Primary key is not set for model ".get_class($this)." so we can't save the model");
        }

        $columns = [];
        $values = [];
        foreach ($this->mappings as $property => $column) {
            if ($property !== $primaryKey) {
                $columns[] = $column;
                $value = $this->$property;
                
                if (is_object($value) && $value instanceof \UnitEnum) {
                    if ($value instanceof \BackedEnum) {
                        $value = $value->value;
                    } else {
                        throw new Exception("Enum {$column} has no value (not a backed enum)");
                    }
                }
                
                // Převod boolean hodnot na integer pro MySQL
                if (is_bool($value)) {
                    $value = (int)$value; // false -> 0, true -> 1
                }
                
                if (is_string($value) && isset($this->columnsDefinition[$column]['length'])) {
                    $maxLength = $this->columnsDefinition[$column]['length'];
                    if ($maxLength !== null && mb_strlen($value) > $maxLength) {
                        $value = mb_substr($value, 0, $maxLength);
                    }
                }

                $values[] = $value;
            }
        }
        
        if ($this->$primaryKey === null || $this->state === ModelState::New) {
            // INSERT
            $placeholders = array_fill(0, count($columns), '?');
            $stmt = $db->prepare("INSERT INTO " . $this->table . " (" . implode(", ", $columns) . ") VALUES (" . implode(", ", $placeholders) . ")");

            if ($stmt->execute($values)) {
                if ($this->primaryKeyAutoIncrement) {
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
            $stmt = $db->prepare("UPDATE " . $this->table . " SET " . implode(", ", $setColumns) . " WHERE $primaryKey = ?");
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

            // hasMany - foreign key je v cílové tabulce
            if (preg_match('/@hasMany\("([^"]+)"\s*,\s*"?([^"]*)"?\)/', $docComment, $matches)) {
                $relationships[$method->getName()] = [
                    'type' => 'hasMany',
                    'class' => $matches[1],
                    'foreignKey' => !empty($matches[2]) ? $matches[2] : strtolower(get_class($this)) . '_id'
                ];
            }
            // belongsTo - foreign key je v aktuální tabulce
            else if (preg_match('/@belongsTo\("([^"]+)"\s*,?\s*"?([^"]*)"?\)/', $docComment, $matches)) {
                $relationships[$method->getName()] = [
                    'type' => 'belongsTo',
                    'class' => $matches[1],
                    'foreignKey' => !empty($matches[2]) ? $matches[2] : 'id' //strtolower($matches[1]) . '_id'
                ];
            }
            // hasOne - foreign key je v aktuální tabulce (jako User → Permission)
            else if (preg_match('/@hasOne\("([^"]+)"\s*,?\s*"?([^"]*)"?\)/', $docComment, $matches)) {
                $relationships[$method->getName()] = [
                    'type' => 'hasOne',
                    'class' => $matches[1],
                    'foreignKey' => !empty($matches[2]) ? $matches[2] : strtolower($matches[1]) . '_id'
                ];
            }
            // hasOneInverse - foreign key je v cílové tabulce (jako Manifest → DailyStat)
            else if (preg_match('/@hasOneInverse\("([^"]+)"\s*,?\s*"?([^"]*)"?\)/', $docComment, $matches)) {
                $relationships[$method->getName()] = [
                    'type' => 'hasOneInverse',
                    'class' => $matches[1],
                    'foreignKey' => !empty($matches[2]) ? $matches[2] : strtolower(get_class($this)) . '_id'
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
        
        if ($relation['type'] === 'hasMany') {                        
            $foreignKey = isset($this->mappings[$relation['foreignKey']])? $this->mappings[$relation['foreignKey']]: $relation['foreignKey'];
            $className = "Models\\". $relation['class'];
            
            $result = (new $className)
                ->where([$foreignKey => $this->{$this->primaryKey}]);
            return $result; // Return QueryBuilder for hasMany relation, because it can be used for further chaining
        }
        else if ($relation['type'] === 'hasOne') {
            $foreignKey = isset($this->mappingsBack[$relation['foreignKey']])? $this->mappingsBack[$relation['foreignKey']]: $relation['foreignKey'];
            if (!isset($this->{$foreignKey})) {
                throw new Exception("Foreign key $foreignKey is not set in " . get_class($this)." for relation $method");
            }
            $foreignValue = $this->{$foreignKey};
            $className = "Models\\". $relation['class'];

            $result = $foreignValue ? ($className)::findById($foreignValue) : null;
        }
        else if ($relation['type'] === 'hasOneInverse') {
            $foreignKey = isset($this->mappings[$relation['foreignKey']])? $this->mappings[$relation['foreignKey']]: $relation['foreignKey'];
            $className = "Models\\". $relation['class'];
            
            $result = ($className)::where([$foreignKey => $this->{$this->primaryKey}])->fetch();
        }
        else if ($relation['type'] === 'belongsTo') {
            $foreignKey = isset($this->mappingsBack[$relation['foreignKey']])? $this->mappingsBack[$relation['foreignKey']]: $relation['foreignKey'];
            $foreignValue = $this->{$foreignKey};
            $className = "Models\\". $relation['class'];

            $result = $foreignValue ? ($className)::where(["id" => $foreignValue])->fetch() : null;
        }

        $this->relationCache[$method] = $result;
        return $result;
    }

    /**
     * Get relation by method name - can be called internally by the class itself
     * 
     * @param string $relationName Name of the relation method
     * @return mixed The relation result (QueryBuilder for hasMany, Model instance or null for belongsTo/hasOne)
     */
    protected function getRelation(string $relationName) {
        return $this->loadRelationship($relationName);
    }

    public function __call($method, $args) {
        $relationships = $this->getRelationships();
    
        if (isset($relationships[$method])) {
            return $this->loadRelationship($method);
        }
    
        throw new Exception("Method $method not found in " . get_class($this));
    }

    /**
     * Generates an SQL CREATE TABLE statement based on the model's annotations and properties.
     *
     * @param string $modelClass Fully qualified class name of the model.
     * @param string $defaultCharset Default charset for the table (default: utf8)
     * @param string $defaultCollation Default collation for text-based columns (default: utf8_bin)
     * @return string The generated SQL statement.
     * @throws Exception If the table name annotation is not found.
     */
    public static function generateCreateTableQuery(string $modelClass, string $defaultCharset = "utf8", string $defaultCollation = "utf8_bin"): string {
        $reflection = new ReflectionClass($modelClass);

        $docComment = $reflection->getDocComment();
        if (!$docComment || !preg_match('/@table\("([^"]+)"\)/', $docComment, $matches)) {
            throw new Exception("Table annotation (@table(...)) not found in class {$modelClass}");
        }
        $tableName = $matches[1];

        $columns = [];
        $definedColumns = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $columnName = $property->getName();

            $propDoc = $property->getDocComment();
            if ($propDoc && preg_match('/@column\("([^"]+)"\)/', $propDoc, $colMatch)) {
                $columnName = $colMatch[1];
            }

            $type = $property->getType();
            $typeName = $type ? $type->getName() : 'string';

            if (!$type->isBuiltin() && class_exists($typeName)) {
                $refClass = new ReflectionClass($typeName);
                if ($refClass->isEnum()) {
                    $refEnum = new ReflectionEnum($typeName);
                    if($refEnum->isBacked()) {
                        $typeName = $refEnum->getBackingType()->getName();
                    }else{
                        throw new Exception("Enum {$typeName} is not backed enum, you need specify it's type");
                    }
                }
            }            

            $length = null;
            if ($propDoc && preg_match('/@length\((\d+)\)/', $propDoc, $lengthMatch)) {
                $length = (int)$lengthMatch[1];
            }

            $charset = "";
            if ($typeName === 'string') {
                if ($length) {
                    $sqlType = "varchar({$length})";
                } else {
                    $sqlType = "text";
                }
                $charset = " COLLATE {$defaultCollation}";
            } else {
                switch ($typeName) {
                    case 'int':
                        $sqlType = $length !== null ? "int({$length})" : "int";
                        break;
                    case 'float':
                        $sqlType = 'float';
                        break;
                    case 'bool':
                        $sqlType = 'boolean';
                        break;
                    default:
                        $sqlType = 'text';
                        $charset = " COLLATE {$defaultCollation}";
                }
            }

            $primaryKey = ($propDoc && strpos($propDoc, '@primaryKey') !== false);
            
            $nullability = (!$primaryKey && $type && $type->allowsNull()) ? 'NULL' : 'NOT NULL';
            $autoIncrementDisabled = ($propDoc && strpos($propDoc, '@autoIncrementDisabled') !== false);

            $defaultClause = "";
            if ($propDoc && preg_match('/@default\(([^)]+)\)/', $propDoc, $defaultMatch)) {
                $defaultValue = trim($defaultMatch[1]);
                if ($typeName === 'string') {
                    if ($defaultValue[0] !== "'" && $defaultValue[0] !== '"') {
                        $defaultValue = "'" . $defaultValue . "'";
                    }
                } else if ($typeName === 'bool') {
                    $defaultValue = (strtolower($defaultValue) === 'true' || $defaultValue === '1') ? 'TRUE' : 'FALSE';
                }
                $defaultClause = " DEFAULT " . $defaultValue;
            }

            $colDefinition = "`{$columnName}` {$sqlType}{$charset} {$nullability}";
            if ($primaryKey) {
                $colDefinition .= " PRIMARY KEY";
                if ($typeName === 'int' && !$autoIncrementDisabled) {
                    $colDefinition .= " AUTO_INCREMENT";
                }
            }
            $colDefinition .= $defaultClause;

            $columns[] = $colDefinition;
            $definedColumns[$columnName] = true;
        }

        $keys = [];
        $foreignKeys = [];
        $keysAdded = [];
        $relationTables = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PRIVATE) as $method) {            
            $methodDoc = $method->getDocComment();            
            if (!$methodDoc) {
                continue;
            }
                                            
            if (preg_match('/@hasOne\("([^"]+)"(?:,\s*"([^"]+)")?(?:,\s*"([^"]+)")?\)/', $methodDoc, $match)) {       
                $relatedClass = $match[1];
                if(!isset($relationTables[$relatedClass])) {
                    $docComment = new ReflectionClass("\\Models\\".$relatedClass);                    
                    if (preg_match('/@table\("([^"]+)"\)/', $docComment->getDocComment(), $matches)) {                        
                        $relatedClass = $relationTables[$relatedClass] = $matches[1];
                    } else {
                        $relationTables[$relatedClass] = $relatedClass;
                    }
                }else{
                    $relatedClass = $relationTables[$relatedClass];
                }                

                if(isset($match[3]) && $match[3] != null) {
                    $foreignKeyColumn = $match[3];
                    $isForeign = true;
                } else {
                    $foreignKeyColumn = "id";
                    $isForeign = false;
                }
                $localTableKey = (isset($match[2]) && $match[2]) ? $match[2] : strtolower($method->getName()) . '_id';  

                if (isset($definedColumns[$localTableKey]) || $isForeign) {
                    if (!isset($keysAdded[$localTableKey])) {
                        $indexName = preg_replace('/_id$/', '', $localTableKey);
                        if ($indexName === "") {
                            $indexName = $localTableKey;
                        }
                        $keys[] = "KEY `{$indexName}` (`{$localTableKey}`)";
                        $keysAdded[$localTableKey] = true;
                    }
                    $foreignKeys[] = "CONSTRAINT `{$tableName}_ibfk_{$localTableKey}` FOREIGN KEY (`{$localTableKey}`) REFERENCES `" . strtolower($relatedClass) . "` (`".$foreignKeyColumn."`) ON DELETE NO ACTION ON UPDATE NO ACTION";
                }
            }
        }

        $columnsSql = implode(",\n    ", $columns);
        $constraints = array_merge($keys, $foreignKeys);
        $constraintsSql = !empty($constraints) ? ",\n    " . implode(",\n    ", $constraints) : "";

        $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n    {$columnsSql}{$constraintsSql}\n) ENGINE=InnoDB DEFAULT CHARSET={$defaultCharset} COLLATE={$defaultCollation};";
        return $sql;
    }
}

class ModelListResult {
    public QueryBuilder $query;
    public Paginator $paginator;
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
                $compare = $cond === null? "IS": "=";

                $this->where[] = [
                    'type' => count($this->where) > 0 ? 'AND' : '',
                    'value' => "$key $compare $bindName",
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

	public function fetchAll(): array {
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
    public function fetch(): ?Model {
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

        if ($isCount && count($this->items) === 0){
            $sql[] = $queryType;
        } else if (count($this->items) === 0) {
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

if(defined("USE_USERS")) {
    enum UserServiceCheck {
        case Ok;
        case EmailExists;
        case EmailInvalid;
        case LoginExists;
        case PasswordBad;    
        case WrongEmail;
        case WrongToken;
        case Unknown;
    }

    enum UserServiceLogin {
        case Ok;
        case WrongPassword;
        case WrongLogin;
    }

    class UserService {
        private Router $router;
        private AuthentificatorProvider $authentificatorProvider;

        public function __construct(Router $router, AuthentificatorProvider $authentificatorProvider) {
            $this->authentificatorProvider = $authentificatorProvider;
            $this->router = $router;
        }

        public function check(string $login, string $email): UserServiceCheck {
            if(!Utilities::isEmail($email)) return UserServiceCheck::EmailInvalid;

            $user = Models\User::find($login, $email);
            if($user == null) return UserServiceCheck::Ok;

            if($user->login == $login) return UserServiceCheck::LoginExists;
            if($user->email == $email) return UserServiceCheck::EmailExists;

            return UserServiceCheck::Unknown;
        }

        /**
         * Register new user
         * @param string $login
         * @param string $password
         * @param string $email
         * @param callable $callback optional function to modify user before save
         * @return Models\User | UserServiceCheck
         */
        public function register(string $login, string $password, string $email, ?callable $callback = null): Models\User | UserServiceCheck {
            $state = $this->check($login, $email);

            if($state == UserServiceCheck::Ok) {
                $user = new Models\User();
                $user->login = $login;
                $user->password = sha1($password);
                $user->email = $email;
                $user->permissionId = 1;
                
                if($callback != null) {
                    $callback($user);
                }

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
            $auth = $this->authentificatorProvider->get();
            if(!$auth->isAuthenticated()) return false;

            $userId = $auth->getValue();
            $user = Models\User::findById($userId);
            if($user != null) return true;   

            return false;
        }

        public function login(string $login, string $password, ?callable $callback = null): UserServiceLogin | string {
            $user = Models\User::find($login, $login);

            if($user == null) return UserServiceLogin::WrongLogin;
            if($user->password != $this->hashPassword($password)) return UserServiceLogin::WrongPassword;
            if($callback != null) {
                $result = $callback($user);
                if($result !== true) {
                    return $result;
                }
            }

            $auth = $this->authentificatorProvider->get();
            $auth->authenticate($user->id);

            return UserServiceLogin::Ok;
        }

        public function hashPassword(string $password): string {
            return sha1($password);
        }

        public function logout() {
            $auth = $this->authentificatorProvider->get();
            $auth->deauthenticate();
        }

        public function current(): ?Models\User {
            if(!$this->isAuthentificated()) return null;
            $auth = $this->authentificatorProvider->get();
            return Models\User::findById($auth->getValue());
        }

        public function changePassword(string $password): bool {
            if(!$this->isAuthentificated()) return false;

            $user = $this->current();
            $user->password = $this->hashPassword($password);
            $user->save();

            return true;
        }

        public function resetPassword(string $email): UserServiceCheck {
            if(!Utilities::isEmail($email)) return UserServiceCheck::EmailInvalid;

            $user = Models\User::findByEmail($email);
            if($user == null) return UserServiceCheck::WrongEmail;

            $ticket = TicketService::createTicket(Models\TicketType::PasswordReset, $user->id, $email);
            
            EmailService::send(
                $email,
                "Password recovery",
                "<p>Hello, someone probably asked you to reset your password, you can do so at this <a href='".Router::url()."/password-reset-ticket/".$ticket->token."/'>link</a></p>");

            return UserServiceCheck::Ok;
        }

        public function checkResetPasswordTicket(string $token, ?Models\User &$user, ?Models\Ticket &$ticket): UserServiceCheck {
            $ticket = TicketService::findByToken($token);
            if($ticket == null) return UserServiceCheck::WrongToken;
            if($ticket->type->value != Models\TicketType::PasswordReset->value) return UserServiceCheck::WrongToken;

            $user = Models\User::findById($ticket->entityId);
            if($user == null) return UserServiceCheck::WrongToken;

            return UserServiceCheck::Ok;
        }

        public function changePasswordByTicket(string $token, string $password): UserServiceCheck {            
            $state = $this->checkResetPasswordTicket($token, $user, $ticket);
            if($state != UserServiceCheck::Ok) {
                return $state;
            }

            $user->password = $this->hashPassword($password);
            $user->save();

            // Mark ticket used after successful password reset
            $ticket->markUsed();

            return UserServiceCheck::Ok;
        }
    }
}

class TicketService {
    public static function createTicket(Models\TicketType $type, ?int $entityId = null, ?string $data = null): Models\Ticket {
        $ticket = new Models\Ticket();
        $ticket->token = Utilities::random(10);
        $ticket->type = $type;
        $ticket->entityId = $entityId;
        $ticket->data = $data;
        $ticket->save();

        return $ticket;
    }

    public static function findByToken(string $token): ?Models\Ticket {
        return Models\Ticket::where(["token" => $token])->fetch();
    }
}

class EmailService {
    public static function send(string $to, string $subject, string $message, ?string $fromEmail = null, ?string $fromName = null): bool {
        if (empty($to) || empty($subject) || empty($message)) {
            throw new Exception("EmailService: Missing parameters.");
        }
        
        $encodedSubject = '=?utf-8?B?' . base64_encode($subject) . '?=';

        if(empty($fromEmail)) {
            $fromEmail = "no-reply@" . $_SERVER['HTTP_HOST'];
        }
        if(empty($fromName)) {
            $fromName = $_SERVER['HTTP_HOST'];
        }

        $headers = "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        if (defined("DEBUG") && DEBUG) {
            return self::saveToFile($to, $encodedSubject, $message, $headers);
        }

        return mail($to, $encodedSubject, $message, $headers);
    }

    private static function saveToFile(string $to, string $subject, string $message, string $headers): bool{
        $folder = ROOT . '/emails';

        if (!is_dir($folder)) {
            if (!mkdir($folder, 0777, true)) {
                error_log("Unable to create a folder for debug emails.");
                return false;
            }
        }

        $timestamp = date('Y-m-d_H-i-s');
        $filename = $folder . "/email_{$timestamp}_" . uniqid() . ".html";

        $content = "To: {$to}<br/>Subject: {$subject}<br/>{$headers}<br/><br/>{$message}";

        return file_put_contents($filename, $content) !== false;
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
			"Accept" => "*/*",
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
		parse_str(isset($urlParts['query'])? $urlParts['query']: "", $params);
	
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

	public function postJson(string $url, string | object | array $json = []): self {
        if(!is_string($json))
            $json = json_encode($json);

		$this->headers["Content-Type"] = "application/json";
        $this->headers["Accept"] = "application/json";
        $length = $json == null? 0: strlen($json);
		$this->sendRequest($url, $json, $length, "POST");
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
        $this->headers["Accept"] = "application/json";
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

	public function getDebug(): ?array {
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

    public function is(string | Method $type): bool {
        if(is_string($type))
            return strtolower($_SERVER['REQUEST_METHOD']) === trim(strtolower($type));
        
        if($type == Method::GET)
            return strtolower($_SERVER['REQUEST_METHOD']) === "get";
        if($type == Method::POST)
            return strtolower($_SERVER['REQUEST_METHOD']) === "post";
        if($type == Method::PUT)
            return strtolower($_SERVER['REQUEST_METHOD']) === "put";
        if($type == Method::DELETE)
            return strtolower($_SERVER['REQUEST_METHOD']) === "delete";

        throw new Exception("Unknown method type = {$type->name}");
    }

    public function method(): Method {
        if($this->is(Method::GET)) return Method::GET;
        if($this->is(Method::POST)) return Method::POST;
        if($this->is(Method::PUT)) return Method::PUT;
        if($this->is(Method::DELETE)) return Method::DELETE;

        throw new Exception("Unknown method type");
    }

    public function isAjax(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }

    public function isJsonRequest(): bool {
        return (empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || $this->isAjax();
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

    public function write($content) {
        echo $content;
        ob_end_flush();
        exit();
    }
}

enum ControllerActionType {
    case None;
    case View;
    case Json;
    case Redirect;
}

class ControllerMethodNotAllowedException extends Exception {
    private Method $method;

    public function __construct(Method $method) {
        parent::__construct("Method not allowed", Response::HTTP_BAD_REQUEST);
        $this->method = $method;
    }

    public function getMethod(): Method {
        return $this->method;
    }
}

class ControllerReturnedNullException extends Exception {
    public function __construct(string $class, string $method) {
        $message = "The controller method {$class}::{$method} returned null. "
                 . "If you intend to skip any response or rendering, "
                 . "return \$this->continue() instead.";
        parent::__construct($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

class ControllerAction {
    private ControllerActionType $type = ControllerActionType::None;
    private array $params = [];
    private mixed $object = null;

    public function getType(): ControllerActionType {
        return $this->type;
    }

    public function getParams(): array {
        return $this->params;
    }

    public function getClass(): string {
        if($this->type != ControllerActionType::View) throw new Exception("getClass can be called only for View type");
        $class = $this->params["class"];
        if(substr($class, 0, strlen("Controllers\\")) == "Controllers\\") {
            $class = substr($class, strlen("Controllers\\"));
        }
        return strtolower($class);
    }

    public function shouldClearContent(): bool {
        return (($this->type == ControllerActionType::View && $this->params["clear"]) || $this->type == ControllerActionType::Json);
    }

    public function getView(): string {
        if($this->type != ControllerActionType::View) throw new Exception("getView can be called only for View type");
        return $this->params["view"];
    }

    public function getModel(): array {
        if($this->type != ControllerActionType::View) throw new Exception("getModel can be called only for View type");        
        $model = $this->params["model"];
        return is_null($model)? []: $model;
    }

    public function getJson(): string {
        if($this->type != ControllerActionType::Json) throw new Exception("getJson can be called only for Json type");
        return json_encode($this->object);
    }

    public function getRedirectUrl(): string {
        if($this->type != ControllerActionType::Redirect) throw new Exception("getRedirectUrl can be called only for Redirect type");
        return $this->params["redirect"];
    }

    public static function makeViewModel(string $class, string $view, array|null $model, bool $clearContent): ControllerAction {
        $viewModel = new ControllerAction();
        $viewModel->params = [
            "class" => $class,
            "view" => $view,
            "model" => $model,
            "clear" => $clearContent
        ];
        $viewModel->type = ControllerActionType::View;
        return $viewModel;
    }

    public static function makeJsonModel(mixed $object): ControllerAction {
        $viewModel = new ControllerAction();
        $viewModel->object = $object;
        $viewModel->type = ControllerActionType::Json;
        return $viewModel;
    }

    public static function makeContinueModel(): ControllerAction {
        $viewModel = new ControllerAction();
        $viewModel->type = ControllerActionType::None;
        return $viewModel;
    }

    public static function makeRedirectModel(string $url): ControllerAction {
        $viewModel = new ControllerAction();
        $viewModel->params = [
            "redirect" => $url
        ];
        $viewModel->type = ControllerActionType::Redirect;
        return $viewModel;
    }
}

class Controller {
    private function getNameOfCaller($back = 2) {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        return isset($backtrace[$back]['function']) ? $backtrace[$back]['function'] : null;
    }

    protected function view($name = null, $model = null, $clearContent = false): ControllerAction {
        $className = get_class($this);
        $callerFunction = $this->getNameOfCaller();

        if (($name === null || !is_string($name)) && $model === null && $callerFunction !== null) {
            $model = $name;
            $name = $callerFunction;
        }

        return ControllerAction::makeViewModel($className, $name, $model, $clearContent);
    }

    protected function component($name = null, $model = null) {
        $callerFunction = $this->getNameOfCaller();

        if (($name === null || !is_string($name)) && $model === null && $callerFunction !== null) {
            $model = $name;
            $name = $callerFunction;
        }

        return $this->view($name, $model, true);
    }

    protected function json(mixed $object): ControllerAction {
        return ControllerAction::makeJsonModel($object);
    }

    protected function continue(): ControllerAction {
        return ControllerAction::makeContinueModel();
    }

    protected function redirect(string $url): ControllerAction {
        return ControllerAction::makeRedirectModel($url);
    }
}

class AuthentificatorProvider {
    private ?Authentication $authentication = null;

    public function __construct() {
        
    }

    public function set(Authentication $authentication) {
        $this->authentication = $authentication;
    }

    public function get(): Authentication {
        if($this->authentication == null) {
            //This will ensure that there is always one authnetification
            $this->authentication = (new Authentication())
            ->setAuthenticationMethods([
                'cookies' => new CookieAuthentication('session', '+1 day')
            ])
            ->setDefaultAuthenticationMethod('cookies');
        }
        return $this->authentication;
    }
}

class Authentication {
    /**
     * @var array<string, AuthenticationMethod>
     */
    private array $methods = [];
    private ?AuthenticationMethod $defaultMethod = null;
    private ?string $authentificationUrl = null;
    private bool $needAuthentification = false;

    /**
     * @param array<string, AuthenticationMethod> $methods
     */
    public function setAuthenticationMethods(array $methods): self {
        foreach ($methods as $key => $method) {
            if (!$method instanceof AuthenticationMethod) {
                throw new InvalidArgumentException("Authentication method '$key' must implement AuthenticationMethod interface.");
            }
        }

        $this->methods = $methods;

        // If only one method is provided, set it as default automatically
        if (count($methods) === 1) {
            $this->defaultMethod = reset($methods); // gets the first value
        }

        return $this;
    }

    public function setDefaultAuthenticationMethod(string $name): self {
        if (!isset($this->methods[$name])) {
            throw new Exception("Unknown authentication method: $name");
        }
        $this->defaultMethod = $this->methods[$name];
        return $this;
    }

    public function setAuthentificationUrl(string $url): self {
        $this->authentificationUrl = $url;
        return $this;
    }

    public function getAuthentificationUrl(): ?string {
        return $this->authentificationUrl;
    }

    public function requireAuthentification(bool $require): self {
        $this->needAuthentification = $require;
        return $this;
    }

    public function isAuthentificationRequired(): bool {
        return $this->needAuthentification;
    }

    private function getMethod(?string $method = null): AuthenticationMethod {
        if ($method === null) {
            if ($this->defaultMethod === null) {
                throw new Exception("Default authentication method not set");
            }
            return $this->defaultMethod;
        }

        if (!isset($this->methods[$method])) {
            throw new Exception("Unknown authentication method: $method");
        }

        return $this->methods[$method];
    }

    public function authenticate(string|object $value, ?string $method = null): bool {
        return $this->getMethod($method)->authenticate($value);
    }

    public function deauthenticate(?string $method = null) {
        return $this->getMethod($method)->deauthenticate();
    }

    public function isAuthenticated(?string $method = null): bool {
        return $this->getMethod($method)->isAuthenticated();
    }

    public function getValue(?string $method = null): string|object|null {
        return $this->getMethod($method)->getValue();
    }
}

interface AuthenticationMethod {
    public function authenticate(string|object $value): bool;
    public function deauthenticate();
    public function isAuthenticated(): bool;
    public function getValue(): string|object|null;
}

class CookieAuthentication implements AuthenticationMethod {
    private string $cookieName = "session";
    private int $cookieExpire = 0;

    public function __construct(string $cookieName = "", string | int $expiration = 0) {
        if($cookieName != "") {
            $this->cookieName = $cookieName;
        }
        $this->cookieExpire = (is_int($expiration) && $expiration == 0? strtotime("+1 day", 0): (is_int($expiration)? $expiration: strtotime($expiration, 0)));        
    }

    public function authenticate(string | object $value): bool {
        if($value == null) return false;
        Cookies::set($this->cookieName, $value, $this->cookieExpire);
        return true;
    }

    public function deauthenticate(){
        if(isset($_COOKIE[$this->cookieName])) {
            Cookies::delete($this->cookieName);
        }
    }

    public function isAuthenticated(): bool {
        return isset($_COOKIE[$this->cookieName]) && Cookies::security_check($this->cookieName);
    }

    public function getValue(): string | object | null {
        if($this->isAuthenticated()) return $_COOKIE[$this->cookieName];
        return null;
    }
}

if(defined("USE_EXCEPTION_HANDLER")) {
    class GlobalErrorHandler {
        private string $errorDir;
        private bool $displayErrors;
        private string $vscodeProtocol;
        private ?int $maxLogFiles; // Max number of log files to keep

        public function __construct(string $errorDir = './errors', bool $displayErrors = true, string $vscodeProtocol = 'vscode://file', int $maxLogFiles = 100) {
            $this->errorDir = rtrim($errorDir, '/');
            if (defined("DEBUG") && DEBUG) {
                $this->displayErrors = $displayErrors;
            }else{
                $this->displayErrors = false; // Don't display errors in production
            }
            $this->vscodeProtocol = $vscodeProtocol;
            $this->maxLogFiles = $maxLogFiles;
            
            // Vytvoř složku pro chyby pokud neexistuje
            if (!is_dir($this->errorDir)) {
                mkdir($this->errorDir, 0755, true);
            }

            $this->rotateLogs();
        }

        private function rotateLogs(): void {
            $files = glob($this->errorDir . '/*.json');
            
            if (empty($files)) {
                return;
            }
            
            usort($files, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            $maxFiles = $this->maxLogFiles ?? 100;
            $filesToDelete = count($files) - $maxFiles;
            
            if ($filesToDelete > 0) {
                for ($i = 0; $i < $filesToDelete; $i++) {
                    if (file_exists($files[$i])) {
                        unlink($files[$i]);
                    }
                }
            }
        }

        public function setErrorDir(string $errorDir): void {
            $this->errorDir = rtrim($errorDir, '/');
            if (!is_dir($this->errorDir)) {
                mkdir($this->errorDir, 0755, true);
            }
        }

        public function setDisplayErrors(bool $displayErrors): void {
            $this->displayErrors = $displayErrors;
        }

        public function setVscodeProtocol(string $vscodeProtocol): void {
            $this->vscodeProtocol = rtrim($vscodeProtocol, '/');
        }

        public function register(): void {
            set_error_handler([$this, 'handleError']);
            set_exception_handler([$this, 'handleException']);
            register_shutdown_function([$this, 'handleFatalError']);
        }

        public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool {
            $this->handleException(new ErrorException($errstr, 0, $errno, $errfile, $errline));
            return true;
        }

        public function handleException(Throwable $exception): void {
            $errorData = $this->prepareErrorData($exception);
            $this->logError($errorData);
            
            if ($this->displayErrors) {
                $this->displayErrorPage($errorData);
            } else {
                http_response_code(500);
                echo "<meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'><title>An error occurred</title><style>*{font-family:Arial;}</style>";
                echo "<div style='margin: 30px 50px;'>";
                    echo "<h1>An error occurred</h1>";
                    echo "<p>Sorry, an error occurred while processing your request.</p>";
                    echo "<p>You can share this code <b>".$errorData['id']."</b> with developers so they can fix this problem</p>";
                    echo "<p style='font-size: 13px;'>Error occurrence date <b>".Date::toString(time(), false)."</b></p>";
                echo "</div>";
            }
        }

        public function handleFatalError(): void {
            $error = error_get_last();
            
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                $exception = new ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );
                
                $errorData = $this->prepareErrorData($exception);
                $this->logError($errorData);
                
                if ($this->displayErrors) {
                    $this->displayErrorPage($errorData);
                }
            }
        }

        private function prepareErrorData(Throwable $exception): array {
            $line = $exception->getLine();
            if($exception instanceof TemplaterV2Exception) {
                $line = $exception->getRow() + 1;
            }

            return [
                'id' => uniqid('error_', true),
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $line,
                'trace' => $exception->getTrace(), // Použijeme strukturovaný trace
                'trace_string' => $exception->getTraceAsString(),
                'code' => $exception->getCode(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'localhost'
            ];
        }

        private function logError(array $errorData): void {
            $filename = $this->errorDir . '/' . $errorData['id'] . '.json';
            file_put_contents($filename, json_encode($errorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            error_log("Error {$errorData['id']}: {$errorData['message']} in {$errorData['file']}:{$errorData['line']}");
        }

        private function displayErrorPage(array $errorData): void {
            if (ob_get_level()) {
                ob_clean();
            }

            http_response_code(500);
            
            $vscodeLink = $this->vscodeProtocol . '/' . $errorData['file'] . ':' . $errorData['line'];
            $fileCode = $this->getFileContext($errorData['file'], $errorData['line']);
            $stackTraceHtml = $this->getStackTraceHtml($errorData['trace']);
            
            echo $this->getErrorPageHtml($errorData, $vscodeLink, $fileCode, $stackTraceHtml);
            exit;
        }

        private function getFileContext(string $file, int $line, int $contextLines = 5): array {
            if (!file_exists($file)) {                
                return [];
            }

            $lines = file($file);
            $start = max(0, $line - $contextLines - 1);
            $end = min(count($lines), $line + $contextLines);
            
            $context = [];
            for ($i = $start; $i < $end; $i++) {
                $context[$i + 1] = [
                    'number' => $i + 1,
                    'code' => htmlspecialchars(rtrim($lines[$i])),
                    'is_error' => ($i + 1) === $line
                ];
            }
                        
            return $context;
        }

        private function getStackTraceHtml(array $trace): string {
            $html = '';
            $trace = array_slice($trace, 1);

            foreach ($trace as $index => $step) {
                $file = $step['file'] ?? 'unknown';
                $line = $step['line'] ?? 0;
                $function = $step['function'] ?? 'unknown';
                $class = $step['class'] ?? '';
                $type = $step['type'] ?? '';
                
                $functionCall = $class ? "{$class} {$type} {$function}" : $function;
                $args = $this->formatArguments($step['args'] ?? []);
                
                $vscodeLink = $this->vscodeProtocol . '/' . $file . ':' . $line;
                $fileShort = basename($file);
                $dirPath = dirname($file);
                
                // Získej náhled kódu pro tento krok
                $codePreview = '';
                if ($file !== 'unknown' && file_exists($file)) {
                    $codeContext = $this->getFileContext($file, $line, 2);
                    if (!empty($codeContext)) {
                        $codePreview = "<div class='trace-code-preview'>";
                        foreach ($codeContext as $contextLine) {
                            $class = $contextLine['is_error'] ? 'preview-error-line' : '';
                            $codePreview .= "<div class='preview-line {$class}'>";
                            $codePreview .= "<span class='preview-line-number'>{$contextLine['number']}</span>";
                            $codePreview .= "<span class='preview-line-code'>{$contextLine['code']}</span>";
                            $codePreview .= "</div>";
                        }
                        $codePreview .= "</div>";
                    }
                }
                
                $html .= "
                <div class='trace-step' data-step='{$index}'>
                    <div class='trace-step-header'>
                        <span class='trace-step-number'>#{$index}</span>
                        <div class='trace-step-info'>
                            <div class='trace-function'>
                                <span class='function-name'>{$functionCall}</span>
                                <span class='function-args'>({$args})</span>
                            </div>
                            <div class='trace-location'>
                                " . ($file !== 'unknown' ? "
                                <a href='{$vscodeLink}' class='file-link' title='Open in VS Code'>
                                    <span class='file-path'>{$dirPath}/</span>
                                    <span class='file-name'>{$fileShort}</span>
                                    <span class='file-line'>:{$line}</span>
                                </a>
                                " : "<span class='file-unknown'>Unknown file</span>") . "
                            </div>
                        </div>
                        <button class='trace-toggle' onclick='toggleTraceCode({$index})'>
                            <span class='toggle-icon'>▼</span>
                        </button>
                    </div>
                    <div class='trace-step-code' id='trace-code-{$index}' style='display: none;'>
                        {$codePreview}
                    </div>
                </div>";
            }
            
            return $html;
        }

        private function formatArguments(array $args): string {
            $formatted = [];
            
            foreach ($args as $arg) {
                if (is_string($arg)) {
                    $formatted[] = "'" . (strlen($arg) > 50 ? substr($arg, 0, 50) . '...' : $arg) . "'";
                } elseif (is_numeric($arg)) {
                    $formatted[] = (string)$arg;
                } elseif (is_bool($arg)) {
                    $formatted[] = $arg ? 'true' : 'false';
                } elseif (is_null($arg)) {
                    $formatted[] = 'null';
                } elseif (is_array($arg)) {
                    $formatted[] = 'Array(' . count($arg) . ')';
                } elseif (is_object($arg)) {
                    $formatted[] = get_class($arg) . ' Object';
                } else {
                    $formatted[] = gettype($arg);
                }
            }
            
            return implode(', ', $formatted);
        }

        private function getErrorPageHtml(array $errorData, string $vscodeLink, array $fileCode, string $stackTraceHtml): string {
            $codeHtml = '';
            foreach ($fileCode as $lineData) {
                $class = $lineData['is_error'] ? 'error-line' : '';
                $codeHtml .= "<div class='code-line {$class}'>";
                $codeHtml .= "<span class='line-number'>{$lineData['number']}</span>";
                $codeHtml .= "<span class='line-code'>{$lineData['code']}</span>";
                $codeHtml .= "</div>";
            }

            $errMessage = str_replace("\n", "<br/>", $errorData['message']);

            return "
            <!DOCTYPE html>
            <html lang='cs'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Application exception</title>
                <style>
                    .code-section,.info-section,.trace-section{border-left:4px solid #dc2626}.code-line,.preview-line{font-family:monospace;white-space:pre}
                    .file-link,.vs-code-link,.vscode-link{text-decoration:none}*{margin:0;padding:0;box-sizing:border-box}
                    body{font-family:Consolas,Monaco,'Courier New',monospace;background:#1a1a1a;color:#fff;line-height:1.6}.error-container{
                    background:linear-gradient(135deg,#dc2626,#b91c1c);min-height:100vh;padding:20px}.error-content{margin:0 auto;background:rgba(0,0,0,.8);
                    border-radius:2px;padding:10px 15px;box-shadow:0 20px 40px rgba(0,0,0,.3)}.error-header{border-bottom:2px solid #dc2626;padding-bottom:20px;
                    margin-bottom:30px}.error-title{font-size:2em;color:#ef4444;margin-bottom:0;text-shadow:2px 2px 4px rgba(0,0,0,.5)}.error-subtitle{font-size:1.2em;
                    color:#fca5a5;font-weight:300}.error-info{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:15px}.info-section{
                    background:rgba(220,38,38,.1);padding:8px 10px;border-radius:2px}.info-title{color:#ef4444;font-size:1.1em;margin-bottom:15px;text-transform:uppercase;
                    letter-spacing:1px}.info-item{margin-bottom:2px;display:flex}.info-label{color:#fca5a5;min-width:80px;margin-right:10px}.info-value{color:#fff;
                    word-break:break-all}.vscode-link{display:inline-block;background:#007acc;color:#fff;padding:8px 16px;border-radius:4px;font-size:.9em;
                    margin-top:10px;transition:background .3s}.vscode-link:hover{background:#005a9e}.code-section{background:rgba(0,0,0,.5);padding:8px 10px;
                    border-radius:2px;margin-bottom:15px}.code-line{display:flex;margin-bottom:0;padding:2px 0}.code-line.error-line{background:rgba(220,38,38,.3);
                    border-left:4px solid #ef4444;padding-left:0;margin-left:-4px}.trace-step-header:hover,.trace-toggle:hover{background:rgba(255,255,255,.1)}
                    .line-number{color:#6b7280;min-width:40px;text-align:right;margin-right:15px;user-select:none}.line-code{color:#e5e7eb}.trace-section{
                    background:rgba(0,0,0,.3);padding:20px;border-radius:2px}.trace-step{background:rgba(255,255,255,.05);border-radius:2px;margin-bottom:10px;
                    border:1px solid rgba(220,38,38,.3);overflow:hidden}.trace-step-header{overflow: auto;display:flex;align-items:center;padding:12px 16px;cursor:pointer;
                    transition:background .2s}.trace-step-number{background:#dc2626;color:#fff;padding:4px 8px;border-radius:4px;font-size:.8em;font-weight:700;
                    min-width:30px;text-align:center;margin-right:15px}.function-args,.trace-location{font-size:.9em}.trace-step-info{flex:1}.trace-function{margin-bottom:0}
                    .file-line,.function-name{color:#fbbf24;font-weight:700}.function-args{color:#9ca3af}.file-link{color:#60a5fa;display:inline-flex;align-items:center;
                    transition:color .2s}.file-link:hover{color:#93c5fd;text-decoration:underline}.file-icon{margin-right:6px}.file-path{color:#9ca3af}.file-name{color:#e5e7eb;
                    font-weight:500}.file-unknown{color:#6b7280;font-style:italic}.trace-toggle{background:0 0;border:none;color:#9ca3af;cursor:pointer;padding:5px;
                    border-radius:3px;transition:.2s}.trace-toggle:hover{color:#e5e7eb}.toggle-icon{display:inline-block;transition:transform .2s}.toggle-icon.rotated{
                    transform:rotate(-90deg)}.trace-step-code{overflow: auto;border-top:1px solid rgba(220,38,38,.3);background:rgba(0,0,0,.3)}.trace-code-preview{padding:0}
                    .preview-line{display:flex;padding:3px 16px;font-size:.85em}.preview-line.preview-error-line{background:rgba(220,38,38,.2)}.preview-line-number{
                    color:#6b7280;min-width:35px;text-align:right;margin-right:12px;user-select:none}.preview-line-code{color:#d1d5db}@media (max-width:768px){.error-info{
                    grid-template-columns:1fr}.error-title{font-size:2em}}.vs-code-link{color:inherit;border-bottom:1px dashed #a7a7a7}.code-content{overflow: auto;.}
                </style>
                <script>
                    function toggleTraceCode(e){const t=document.getElementById(\"trace-code-\"+e),o=document.querySelector('[data-step=\"'+e+'\"] .toggle-icon');\"none\"===t.style.display?(t.style.display=\"block\",o.classList.add(\"rotated\")):(t.style.display=\"none\",o.classList.remove(\"rotated\"))}document.addEventListener(\"DOMContentLoaded\",(function(){const e=document.getElementById(\"trace-code-0\"),t=document.querySelector('[data-step=\"0\"] .toggle-icon');e&&t&&(e.style.display=\"block\",t.classList.add(\"rotated\"))}));
                </script>
            </head>
            <body>
                <div class='error-container'>
                    <div class='error-content'>
                        <div class='error-header'>
                            <h1 class='error-title'>Application error</h1>
                            <p class='error-subtitle'><b>{$errorData['type']}</b>: {$errMessage}</p>
                        </div>
                        
                        <div class='error-info'>
                            <div class='info-section'>
                                <h2 class='info-title'>Error details</h2>
                                <div class='info-item'>
                                    <span class='info-label'>Type</span>
                                    <span class='info-value'>{$errorData['type']}</span>
                                </div>
                                <div class='info-item'>
                                    <span class='info-label'>Message</span>
                                    <span class='info-value'>{$errMessage}</span>
                                </div>
                                <div class='info-item'>
                                    <span class='info-label'>File</span>
                                    <span class='info-value'><a href='{$vscodeLink}' title='Open in VS Code' class='vs-code-link'>{$errorData['file']}</a></span>
                                </div>
                                <div class='info-item'>
                                    <span class='info-label'>Line</span>
                                    <span class='info-value'>{$errorData['line']}</span>
                                </div>
                            </div>
                            
                            <div class='info-section'>
                                <h2 class='info-title'>Context</h2>
                                <div class='info-item'>
                                    <span class='info-label'>ID</span>
                                    <span class='info-value'>{$errorData['id']}</span>
                                </div>
                                <div class='info-item'>
                                    <span class='info-label'>Time</span>
                                    <span class='info-value'>{$errorData['timestamp']}</span>
                                </div>
                                <div class='info-item'>
                                    <span class='info-label'>URL</span>
                                    <span class='info-value'>{$errorData['request_uri']}</span>
                                </div>
                                <div class='info-item'>
                                    <span class='info-label'>IP</span>
                                    <span class='info-value'>{$errorData['ip']}</span>
                                </div>
                            </div>
                        </div>
                        
                        " . (!empty($fileCode) ? "
                        <div class='code-section'>
                            <h2 class='info-title'>Source code</h2>
                            <div class='code-content'>
                                {$codeHtml}
                            </div>
                        </div>
                        " : "") . "
                        
                        <div class='trace-section'>
                            <h2 class='info-title'>Stack Trace</h2>
                            <div class='trace-content'>
                                {$stackTraceHtml}
                            </div>
                        </div>
                    </div>
                </div>
            </body>
            </html>";
        }
    }
}

if(!defined("CACHE")) {
    define("CACHE", 1);
}

$container = Container::getInstance();
$container->setSingleton(Autoload::class);
$container->setSingleton(Router::class);
$container->setSingleton(Page::class);
$container->setSingleton(Layout::class);
$container->setSingleton(Database::class);
$container->setSingleton(Request::class);
$container->setSingleton(Response::class);
$container->setSingleton(AuthentificatorProvider::class);

if(defined("USE_USERS")) {
    $container->setSingleton(UserService::class);
}

if(defined("USE_EXCEPTION_HANDLER")) {
    $container->setSingleton(GlobalErrorHandler::class);
    $container->get(GlobalErrorHandler::class)->register();
}

$container->get(Autoload::class)->register();