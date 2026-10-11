<?php
if(!defined("USE_USERS")) define("USE_USERS", true);
if(!defined("USE_SESSIONS_DB")) define("USE_SESSIONS_DB", true);
if(!defined("USE_EXCEPTION_HANDLER")) define("USE_EXCEPTION_HANDLER", true);
if(!defined("DEBUG")) define("DEBUG", false);

if(DEBUG !== true) {
    error_reporting(E_ERROR | E_PARSE);
    ini_set('zend.exception_ignore_args', '1');
}

define("ROOT", str_replace("\\", "/", getcwd()));

Request::sessionStart(); // Start session

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
    private bool $needSave = false;

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

    private function saveCache(bool $force = false): void {
        if(!$force) {
            if(!$this->needSave)
                return;
            $this->needSave = false;
        }        
        $currentCache = [];
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $currentCache = json_decode($content, true) ?? [];
        }

        $mergedClassMap = array_merge($currentCache['classMap'] ?? [], $this->classMap);
        $mergedLibraryPaths = array_merge($currentCache['libraryPaths'] ?? [], $this->libraryPaths);

        $mergedCache = [
            'classMap' => $mergedClassMap,
            'libraryPaths' => $mergedLibraryPaths
        ];

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
        register_shutdown_function([$this, 'saveCache']);
    }

    /**
     * Registers a library with its own root namespace and path
     * @param string $name Library name (e.g. "PhpSpreadsheet")
     * @param string $namespace Root namespace of the library (e.g. "PhpOffice")
     * @param string $path Path to the library (e.g. "libs/PhpSpreadsheet")
     */
    public function addLib(string $name, string $namespace = '', string $path = ''): void {
        if (empty($namespace)) {
            $namespace = $name;
        }
        
        if (empty($path)) {
            $path = 'libs/' . $name;
        }

        $namespace = trim($namespace, '\\');
        $path = rtrim($path, '/\\');
        
        $this->libraryPaths[$namespace] = $path;
        $this->needSave = true;
    }

    /**
     * Finds the file for the given class based on the registered libraries
     */
    private function findFileForClass(string $class): ?string {
        foreach ($this->libraryPaths as $namespace => $basePath) {
            if (strpos($class, $namespace . '\\') === 0) {
                $relativePath = substr($class, strlen($namespace) + 1);
                
                $filePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath) . '.php';
                $fullPath = $basePath . DIRECTORY_SEPARATOR . $filePath;
                
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            }
        }
        
        return null;
    }

    public function loadClass(string $class): void {
        if (isset($this->classMap[$class])) {
            $file = $this->classMap[$class];
            if (!in_array($file, $this->loadedFiles, true)) {
                if (file_exists($file)) {
                    require_once $file;
                    $this->loadedFiles[] = $file;
                    return;
                } else {
                    unset($this->classMap[$class]);
                    $this->needSave = true;
                }
            }            
        }

        $file = $this->findFileForClass($class);
        
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

        if ($hasNewClasses) {
            $this->needSave = true;
        }

        if (!in_array($class, $newClasses, true)
            && !class_exists($class, false)
            && !interface_exists($class, false)
            && !trait_exists($class, false)) {
            throw new Exception("Autoloader: Class '$class' could not be loaded from file '$file'.");
        }
    }

    public function addClass(string $class, string $file): void {
        $this->classMap[$class] = $file;
        $this->needSave = true;
    }

    public function cleanCache(): void {
        $cleaned = false;
        
        foreach ($this->classMap as $class => $file) {
            if (!file_exists($file)) {
                unset($this->classMap[$class]);
                $cleaned = true;
            }
        }
        
        if ($cleaned) {
            $this->saveCache(true);
        }
    }

    public function getClassMap(): array {
        return $this->classMap;
    }

    public function getLibraryPaths(): array {
        return $this->libraryPaths;
    }

    /**
     * Removes a library from the registered paths
     */
    public function removeLib(string $namespace): void {
        unset($this->libraryPaths[$namespace]);
        $this->needSave = true;
    }

    /**
     * Debug helper: shows where the autoloader looks for the given class
     */
    public function debugClass(string $class): array {
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
     * Possible formats:
     *  @method("arg1", "arg2")   -> contains arguments in parentheses (group 2)
     *  @primaryKey               -> without arguments
     *  @get index                -> argument without parentheses (group 3)
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

if (defined("DEBUG")) {
    class DebugTimer {
        private static array $records = [];
        // Stack of currently active timer names (for nesting detection)
        private static array $activeStack = [];

        public static function start(string $name): void {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $backtrace[0] ?? [];
            $file = basename($caller['file'] ?? 'unknown');
            $line = $caller['line'] ?? 0;

            // Determine parent from the current active stack
            $parent = !empty(self::$activeStack) ? end(self::$activeStack) : null;

            self::$records[$name][] = [
                'start'    => microtime(true),
                'stop'     => null,
                'file'     => $file,
                'line'     => $line,
                'parent'   => $parent,
                'children' => [],
            ];

            // Register this timer as a child of its parent's latest entry
            if ($parent !== null && isset(self::$records[$parent])) {
                $parentIdx = count(self::$records[$parent]) - 1;
                self::$records[$parent][$parentIdx]['children'][] = $name;
            }

            self::$activeStack[] = $name;
        }

        public static function stop(string $name): float {
            if (empty(self::$records[$name])) {
                throw new \Exception("Timer '$name' was not started.");
            }

            // Pop name from the active stack (handle out-of-order stops gracefully)
            $pos = array_search($name, self::$activeStack, true);
            if ($pos !== false) {
                array_splice(self::$activeStack, $pos, 1);
            }

            for ($i = count(self::$records[$name]) - 1; $i >= 0; $i--) {
                if (self::$records[$name][$i]['stop'] === null) {
                    self::$records[$name][$i]['stop'] = microtime(true);
                    return self::$records[$name][$i]['stop'] - self::$records[$name][$i]['start'];
                }
            }

            throw new \Exception("No running timer found for '$name'.");
        }

        // Return elapsed ms for a running timer without stopping it
        public static function peek(string $name): ?float {
            if (empty(self::$records[$name])) return null;
            foreach (array_reverse(self::$records[$name]) as $entry) {
                if ($entry['stop'] === null) {
                    return (microtime(true) - $entry['start']) * 1000;
                }
            }
            return null;
        }

        public static function dump(int $precision = 4): void {
            // Collect root timers (no parent) preserving insertion order
            $roots = [];
            $seen  = [];
            foreach (self::$records as $name => $entries) {
                foreach ($entries as $entry) {
                    if ($entry['parent'] === null && !in_array($name, $seen, true)) {
                        $roots[] = $name;
                        $seen[]  = $name;
                    }
                }
            }

            $totalMs = 0.0;
            foreach ($roots as $name) {
                foreach (self::$records[$name] as $entry) {
                    if ($entry['stop'] !== null) {
                        $totalMs += ($entry['stop'] - $entry['start']) * 1000;
                    }
                }
            }

            echo '<div style="font-family:monospace;font-size:13px;background:#1a1a1a;color:#e0e0e0;'
               . 'padding:16px 20px;border-radius:8px;line-height:1.7;max-width:900px;margin:10px 0">';
            echo '<div style="font-size:15px;font-weight:bold;margin-bottom:12px;color:#f0c040">'
               . '⏱ Debug Timers &nbsp;<span style="font-size:12px;font-weight:normal;color:#888">'
               . 'total root time: ' . round($totalMs, $precision) . ' ms</span></div>';

            foreach ($roots as $name) {
                self::renderTimer($name, 0, $precision);
            }

            echo '</div>';
        }

        private static function renderTimer(string $name, int $depth, int $precision): void {
            if (!isset(self::$records[$name])) return;

            $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth);
            $isRoot = $depth === 0;

            foreach (self::$records[$name] as $runIdx => $entry) {
                $durationMs = $entry['stop']
                    ? round(($entry['stop'] - $entry['start']) * 1000, $precision)
                    : null;

                $durationStr = $durationMs !== null
                    ? self::coloredDuration($durationMs)
                    : '<span style="color:#f0a040">(still running)</span>';

                $startStr = date('H:i:s', (int)$entry['start'])
                          . substr(number_format(fmod($entry['start'], 1), 3), 1); // .milliseconds

                $label = $isRoot
                    ? "<span style=\"color:#7ec8f5;font-weight:bold\">$name</span>"
                    : "<span style=\"color:#b0d8a4\">$name</span>";

                $run = count(self::$records[$name]) > 1
                    ? "<span style=\"color:#666\"> #" . ($runIdx + 1) . "</span>"
                    : '';

                $meta = "<span style=\"color:#555\">{$entry['file']}:{$entry['line']}</span>";

                echo "$indent$label$run &nbsp;$durationStr"
                   . " &nbsp;<span style=\"color:#444\">@ $startStr &nbsp;$meta</span><br>";

                // Recursively render children
                foreach ($entry['children'] as $childName) {
                    self::renderTimer($childName, $depth + 1, $precision);
                }
            }
        }

        private static function coloredDuration(float $ms): string {
            if ($ms < 5)   return "<span style=\"color:#5dca82\">{$ms} ms</span>";
            if ($ms < 50)  return "<span style=\"color:#c8e04a\">{$ms} ms</span>";
            if ($ms < 200) return "<span style=\"color:#f0a040\">{$ms} ms</span>";
            return             "<span style=\"color:#e05050;font-weight:bold\">{$ms} ms</span>";
        }

        public static function reset(): void {
            self::$records     = [];
            self::$activeStack = [];
        }
    }
} else {
    define("START_TIMER", microtime(true));

    class DebugTimer {
        public static function start(string $name): void {}
        public static function stop(string $name): float { return 0.0; }
        public static function peek(string $name): ?float { return null; }
        
        public static function dump(int $precision = 4): void {
            $totalTime = microtime(true) - START_TIMER;
            $formattedTime = number_format($totalTime, $precision) * 1000;
            
            echo '<div style="font-family:monospace;font-size:13px;background:#1a1a1a;color:#e0e0e0;'
               . 'padding:16px 20px;border-radius:8px;line-height:1.7;max-width:900px;margin:10px 0">';
            echo '<div style="font-size:15px;font-weight:bold;color:#f0c040">'
               . '⏱ Total time wihout debug &nbsp;<span style="font-size:12px;font-weight:normal;color:#888">'
               . 'total root time: ' . $formattedTime . ' ms</span></div>';
            echo "</div>";
        }
        
        public static function reset(): void {}
    }
}

DebugTimer::start("library");

class Config {
    private array $data = [];
    private bool $loaded = false;

    // Priority: 1) argument, 2) DEFINE, 3) $_ENV/getenv, 4) .key file (just when debug)
    private function resolveKey(): ?string {
        if (defined('APP_KEY')) {
            return APP_KEY;
        }

        $envKey = getenv('APP_KEY') ?: ($_ENV['APP_KEY'] ?? null);
        if ($envKey) {
            return $envKey;
        }

        if (defined('DEBUG') && DEBUG === true) {
            $keyFile = ROOT . '/.key';
            if (file_exists($keyFile)) {
                return trim(file_get_contents($keyFile));
            }
        }

        return null;
    }

    public function load(?string $path = null, ?string $key = null): void {
        if ($this->loaded) return;

        DebugTimer::start("config.load");

        if ($path === null) {
            $path = ROOT . '/.env';
            if (defined('DEBUG') && DEBUG === true && file_exists(ROOT . '/.env.dev')) {
                $path = ROOT . '/.env.dev';
            }
        }

        $encryptedPath = $path . '.encrypted';
        $useEncrypted = file_exists($encryptedPath);

        if ($useEncrypted) {
            $resolvedKey = $key ?? $this->resolveKey();
            if ($resolvedKey === null) {
                throw new \RuntimeException(
                    "Encrypted .env found but no decryption key available. " .
                    "Set APP_KEY via define(), environment variable, or .key file (debug only)."
                );
            }
            $lines = $this->decryptEnv($encryptedPath, $resolvedKey);
        } elseif (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } else {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (!str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $v = trim(trim($v), '"\'');
            $k = trim($k);

            $this->data[$k] = $v;
            $_ENV[$k] = $v;
            if(function_exists("putenv")) {
                @putenv("$k=$v");
            }
        }
        $this->loaded = true;

        DebugTimer::stop("config.load");
    }

    /** 
     * Encrypt the .env file
     */
    public function encrypt(?string $path = null, ?string $key = null): void {
        $path = $path ?? ROOT . '/.env';
        if (defined('DEBUG') && DEBUG === true && file_exists(ROOT . '/.env.dev')) {
            $path = ROOT . '/.env.dev';
        }

        if (!file_exists($path)) {
            throw new \RuntimeException("Source file not found: $path");
        }

        $resolvedKey = $key ?? $this->resolveKey();
        if ($resolvedKey === null) {
            throw new \RuntimeException("No encryption key available.");
        }

        $plaintext = file_get_contents($path);
        $encryptedPath = $path . '.encrypted';

        // Derive 256-bit key from whatever string was passed
        $binaryKey = $this->deriveKey($resolvedKey);

        $iv = random_bytes(16); // AES-256-CBC IV
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $binaryKey, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $iv . $ciphertext, $binaryKey, true);

        // Format: hmac(32) + iv(16) + ciphertext
        file_put_contents($encryptedPath, base64_encode($hmac . $iv . $ciphertext));
    }

    private function decryptEnv(string $encryptedPath, string $key): array {
        $binaryKey = $this->deriveKey($key);
        $raw = base64_decode(file_get_contents($encryptedPath));

        if (strlen($raw) < 48) { // 32 hmac + 16 iv minimum
            throw new \RuntimeException("Invalid encrypted env file.");
        }

        $hmac       = substr($raw, 0, 32);
        $iv         = substr($raw, 32, 16);
        $ciphertext = substr($raw, 48);

        // Check integrity (timing-safe)
        $expectedHmac = hash_hmac('sha256', $iv . $ciphertext, $binaryKey, true);
        if (!hash_equals($expectedHmac, $hmac)) {
            throw new \RuntimeException("Decryption failed: invalid key or corrupted file.");
        }

        $plaintext = openssl_decrypt($ciphertext, 'AES-256-CBC', $binaryKey, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \RuntimeException("Decryption failed.");
        }

        return explode("\n", $plaintext);
    }

    private function deriveKey(string $key): string {
        // HKDF-like derivation – from any string make 32 bytes
        return hash_hkdf('sha256', $key, 32, 'config-encryption');
    }

    public function get(string $key, mixed $default = null): mixed {
        return $this->data[$key] ?? $default;
    }
    /**
     * Reads a boolean config value. Accepts true/false, 1/0, yes/no, on/off (case-insensitive).
     * Note: boolval("false") is true in PHP, which is why this helper exists.
     */
    public function getBool(string $key, bool $default = false): bool {
        if (!isset($this->data[$key]) || trim($this->data[$key]) === '') {
            return $default;
        }
        return self::parseBool($this->data[$key], $default);
    }

    public static function parseBool(mixed $value, bool $default = false): bool {
        if (is_bool($value)) return $value;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }

    public function has(string $key): bool {
        return isset($this->data[$key]);
    }

    public function all(): array {
        return $this->data;
    }

    /**
     * Sets a value at runtime (useful for tests and for code that wants to override a setting).
     */
    public function set(string $key, string $value): void {
        $this->data[$key] = $value;
    }
}

enum ConfigValidatorType {
    case Number;
    case PositiveNumber;
    case NonNegativeNumber;
    case Boolean;
    case Email;
    case Url;
    case NonEmpty;
    case Ip;
    case Port;
    case DateOffset;
    case PositiveDateOffset;
}

class ConfigValidator {
    public function __construct(
        public readonly Closure $validate,
        public readonly ?string $hint = null
    ) {}
}

class ConfigBasicValidator {
    public static function create(ConfigValidatorType $type): ConfigValidator {
        return match($type) {
            ConfigValidatorType::Number => new ConfigValidator(
                fn(string $v) => is_numeric($v),
                'a numeric value (e.g. 42, 3.14)'
            ),

            ConfigValidatorType::PositiveNumber => new ConfigValidator(
                fn(string $v) => is_numeric($v) && (float)$v > 0,
                'a positive number (e.g. 1, 3.14)'
            ),

            ConfigValidatorType::NonNegativeNumber => new ConfigValidator(
                fn(string $v) => is_numeric($v) && (float)$v >= 0,
                'a non-negative number (e.g. 0, 5)'
            ),

            ConfigValidatorType::Boolean => new ConfigValidator(
                fn(string $v) => in_array(strtolower($v), ['true', 'false', '1', '0', 'yes', 'no'], true),
                'a boolean (true/false, 1/0, yes/no)'
            ),

            ConfigValidatorType::Email => new ConfigValidator(
                fn(string $v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
                'a valid email address (e.g. user@example.com)'
            ),

            ConfigValidatorType::Url => new ConfigValidator(
                fn(string $v) => filter_var($v, FILTER_VALIDATE_URL) !== false,
                'a valid URL (e.g. https://example.com)'
            ),

            ConfigValidatorType::NonEmpty => new ConfigValidator(
                fn(string $v) => trim($v) !== '',
                'a non-empty string'
            ),

            ConfigValidatorType::Ip => new ConfigValidator(
                fn(string $v) => filter_var($v, FILTER_VALIDATE_IP) !== false,
                'a valid IP address (e.g. 127.0.0.1)'
            ),

            ConfigValidatorType::Port => new ConfigValidator(
                fn(string $v) => is_numeric($v) && (int)$v >= 1 && (int)$v <= 65535,
                'a port number between 1 and 65535'
            ),

            ConfigValidatorType::DateOffset => new ConfigValidator(
                fn(string $v) => (bool)preg_match(
                    '/^[+-]?\d+\s*(second|minute|hour|day|week|month|year)s?$/i',
                    trim($v)
                ),
                'a date offset (e.g. +1 day, -2 weeks, 3 months)'
            ),

            ConfigValidatorType::PositiveDateOffset => new ConfigValidator(
                fn(string $v) => (bool)preg_match(
                    '/^\+?\d+\s*(second|minute|hour|day|week|month|year)s?$/i',
                    trim($v)
                ),
                'a positive date offset (e.g. +1 day, 2 weeks)'
            ),
        };
    }

    public static function enum(string ...$allowed): ConfigValidator {
        return new ConfigValidator(
            fn(string $v) => in_array($v, $allowed, true),
            'one of: (' . implode(', ', $allowed).')'
        );
    }
}

class ConfigType {
    public string $name;
    public bool $required;
    public bool $secret;
    private ?ConfigValidator $validator;

    /**
     * @param string $name The name of the configuration key.
     * @param bool $required Whether this configuration key is required.
     * @param ConfigValidator|null $validator An optional \ConfigValidator that validates the value of the configuration key.
     */
    public function __construct(string $name, bool $required = true, bool $secret = false, ?ConfigValidator $validator = null) {
        $this->name = $name;
        $this->required = $required;
        $this->secret = $secret;
        $this->validator = $validator;
    }

    public function validate(string $value): bool {
        if ($this->validator) {
            return ($this->validator->validate)($value);
        }
        return true;
    }

    public function hint(): ?string {
        return $this->validator?->hint;
    }
}

class ConfigProvider {
    private Config $config;
    private array $providers = [];

    public function __construct(Config $config) {
        $this->config = $config;
    }

    /**
     * Registers a handler that will run if all required keys are available.
     * 
     * @param array<string|ConfigType>    $requiredKeys  Keys that must exist in the config
     * @param callable $handler       Callback that will receive the values as an array
     */
    public function register(array $requiredKeys, callable $handler): void {
        $this->providers[] = [
            'keys'    => $requiredKeys,
            'handler' => $handler,
        ];
    }

    /**
     * It will run all handlers whose keys are available.
     * It is called once after loading the config.
     */
    public function boot(): void {
        DebugTimer::start("config.provider.boot");

        foreach ($this->providers as $provider) {
            $values = [];
            $allPresent = true;
            
            foreach ($provider['keys'] as $key) {
                if ($key instanceof ConfigType) {
                    $keyName = $key->name;
                    if (!$this->config->has($keyName)) {
                        if ($key->required) {
                            $allPresent = false;
                            break;
                        } else {
                            continue;
                        }
                    }

                    $value = $this->config->get($keyName);
                    if (!$key->validate($value)) {
                        $valueShow = $key->secret? "******": $value;
                        $hint = $key->hint() ? " Expected: {$key->hint()}." : '';
                        throw new Exception("Validation failed for config key '$keyName' with value '$valueShow'.$hint");
                    }
                    
                    $values[$keyName] = $value;
                } else {
                     if (!$this->config->has($key)) {
                        $allPresent = false;
                        break;
                    }
                    $values[$key] = $this->config->get($key);
                }
            }
            if ($allPresent) {
                ($provider['handler'])($values);
            }
        }

        DebugTimer::stop("config.provider.boot");
    }
}

class Utilities {
    /**
     * @var SplObjectStorage<object, null>|null Tracks objects already visited
     * during the current top-level dump, to guard against infinite recursion
     * on circular references.
     */
    private static ?SplObjectStorage $seen = null;
 
    /** @var bool Whether the <style> block has already been printed during this request */
    private static bool $debugStylesPrinted = false; 
    private const DEBUG_STYLES = '<style>.var-dump{--vd-bg:#10121a;--vd-border:#262b3d;--vd-text:#d7dae5;--vd-muted:#6b7280;--vd-key:#8fb8ff;--vd-string:#f2b866;--vd-number:#6ee7c8;--vd-bool:#c792ea;background:var(--vd-bg);color:var(--vd-text);font-family:"JetBrains Mono","Fira Code",ui-monospace,SFMono-Regular,monospace;font-size:13px;line-height:1.6;padding:14px 16px;border-radius:6px;overflow-x:auto}.var-dump summary.type{cursor:pointer;list-style:none;color:var(--vd-muted);font-weight:600;padding:2px 0}.var-dump summary.type::-webkit-details-marker{display:none}.var-dump summary.type::before{content:"▸";display:inline-block;width:1em;color:var(--vd-muted);transition:transform .12s ease;position: relative;top: 1px;}.var-dump details[open]>summary.type::before{transform:rotate(90deg)}.var-dump summary.type-array{color:#7aa8f7}.var-dump summary.type-object{color:#7ee0c3}.var-dump .children{margin-left:.9em;padding-left:.9em;border-left:1px solid var(--vd-border)}.var-dump .prop{padding:1px 0}.var-dump .name{color:var(--vd-key)}.var-dump .typev{color:var(--vd-muted);font-style:italic;margin-right:2px}.var-dump .value.type-string{color:var(--vd-string)}.var-dump .value.type-integer,.var-dump .value.type-double{color:var(--vd-number)}.var-dump .value.type-boolean{color:var(--vd-bool)}.var-dump .string-len{color:var(--vd-muted);font-size:11px}</style>';
 
    public static function vardump(mixed $value, int $level = 0): void
    {
        // Only the outermost call resets the "seen objects" tracker and wraps the output.
        if ($level === 0) {
            self::$seen = new SplObjectStorage();
 
            // The style block is only printed once per request, even if vardump()
            // is called multiple times across the same PHP file.
            if (!self::$debugStylesPrinted) {
                echo self::DEBUG_STYLES;
                self::$debugStylesPrinted = true;
            }
 
            echo "<div class='var-dump'>";
        }
 
        if (is_array($value)) {
            self::dumpEntries($value, "array(" . count($value) . ")", "array", $level);
        } elseif (is_object($value)) {
            self::dumpObject($value, $level);
        } elseif ($value !== null) {
            echo "<div class=prop>";
            self::dumpScalar($value);
            echo "</div>";
        } else {
            echo "<div class=prop><span class=typev>NULL</span></div>";
        }
 
        if ($level === 0) {
            echo "</div>";
        }
    }
 
    private static function dumpObject(object $object, int $level): void
    {
        $class = get_class($object);
 
        if (self::$seen->offsetExists($object)) {
            echo "<div class=type>object($class) *RECURSION*</div>";
            return;
        }
        self::$seen->offsetSet($object, null);
 
        $props = get_object_vars($object);
        self::dumpEntries($props, "object($class)(" . count($props) . ")", "object", $level);
    }
 
    // $kind is "array" or "object", used as a CSS class so the two can be colored differently
    private static function dumpEntries(array $entries, string $header, string $kind, int $level): void
    {
        echo "<details open class=node>";
        echo "<summary class='type type-$kind'>" . $header . "</summary>";
        echo "<div class=children>";
 
        foreach ($entries as $key => $item) {
            echo "<div class=prop><span class=name>";
            echo is_int($key) ? $key : "'" . htmlspecialchars((string) $key) . "'";
            echo "</span> => ";
 
            if ($item === null) {
                echo "<span class=typev>NULL</span>";
            } elseif (is_array($item) || is_object($item)) {
                self::vardump($item, $level + 1);
            } else {
                self::dumpScalar($item);
            }
 
            echo "</div>";
        }
 
        echo "</div>";
        echo "</details>";
    }
 
    private static function dumpScalar(mixed $value): void
    {
        $type = gettype($value);
        echo "<span class=typev>" . $type . "</span> ";
        echo "<span class='value type-" . $type . "'>";
 
        if ($type === "string") {
            echo "'" . htmlspecialchars($value) . "'";
        } elseif ($type === "boolean") {
            echo $value ? "true" : "false";
        } else {
            echo $value;
        }
 
        echo "</span>";
 
        if ($type === "string") {
            echo " <span class=string-len>(length=" . strlen($value) . ")</span>";
        }
    }

    public static function random(int $length): string {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $randomizer = new \Random\Randomizer(new \Random\Engine\Secure());
        return $randomizer->getBytesFromString($characters, $length);
    }

    /**
     * Returns the IP addresses of reverse proxies whose X-Forwarded-For header is trusted.
     * Configure with TRUSTED_PROXIES="10.0.0.1, 10.0.0.2" in .env; defaults to localhost only.
     */
    public static function trustedProxies(): array {
        $configured = Container::getInstance()->get(Config::class)->get('TRUSTED_PROXIES');
        if ($configured === null || trim($configured) === '') {
            return ['127.0.0.1', '::1'];
        }
        return array_values(array_filter(array_map('trim', explode(',', $configured)), 'strlen'));
    }

    /**
     * Returns a keyed fingerprint of the client network (not of the exact address).
     * IPv4 is truncated to /24, IPv6 to /48, then HMAC-ed with the server secret.
     * Good enough to detect "the user moved to a different network", useless for reverse lookup without the key.
     */
    public static function ipFingerprint(string $ip): string {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return '';
        }

        $bytes = strlen($bin) === 4 ? 3 : 6; // /24 for IPv4, /48 for IPv6
        $network = substr($bin, 0, $bytes);

        return hash_hmac('sha256', $network, Cookies::secret() . '|ip');
    }

    public static function ip(bool $pure = false): string {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip = $remote;

        $trusted = self::trustedProxies();

        if (self::ipInList($remote, $trusted)) {
            $header = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_FORWARDED_FOR'] ?? '';

            if ($header !== '') {
                $chain = array_map('trim', explode(',', $header));

                // Walk from the right, skip trusted proxies, the first untrusted hop is the client
                for ($i = count($chain) - 1; $i >= 0; $i--) {
                    $candidate = $chain[$i];

                    // Garbage in the chain: do not trust anything further left
                    if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                        break;
                    }

                    $ip = $candidate;
                    if (!self::ipInList($candidate, $trusted)) {
                        break;
                    }
                }
            }
        }

        if ($pure) {
            return (string)$ip;
        }

        if ($ip === '::1') {
            $ip = '127.0.0.1';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }

        return $ip;
    }

    private static function ipInList(string $ip, array $list): bool {
        foreach ($list as $entry) {
            if (str_contains($entry, '/')) {
                if (self::ipInCidr($ip, $entry)) {
                    return true;
                }
            } elseif (inet_pton($ip) !== false && inet_pton($ip) === inet_pton($entry)) {
                return true; // inet_pton normalizes IPv6 notation differences
            }
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
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

    private const TRANSLITERATION = [
        'á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd', 'đ' => 'd',
        'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'è' => 'e', 'ê' => 'e', 'ę' => 'e',
        'í' => 'i', 'ï' => 'i', 'ì' => 'i', 'î' => 'i', 'ł' => 'l', 'ľ' => 'l', 'ĺ' => 'l',
        'ň' => 'n', 'ń' => 'n', 'ñ' => 'n',
        'ó' => 'o', 'ö' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ř' => 'r', 'ŕ' => 'r', 'š' => 's', 'ś' => 's', 'ş' => 's', 'ß' => 'ss', 'ť' => 't', 'ţ' => 't',
        'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z',
    ];

    /**
     * URL-friendly version of a string: "Příliš žluťoučký kůň" -> "prilis-zlutoucky-kun".
     */
    public static function slugify(string $text, int $maxLength = 200): string {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, self::TRANSLITERATION);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        if ($maxLength > 0 && strlen($text) > $maxLength) {
            $text = rtrim(substr($text, 0, $maxLength), '-');
        }
        return $text;
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

    public static function month(int $val): string {
		return date("F", mktime(0, 0, 0, $val, 1, 2024));
	}
	
	public static function day(int $val){
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
     * @param string $date date in format j.n.Y H:i or specify by $format
     * @param string $format format of the date
     */
    public static function toTimestamp(string $date, string $format = 'j.n.Y H:i'): int {
        $dt = DateTime::createFromFormat($format, $date);
        if (!$dt) {
            throw new Exception("Invalid date format: $date");
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
        $port = isset($parsedUrl['port']) ? ":{$parsedUrl['port']}" : "";
        $resultUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}{$port}{$parsedUrl['path']}?$queryString";

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
        $port = isset($parsedUrl['port']) ? ":{$parsedUrl['port']}" : "";
        $resultUrl = "{$parsedUrl['scheme']}://{$parsedUrl['host']}{$port}{$parsedUrl['path']}?$queryString";

        return $resultUrl;
    }
}

enum Lifetime {
    case Singleton;
    case NewInstance;
    case LazyLoading;
}

class Container {
    private static ?Container $instance = null;

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

/**
 * Indicates that the route can be accessed without authentication.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class AllowAnonymous {}

/**
 * Define the route path and HTTP method for a controller action. You can also specify a priority and an optional name for the route.
 * The route name is useful for generating URLs or referencing the route in other parts of the application.
 * If you define two same route path with different methods, the router will handle them correctly based on the HTTP method of the request.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Route {
    public function __construct(
        public string $path,
        public Method $method = Method::UNKNOWN,
        public int $priority = 0,
        public ?string $name = null
    ) {}
}

/**
 * Indicates that the route requires a specific HTTP method (GET, POST, etc.) to be accessed.
 * You should use it when you dont need to define the route only required method for the route.
 * If the request method does not match, a 405 Method Not Allowed response will be returned
 * 
 * @param Method $method The required HTTP method for the route.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class RequireMethod {
    public function __construct(
        public Method $method,
    ) {}
}

/**
 * Marks a controller (or a single action) as exempt from CSRF validation, e.g. for JSON APIs called by other clients.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SkipCsrf {}

/**
 * Requires the signed-in user to have at least the given permission level (Models\Permission::$level).
 * A method-level attribute overrides the controller-level one. Anonymous visitors are sent to the login page,
 * signed-in users without the level get a 403.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Authorize {
    public function __construct(public int $level = 1) {}
}

/**
 * Small, dependency-free input validation for controllers:
 *
 *   $v = Validator::make($_POST, [
 *       'title'   => 'required|max:200',
 *       'email'   => 'required|email',
 *       'password'=> 'required|min:8|same:confirmPassword',
 *       'status'  => 'in:draft,published',
 *       'slug'    => ['slug', fn($value) => Article::slugTaken($value) ? "This slug is already used" : true],
 *   ]);
 *   if ($v->fails()) return $this->view("edit", ["errors" => $v->errors()]);
 *   $clean = $v->validated();
 *
 * Rules: required, email, url, min:N, max:N (string length), integer, numeric, boolean, in:a,b,c, same:otherField,
 * slug (letters, digits, dashes), alpha_dash. A closure rule returns true or an error message.
 * Empty optional fields skip the remaining rules.
 */
class Validator {
    /** @var array<string,string> field => first error message */
    private array $errors = [];
    private array $validated = [];

    private function __construct(private array $data, private array $rules) {}

    public static function make(array $data, array $rules): self {
        $validator = new self($data, $rules);
        $validator->run();
        return $validator;
    }

    public function passes(): bool {
        return $this->errors === [];
    }

    public function fails(): bool {
        return !$this->passes();
    }

    /** @return array<string,string> */
    public function errors(): array {
        return $this->errors;
    }

    public function error(string $field): ?string {
        return $this->errors[$field] ?? null;
    }

    public function firstError(): ?string {
        return $this->errors === [] ? null : reset($this->errors);
    }

    /**
     * Only the fields that have rules, with strings trimmed. Missing fields are returned as null.
     */
    public function validated(): array {
        return $this->validated;
    }

    public static function label(string $field): string {
        $words = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);
        $words = str_replace(['_', '-'], ' ', $words);
        return ucfirst(strtolower(trim($words)));
    }

    private function run(): void {
        foreach ($this->rules as $field => $rules) {
            if (is_string($rules)) {
                $rules = $rules === '' ? [] : explode('|', $rules);
            }

            $value = $this->data[$field] ?? null;
            if (is_string($value)) $value = trim($value);
            $this->validated[$field] = $value;

            $empty = $value === null || $value === '' || $value === [];
            $required = in_array('required', $rules, true);

            if ($empty) {
                if ($required) {
                    $this->errors[$field] = self::label($field) . " is required";
                }
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'required') continue;
                $message = $this->check($field, $value, $rule);
                if ($message !== true) {
                    $this->errors[$field] = $message;
                    break;
                }
            }
        }
    }

    private function check(string $field, mixed $value, mixed $rule): true|string {
        $label = self::label($field);

        if ($rule instanceof Closure) {
            $result = $rule($value, $this->data);
            if ($result === true || $result === null) return true;
            return is_string($result) && $result !== '' ? $result : "$label is invalid";
        }

        [$name, $argument] = array_pad(explode(':', (string)$rule, 2), 2, null);
        $string = is_scalar($value) ? (string)$value : '';

        return match ($name) {
            'email' => filter_var($string, FILTER_VALIDATE_EMAIL) !== false ?: "$label must be a valid email address",
            'url' => filter_var($string, FILTER_VALIDATE_URL) !== false ?: "$label must be a valid URL",
            'min' => mb_strlen($string) >= (int)$argument ?: "$label must be at least $argument characters",
            'max' => mb_strlen($string) <= (int)$argument ?: "$label must be at most $argument characters",
            'integer' => preg_match('/^-?\d+$/', $string) === 1 ?: "$label must be a whole number",
            'numeric' => is_numeric($string) ?: "$label must be a number",
            'boolean' => Config::parseBool($string, false) === Config::parseBool($string, true) ?: "$label must be yes or no",
            'in' => in_array($string, array_map('trim', explode(',', (string)$argument)), true) ?: "$label must be one of: " . str_replace(',', ', ', (string)$argument),
            'same' => $string === trim((string)($this->data[$argument] ?? '')) ?: "$label must match " . strtolower(self::label((string)$argument)),
            'slug' => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $string) === 1 ?: "$label may only contain lowercase letters, digits and dashes",
            'alpha_dash' => preg_match('/^[A-Za-z0-9_-]+$/', $string) === 1 ?: "$label may only contain letters, digits, dashes and underscores",
            default => throw new InvalidArgumentException("Unknown validation rule '$name'"),
        };
    }
}

/**
 * An exception that maps directly onto an HTTP status code. The router turns it into a plain response
 * (JSON for JSON requests) instead of the 500 error page.
 */
class HttpException extends \Exception {
    private int $status;

    public function __construct(int $status, string $message = "") {
        parent::__construct($message !== "" ? $message : self::reasonPhrase($status), $status);
        $this->status = $status;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public static function reasonPhrase(int $status): string {
        return match ($status) {
            400 => "Bad Request",
            401 => "Unauthorized",
            403 => "Forbidden",
            404 => "Not Found",
            405 => "Method Not Allowed",
            409 => "Conflict",
            410 => "Gone",
            422 => "Unprocessable Content",
            429 => "Too Many Requests",
            500 => "Internal Server Error",
            503 => "Service Unavailable",
            default => "HTTP " . $status,
        };
    }
}

class ModelNotFoundException extends HttpException {
    public function __construct(string $class, mixed $id) {
        parent::__construct(404, "Model '{$class}' with ID '{$id}' not found");
    }
}

enum Method: string {
    case UNKNOWN = 'UNKNOWN';
    case GET = 'GET';
    case POST = 'POST';
    case PUT = 'PUT';
    case DELETE = 'DELETE';
    case HEAD = 'HEAD';
}

class Router {
    private array $routes = [];
    private ?string $matchedUrl = null;
    private ?array $routeData = null;
    private string $baseUrl;
    private string $cacheFile = 'routes.cache.json';
    private array $controllers = [];
    private array $procesed = [];
    private array $routesByName = [];

    private ?ControllerAction $controllerData = null;
    private Layout $layout;
    private Request $request;
    private AuthentificatorProvider $authenticationProvider;    

    public function __construct(Layout $layout, Request $request, AuthentificatorProvider $authenticationProvider) {
        $this->baseUrl = $this->generateBaseUrl();
        $this->layout = $layout;
        $this->request = $request;
        $this->authenticationProvider = $authenticationProvider;

        DebugTimer::start('router.controller.glob');
        $this->controllers = self::discoverControllers("controllers");
        DebugTimer::stop('router.controller.glob');

        $this->loadRoutes();
    }

    /**
     * Controller files, including sub-directories: controllers/admin/users.php holds Controllers\Admin\Users.
     */
    private static function discoverControllers(string $dir): array {
        $files = glob($dir . "/*.php") ?: [];
        foreach (glob($dir . "/*", GLOB_ONLYDIR) ?: [] as $subDir) {
            $files = array_merge($files, self::discoverControllers($subDir));
        }
        return $files;
    }

    /**
     * controllers/admin/users.php -> Controllers\admin\users (class names are case-insensitive in PHP).
     */
    private static function controllerClassFromFile(string $file): string {
        $relative = preg_replace('#^controllers[\\\\/]#', '', str_replace('\\', '/', $file));
        $relative = preg_replace('/\.php$/', '', $relative);
        return "Controllers\\" . str_replace('/', '\\', $relative);
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
        
        if (count($cache['hashes']) !== count($this->controllers)) {
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
            $method = isset($route['httpMethod']) ? Method::from($route['httpMethod']) : null;
            $this->add($route['route'], [$route['controller'], $route['method']], false, $method, $route['name'] ?? null);
        }
    }

    private function saveCache(array $routes) {
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

    private function buildNameIndex(): void {
        $this->routesByName = [];

        foreach ($this->routes as $route) {
            $name = $route['name'] ?? null;
            if ($name === null || !is_array($route['handler'])) {
                continue;
            }

            $this->routesByName[$name][] = $route;
        }
    }

    private function loadRoutes() {
        DebugTimer::start('router.controller.loading');

        if (!defined("DEBUG") && $this->isCacheValid()) {
            $this->loadFromCache();
        } else {
            $routes = $this->generateRoutes();
            foreach($routes as $route) {
                $this->add($route['route'], [$route['controller'], $route['method']], false, $route['httpMethod'] ?? null, $route['name'] ?? null);
            }
            $this->saveCache($routes);
        }

        $this->buildNameIndex();

        DebugTimer::stop('router.controller.loading');
    }

    private function generateRoutes(): array {
        $routes = [];

        foreach ($this->controllers as $controller) {
            $controllerClass = self::controllerClassFromFile($controller);

            if (class_exists($controllerClass)) {
                $reflection = new ReflectionClass($controllerClass);
                $controllerClass = $reflection->getName();

                if ($reflection->isSubclassOf(Controller::class)) {
                    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                    foreach ($methods as $method) {
                        $attributes = $method->getAttributes(Route::class);

                        foreach ($attributes as $attribute) {
                            $route = $attribute->newInstance();

                            $routes[] = [
                                'route' => $route->path,
                                'name' => $route->name,
                                'controller' => $controllerClass,
                                'method' => $method->getName(),
                                'priority' => $route->priority,
                                'httpMethod' => $route->method
                            ];
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

    /**
     * Path of the current request relative to the application root, without leading/trailing slashes
     * ("article/edit/5"). Uses the `url` query parameter when the web server rewrite provides it
     * (Apache .htaccess), otherwise REQUEST_URI minus the directory index.php lives in.
     */
    public static function currentPath(): string {
        if (isset($_GET['url'])) {
            $url = trim((string)$_GET['url'], '/');

            if (isset($_SERVER['SCRIPT_NAME'])) {
                $scriptDir = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
                if ($scriptDir !== '' && str_starts_with($url, $scriptDir)) {
                    $url = trim(substr($url, strlen($scriptDir)), '/');
                }
            }

            return $url;
        }

        $path = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2)[0];
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        if ($scriptDir !== '/' && $scriptDir !== '.' && str_starts_with($path, $scriptDir)) {
            $path = substr($path, strlen($scriptDir));
        }

        return trim(rawurldecode($path), '/');
    }

    /**
     * Absolute URL of the application root ("http://host/sub/app"), or with $full the URL of the current request.
     * Works with Apache rewrites (url= parameter), nginx and PHP's built-in server alike.
     */
    public static function url(bool $full = false, bool $_request = false): string {
        $scheme = Request::isHttps() ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = self::getPort();

        $requestParts = explode('?', $_SERVER['REQUEST_URI'] ?? '/', 2);
        $requestPath = rtrim($requestParts[0], '/') . '/';

        if ($full) {
            $query = $_request && isset($requestParts[1]) ? '?' . $requestParts[1] : '';
            return rtrim("{$scheme}://{$host}{$port}{$requestPath}", '/') . $query;
        }

        // Base = request path minus the part the router handles.
        $routed = self::currentPath();
        $base = trim(rawurldecode($requestPath), '/');
        if ($routed !== '' && str_ends_with($base, $routed)) {
            $base = trim(substr($base, 0, strlen($base) - strlen($routed)), '/');
        }

        return rtrim("{$scheme}://{$host}{$port}/" . ($base !== '' ? $base . '/' : ''), '/');
    }

    public static function getPort(): string {
        $port = $_SERVER['SERVER_PORT'] ?? null;
        if ($port === null || $port == 80 || $port == 443) {
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

    public function add(string $path, string|callable|array $handler, bool $redirect = false, ?Method $method = null, ?string $name = null): void {
        $path = $this->combineOptionalSegments($path);
        $pattern = $this->buildRegexPattern($path);
        
        $route = [
            'pattern' => $path,
            'regex' => $pattern,
            'handler' => $handler,
            'redirect' => $redirect,
            'status' => null,
            'variables' => null,
            'module' => null,
            'method' => $method,
            'name' => $name,
        ];
        $this->routes[] = $route;
        
        if ($name !== null && is_array($handler)) {
            $this->routesByName[$name][] = $route;
        }
    }

    public function addControllerActionFallback(string $pattern = '<controller>/<action=index>'): void {
        $this->add($pattern, function (array $variables) {
            $controllerName = $variables['controller'] ?? null;
            $action = $variables['action'] ?? 'index';

            if ($controllerName === null) {
                http_response_code(404);
                echo "Not Found";
                exit();
            }

            $this->processVariables($variables);

            try {
                $this->callController([$controllerName, $action], $variables, null, true);
            } catch (HttpException $e) {
                $this->sendHttpError($e);
            }
        });
    }
    /** @var null|callable(HttpException): void */
    private $httpErrorHandler = null;

    /**
     * Replaces the default plain-text error response with your own (e.g. a themed 404 page).
     * The handler receives the HttpException and must send the complete response and exit (or throw);
     * if it simply returns, request processing continues as if no route had matched.
     */
    public function setHttpErrorHandler(?callable $handler): void {
        $this->httpErrorHandler = $handler;
    }

    /**
     * Turns an HttpException into a minimal response: status code plus a plain-text (or JSON) body.
     */
    public function sendHttpError(HttpException $e): void {
        $this->procesed[] = "HTTP " . $e->getStatus() . ": " . $e->getMessage();

        if ($this->httpErrorHandler !== null) {
            ($this->httpErrorHandler)($e);
            return;
        }

        if (ob_get_level()) {
            ob_clean();
        }
        http_response_code($e->getStatus());

        if ($this->request->isJsonRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'code' => $e->getStatus(), 'message' => $e->getMessage()]);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo $e->getMessage();
        }
        exit();
    }

    public function redirect(string $path): void {
        $url = self::url() . '/' . ltrim($path, '/');
        ob_clean();
        header("Location: $url");
        exit();
    }

    public function start(): void {
        DebugTimer::start("router.start");

        $url = $this->getCurrentUrl();
        $this->matchedUrl = $url;

        $this->processQueryParameters();
        Csrf::ensureCookie();

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

                    try {
                        $this->callController($matchedRoute['handler'], $matchedRoute['variables'], $matchedRoute['method']);
                    } catch (HttpException $e) {
                        $this->sendHttpError($e);
                    }
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

        DebugTimer::stop("router.start");
    }

    /**
     * Absolute URL of a named route, e.g. Router::urlFor('article.edit', ['id' => 5]).
     * Available in templates as {route 'article.edit', ['id' => $id]}.
     */
    public static function urlFor(string $name, array $params = []): string {
        $path = Container::getInstance()->get(Router::class)->generateUrl($name, $params);
        return self::url() . '/' . $path;
    }

    public function generateUrl(string $name, array $params = []): string {
        if (!isset($this->routesByName[$name])) {
            throw new Exception("Route with name '$name' not found");
        }

        foreach ($this->routesByName[$name] as $route) {
            if ($this->patternAcceptsParams($route['pattern'], $params)) {
                return $this->buildUrlFromPattern($route['pattern'], $params);
            }
        }

        throw new Exception("No route variant for name '$name' matches given params: " . implode(', ', array_keys($params)));
    }

    private function patternAcceptsParams(string $pattern, array $params): bool {
        // required = <name> placeholders that are NOT inside an optional [...] group.
        // (Implemented by stripping the optional groups first; PCRE does not allow variable-length lookbehinds.)
        $withoutOptional = preg_replace('/\[[^\[\]]*\]/', '', $pattern);
        preg_match_all('/<([^>=]+)(?:=[^>]*)?>/', $withoutOptional, $matches);
        $required = $matches[1] ?? [];

        foreach ($required as $name) {
            if (!array_key_exists($name, $params)) {
                return false;
            }
        }

        return true;
    }

    private function buildUrlFromPattern(string $pattern, array $params): string {
        $path = preg_replace_callback('/<([^>=]+)(?:=([^>]*))?>/', function ($m) use ($params) {
            $name = $m[1];
            $default = $m[2] ?? null;

            if (array_key_exists($name, $params)) {
                return (string)$params[$name];
            }

            if ($default !== null) {
                return $default;
            }

            return '';
        }, $pattern);

        $path = preg_replace_callback('/\[[^\[\]]*\]/', function ($m) {
            return str_contains($m[0], '<') ? '' : trim($m[0], '[]');
        }, $path);

        $path = preg_replace_callback('/\[([^\[\]]*)\]/', fn($m) => $m[1], $path);

        return trim($path, '/');
    }

    private function processVariables($variables) {
        if($variables == null || !is_array($variables)) return;
        foreach($variables as $name => $value) {
            $_GET[$name] = $value;
        }
    }

    private function findSiblingByName(?string $name, Method $wantedMethod): ?ReflectionMethod {
        if ($name === null || !isset($this->routesByName[$name])) {
            return null;
        }

        foreach ($this->routesByName[$name] as $route) {
            if ($route['method'] === $wantedMethod) {
                [$class, $methodName] = $route['handler'];
                return new ReflectionMethod($class, $methodName);
            }
        }

        return null;
    }

    /**
     * @param bool $notFoundAsHttp When the class/method comes from the URL (controller/action fallback) an unknown
     *                             controller is a 404 for the visitor, not a programming error.
     */
    private function callController(array $definition, ?array $variables = null, ?Method $httpMethod = Method::UNKNOWN, bool $notFoundAsHttp = false): void {
        if (!is_array($definition)) throw new Exception("Definition must be array [class, method]");
        $class = $originalClass = (string)$definition[0];
        $methodNameOrCallback = count($definition) > 1 ? $definition[1] : "index";

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
            if ($notFoundAsHttp) throw new HttpException(404);
            throw new Exception("Invalid controller name '$originalClass'");
        }

        if (substr($class, 0, strlen("Controllers\\")) != "Controllers\\") $class = "Controllers\\" . $class;
        if (!class_exists($class) && class_exists($class . "Controller")) $class .= "Controller";

        if (!class_exists($class)) {
            if ($notFoundAsHttp) throw new HttpException(404);
            throw new Exception("Class $originalClass not exists");
        }
        if (!is_subclass_of($class, "Controller")) {
            if ($notFoundAsHttp) throw new HttpException(404);
            throw new Exception("Class $originalClass not implement class \"Controller\"");
        }

        $instance = Container::getInstance()->create($class);

        $isBuiltin = false;
        if (is_object($methodNameOrCallback) && method_exists($methodNameOrCallback, '__invoke')) {
            $ref = new ReflectionMethod($methodNameOrCallback, '__invoke');
            $isBuiltin = $ref->isInternal();
        }

        if (!$isBuiltin && is_callable($methodNameOrCallback)) {
            $method = $methodNameOrCallback($variables);
        } else {
            $method = $methodNameOrCallback;
        }

        if ($method == null || $method == "") $method = "index";

        if (!is_string($method) || !method_exists($instance, $method) || !(new ReflectionMethod($instance, $method))->isPublic()
            || str_starts_with($method, '__') || method_exists(Controller::class, $method)) {
            if ($notFoundAsHttp) throw new HttpException(404);
            throw new Exception("Method '$method' not found in class '$class'");
        }

        $reflectionMethod = new ReflectionMethod($instance, $method);
        $methodName = $this->request->method()->name;
        $methodsToRedirect = [];

        $methodsToRedirect[] = "$reflectionMethod->class::$reflectionMethod->name";
        while ($reflectionMethod != null) {
            // CHANGED: requiredMethod now comes from attributes only, no DocParser.
            // Priority: #[Route(method: ...)] first, then #[RequireMethod(...)] as fallback
            // for actions reachable directly via controller/action without a Route.
            $requiredMethod = Method::UNKNOWN;
            $routeName = null;

            $routeAttributes = $reflectionMethod->getAttributes(Route::class);
            if ($routeAttributes !== []) {
                $routeInstance = $routeAttributes[0]->newInstance();
                $requiredMethod = $routeInstance->method;
                $routeName = $routeInstance->name;
            }

            if ($requiredMethod === Method::UNKNOWN) {
                $requireMethodAttributes = $reflectionMethod->getAttributes(RequireMethod::class);
                if ($requireMethodAttributes !== []) {
                    $requiredMethod = $requireMethodAttributes[0]->newInstance()->method;
                }
            }

            if ($requiredMethod != Method::UNKNOWN && !$this->request->is($requiredMethod)) {
                $sibling = $this->findSiblingByName($routeName, $this->request->method());

                if ($sibling !== null) {
                    $fullName = "{$sibling->class}::{$sibling->name}";
                    if (in_array($fullName, $methodsToRedirect)) {
                        throw new Exception("Circular redirection detected, " . implode(" -> ", $methodsToRedirect) . " -> $fullName");
                    }
                    $methodsToRedirect[] = $fullName;
                    $reflectionMethod = $sibling;
                    continue;
                }

                throw new ControllerMethodNotAllowedException($requiredMethod);
            }

            //Check auth requirements and redirect to login if needed at start of the method call, before any model binding or other processing.
            if ($this->needAuthentication($reflectionMethod)) {
                $this->redirectToLoginIfNeeded();
            }

            $this->enforceAuthorization($reflectionMethod);
            $this->enforceCsrf($reflectionMethod);

            $methodParams = $reflectionMethod->getParameters();
            $resolvedParams = [];

            $modelParams = [];
            foreach ($methodParams as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    if (class_exists($typeName) && is_subclass_of($typeName, \Model::class)) {
                        $modelParams[] = $param->getName();
                    }
                }
            }

            foreach ($methodParams as $param) {
                $name = $param->getName();
                $type = $param->getType();

                if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                    $typeName = $type->getName();
                    if (class_exists($typeName) && is_subclass_of($typeName, \Model::class)) {
                        $rawId = $_GET[$name] ?? $_POST[$name] ?? null;

                        if ($rawId === null && count($modelParams) === 1) {
                            $rawId = $_GET['id'] ?? $_POST['id'] ?? null;
                        }

                        if ($rawId !== null) {
                            $model = $typeName::findById($rawId);
                            if ($model === null) {
                                throw new \ModelNotFoundException($typeName, $rawId);
                            }
                            $resolvedParams[] = $model;
                        } elseif ($param->isDefaultValueAvailable()) {
                            $resolvedParams[] = $param->getDefaultValue();
                        } elseif ($type->allowsNull()) {
                            $resolvedParams[] = null;
                        } else {
                            throw new \Exception("Missing ID for model binding of parameter '$name' ({$typeName})");
                        }
                        continue;
                    }
                }

                if (isset($_POST[$name])) {
                    $resolvedParams[] = $_POST[$name];
                } else if (isset($_GET[$name])) {
                    $resolvedParams[] = $_GET[$name];
                } elseif (isset($_SERVER[$name])) {
                    $resolvedParams[] = $_SERVER[$name];
                } elseif (isset($_FILES[$name])) {
                    $resolvedParams[] = $_FILES[$name];
                } elseif ($param->isDefaultValueAvailable()) {
                    $resolvedParams[] = $param->getDefaultValue();
                } else {
                    throw new \Exception("Missing value for parameter '$name' of method $method in class $class");
                }
            }

            // Check authentication again after resolving parameters, in case the controller method has attributes that depend on the parameters (e.g., model-based authorization).
            if ($this->needAuthentication($reflectionMethod)) {
                $this->redirectToLoginIfNeeded();
            }

            $resultModel = $reflectionMethod->invokeArgs($instance, $resolvedParams);
            if ($resultModel == null) {
                throw new ControllerReturnedNullException($reflectionMethod->class, $reflectionMethod->name);
            }

            foreach ($methodsToRedirect as $mtr) {
                $this->procesed[] = "--> $mtr";
            }
            $this->controllerData = $resultModel;
            break;
        }
    }

    public function redirectToLoginIfNeeded(){
        $authentication = $this->authenticationProvider->get();                
        if(!$authentication->isAuthenticationRequired() || $authentication->isAuthenticated()) return;        
        
        if($this->request->isJsonRequest()) {
            ob_clean();
            http_response_code(401);
            echo json_encode([
                'status' => 'need_login',
                'message' => 'You need to be loged in to view this page'
            ]);
            exit();
        }

        $authUrl = $authentication->getAuthenticationUrl();
        if(!empty($authUrl)) {
            $this->redirect($authUrl);
        } else {
            throw new Exception("Authentication required, but no URL provided.");
        }
    }

    public function needAuthentication(ReflectionMethod $reflectionMethod): bool {     
        $classReflection = new ReflectionClass($reflectionMethod->class);

        if ($classReflection->getAttributes(AllowAnonymous::class) !== []) {
            return false;
        }
        return $reflectionMethod->getAttributes(AllowAnonymous::class) === [];    
    }

    /**
     * #[Authorize(level: N)] on the action (or, as a fallback, on the controller): the signed-in user's permission
     * level must be at least N. Anonymous visitors go to the login page; others get a 403.
     */
    private function enforceAuthorization(ReflectionMethod $reflectionMethod): void {
        $attributes = $reflectionMethod->getAttributes(Authorize::class);
        if ($attributes === []) {
            $attributes = (new ReflectionClass($reflectionMethod->class))->getAttributes(Authorize::class);
        }
        if ($attributes === []) return;

        if (!USE_USERS) {
            throw new Exception("#[Authorize] needs the user service (USE_USERS)");
        }

        $required = $attributes[0]->newInstance()->level;
        $userService = Container::getInstance()->get(UserService::class);

        if (!$userService->isAuthentificated()) {
            $this->redirectToLoginIfNeeded();
            throw new HttpException(401, "You need to sign in to access this page");
        }
        if (!$userService->can($required)) {
            throw new HttpException(403, "You don't have permission to access this page");
        }
    }

    /**
     * CSRF policy: every state-changing request (anything but GET/HEAD/OPTIONS) to a controller action must carry
     * a valid token, unless the action or its controller is marked #[SkipCsrf], the request body is JSON
     * (cross-origin JSON needs a CORS preflight, which is only granted where Response::enableCors() is used),
     * or CSRF_PROTECTION is defined as false.
     */
    private function enforceCsrf(ReflectionMethod $reflectionMethod): void {
        if (!Csrf::enabled()) return;
        if ($this->request->is(Method::GET) || $this->request->is('OPTIONS')) return;
        if ($this->request->hasJsonBody()) return;

        $classReflection = new ReflectionClass($reflectionMethod->class);
        if ($classReflection->getAttributes(SkipCsrf::class) !== [] || $reflectionMethod->getAttributes(SkipCsrf::class) !== []) {
            return;
        }

        if (!Csrf::validate(Csrf::tokenFromRequest($this->request))) {
            throw new CsrfTokenMismatchException();
        }
    }

    /**
     * True when the matched action produces (or leaves room for) a full HTML page, false when it answers
     * with JSON, a redirect or a standalone component. Lets index.php skip the page shell (navigation,
     * flash messages) for responses that never show it.
     */
    public function rendersPage(): bool {
        if ($this->controllerData === null) return true;
        $type = $this->controllerData->getType();
        if ($type === ControllerActionType::None) return true;
        return $type === ControllerActionType::View && !$this->controllerData->shouldClearContent();
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
        else if ($this->controllerData->getType() == ControllerActionType::RedirectToRoute) {
            $routeName = $this->controllerData->getRedirectRoute();

            if ($routeName !== null) {
                $url = $this->generateUrl($routeName, $this->controllerData->getRedirectRouteParams());
            } else {
                $url = $this->controllerData->getRedirectUrl();
            }

            $this->redirect($url);
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

    public function getCurrentUrl(): string {
        return self::currentPath();
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
        $queryString = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        if ($queryString) {
            parse_str($queryString, $queryParams);
            foreach ($queryParams as $key => $value) {
                $_GET[$key] = $value;
            }
        }
    }

    private function generateBaseUrl(): string {
        $protocol = $_SERVER['REQUEST_SCHEME'] ?? 'http';
        $host = $_SERVER['SERVER_NAME'] ?? '';
        $port = self::getPort();
        return "{$protocol}://{$host}{$port}";
    }

    public function dump(): void {
        echo '<div style="padding:7px;">Matching URL: ' . htmlspecialchars($this->matchedUrl??"") . ' >>>> currl="'.htmlspecialchars($this->getCurrentUrl()).'" >>>> url="'.htmlspecialchars($_GET["url"] ?? '').'"</div>';
        echo "<div style='padding:7px;'>";
        foreach($this->procesed as $p) {
            echo "<div>".htmlspecialchars($p)."</div>";
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
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        echo "<head>".PHP_EOL;
		echo '<title>' . $e($this->title) . '</title>'.PHP_EOL;
		echo '<meta http-equiv="Content-Type" content="text/html; charset=' . $e($this->charset) . '">'.PHP_EOL;

        if($this->description != "")
		    echo '<meta name="description" content="' . $e($this->description) . '">'.PHP_EOL;
        if($this->language != "")
		    echo '<meta http-equiv="Content-language" content="' . $e($this->language) . '">'.PHP_EOL;
        if($this->author != "")
		    echo '<meta name="author" content="' . $e($this->author) . '">'.PHP_EOL;
        if($this->keywoards != "")
		    echo '<meta name="keywords" content="' . $e($this->keywoards) . '">'.PHP_EOL;

		echo '<meta name="viewport" content="width=device-width, initial-scale=1">'.PHP_EOL;
		if($this->browserColor != "")
			echo '<meta name="theme-color" content="'.$e($this->browserColor).'">'.PHP_EOL;
		
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
	public function addStyle(string $url, string $type = "text/css") {
		$this->styles[] = array("url" => $url, "type" => $type);
	}

	/**
	 * Add script to page 
	 * $inhead = true | the script will be loaded in header else at end of the page
	 */
	public function addScript(string $url, bool $inhead = true) {
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
        // Sweep stale compiled templates at most once per hour instead of on every request.
        $marker = ROOT."/temp/templates/.gc";
        $lastSweep = @filemtime($marker);
        if ($lastSweep !== false && $lastSweep > time() - 3600) {
            return;
        }
        @touch($marker);

		//Remove old temp files
		$timeMaxOld = strtotime("-1 week");
        foreach (glob(ROOT."/temp/templates/*") as $file) {
            if (!is_file($file)) continue;

            $mtime = @filemtime($file);
            if ($mtime === false) continue;

            if ($mtime < $timeMaxOld) {
                @unlink($file);
            }
        }
	}

    public function render(string $filename, mixed $model = NULL, bool $onlycompile = false, &$outputFile = null): bool {		
		if (!file_exists($filename)) {
			throw new Exception("Failed to load template \"".$filename."\"");
		}	        

		$name = pathinfo($filename, PATHINFO_FILENAME);
		$content = file_get_contents($filename);
		$hash = sha1(TemplaterV2::VERSION . "\0" . $content);
		if (defined('DEBUG')) {
			$hash = 'debug';
		}
		
        $layoutHash = $name.".".$hash;
		$file = ROOT."/temp/templates/".$name.".".$hash.".template.php";
		$outputFile = $file;

		if(file_exists($file) && !defined('DEBUG')){
			if(!$onlycompile)
				include($file);

			return false;
		}

        $fileNameDot = str_replace(["/", "\\"], ".", rtrim(ltrim(str_replace(ROOT, "", $filename), "/"), ".view"));
        DebugTimer::start("layout.render.parse." . $fileNameDot);

		$template = new TemplaterV2($content, $filename);
		$template->process();
		$out = $template->getOutput();

		file_put_contents($file, $out);

        DebugTimer::stop("layout.render.parse." . $fileNameDot);

		if(!$onlycompile) {
            DebugTimer::start("layout.render.include." . $fileNameDot);
			include($file);
            DebugTimer::stop("layout.render.include." . $fileNameDot);
        }

		return true;
	}
}

class TemplaterV2Exception extends \Exception {
    protected ?int $col;
    protected ?int $row;

    public function __construct(string $message, $file = null, $col = null, $row = null) {
        parent::__construct($message);

        if ($file !== null) {
            $this->file = $file;
        }

        $this->col = $col;
        $this->row = $row;
    }

    public function getCol(): ?int {
        return $this->col;
    }

    public function getRow(): ?int {
        return $this->row;
    }
}

class TemplaterV2 {
    public const VERSION = "3";

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
	private ?array $controllTokensDefinition = null;
	private $lastTokenIndex = 0;
	private $eatTokenSafeCounter = 0;
	private $safeBreakTokenCounter = 30;
	private $lastDebugMessage = "";
	private $openControllTokens = [];
    private $printHtmlComments = true;

    public function __construct(string $content, string $fileName = "inline"){
		if($this->controllTokensDefinition == null) {
			$this->controllTokensDefinition = [TokenType::$LBRACKET, TokenType::$RBRACKET];
		}

        $content = str_replace("\r", "", $content);
        $this->content = $this->utf8Split($content);
        $this->contentLen = count($this->content);
		$this->fileName = $fileName;
    }
    /**
     * Output escaper used by compiled templates for every {$expression}.
     * Strings (and Stringable objects) are HTML-escaped; null becomes ""; int/float/bool pass through
     * unchanged so that the conditional attribute syntax (checked="{$bool}") keeps working.
     * Use {!$expression} to output raw HTML on purpose.
     */
    public static function e(mixed $value): mixed {
        if ($value === null) return "";
        if (is_string($value)) return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (is_int($value) || is_float($value) || is_bool($value)) return $value;
        if ($value instanceof \Stringable) return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (is_array($value)) throw new \InvalidArgumentException("Cannot output an array in a template; use {^\$var} to dump it or iterate with {for}.");
        throw new \InvalidArgumentException("Cannot output value of type " . get_debug_type($value) . " in a template.");
    }

    private function printToken(array $token) {
        return TokenType::print($token["type"])." '".$token["value"]."'";
    }

    private function printTokenInfo(array $token) {
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
                $this->throwException("Infinite loop occured! Token: " . $this->printToken($token)." ".$this->printTokenInfo($token), $token);
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

    private function assertToken(array $token, bool $condition, string $message = "", bool $reportedOnly = false){        
        if(!$condition) {
			if($message != "") $message = ", ".$message;
            $this->throwException(($reportedOnly?"Reported token":"Unkown token")." type '".TokenType::print($token["type"])."' ".$this->printTokenInfo($token).$message."; File: " . $this->fileName."\n", $token);
		}
    }

    private function throwException(string $message, $token = null) {
        throw new TemplaterV2Exception(
                $message,
                $this->fileName,
                $token != null && $token["type"] != -1? $token["info"]["line"]["col"]: 0,
                $token != null && $token["type"] != -1? $token["info"]["line"]["row"]: 0
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
                if($elementHasNoPair || $this->getToken()["type"] == TokenType::$SLASH) {                    
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

    private function consumeElementParameter(array $token) {
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

    private function parseControllTokens(array $controllTokens){
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

	private function parserParams(string $params, ?int $len = null){
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
		// The closing "}" is intentionally left for the caller (parseBlock eats one token after every control),
		// otherwise the first token after {/capture} / {/function} was swallowed.
	}

	private function registerOpenControll(string $name, $token) {
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
            
            $this->throwException($message, $lastToken);
		}
	}

    private function renderControll(string $input, $token) {
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
		} else if(substr($type[0], 0, 1) == "!" || substr($type[0], 0, 3) == "raw"){ //TODO: propably use different controll for this, because this is not very clear like "raw"
            // {!$html} | {raw $html} -> raw, unescaped output
            if(substr($type[0], 0, 1) == "!") {
                $expression = trim(substr($input, 1, strlen($input) - 1));
            } else {
                $expression = trim(substr($input, 3, strlen($input) - 3));
            }
            return "<?php echo ".trim($expression)."; ?>";
		} else if($type == "csrf") {
            // {csrf} -> hidden input with the anti-CSRF token
            return "<?php echo Csrf::field(); ?>";
		} else if($type == "flash") {
            // {flash} -> pending one-shot messages (Flash::success() etc.), rendered once
            return "<?php echo Flash::render(); ?>";
		} else if($type == "route") {
            // {route 'name'} or {route 'name', ['id' => $id]} -> absolute URL of a named route
			$params = $this->parserParams($value, 1);
            $name = trim($params[0]);
            $routeParams = isset($params[1]) && trim($params[1]) !== "" ? $this->toPhp($params[1]) : "[]";
            return "<?php echo Router::urlFor(".$name.", ".$routeParams."); ?>";
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

        // {$expression} -> escaped output (see TemplaterV2::e). Use {!$expression} | {raw $expression} for raw HTML.
        return "<?php echo TemplaterV2::e(".$input."); ?>";
    }

    private function toPhp(string $code) {
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

	private function notControll(string $input) {
		return (substr($input, 0, 1) == "\"" && strpos($input, "\n") !==false) || substr($input, 0, 1) == " " || substr($input, 0, 1) == "\n" || trim($input) == "";
	}

	/*param="x"*/
    private function printOutputParameter(){
        $token = $this->getToken();
        $endWith = TokenType::$NONE;
        if($token["type"] == TokenType::$APOSTROPE) {
            $endWith = TokenType::$APOSTROPE;
        }else if($token["type"] == TokenType::$QUOTEMARK) {
            $endWith = TokenType::$QUOTEMARK;
        }else {
            $this->eat();
            return $token["value"];
        }        

        $this->eat();
        if($endWith == TokenType::$NONE) return;

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

    private function printOutputText(array $token){
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
            $this->throwException("Output cache index is less than zero!");
		}
		return $this->outputCache[$index];
	}

    private function printOutput(string $text) {
        $this->outputCache[$this->outputCacheIndex] .= $text;
    }

    public function getOutput(){
        return $this->output;
    }

    private function utf8Split(string $str, int $len = 1): array {
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

    private function isCharacter(string $char){
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
    public static $NONE = -2;
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

    public static function print(TokenType | int $type) {
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

    public static function name(TokenType $type) {
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
    private static ?string $secret = null;

    private static function getSecret(): string {
        if (self::$secret !== null) return self::$secret;

        $file = __DIR__ . '/secret.json';
        if (!file_exists($file)) {
            $dataToSave = [
                "cookies" => bin2hex(random_bytes(32))
            ];
            file_put_contents($file, json_encode($dataToSave, JSON_PRETTY_PRINT), LOCK_EX);
        }

        $jsonContent = file_get_contents($file);
        $config = json_decode($jsonContent, true);
        if($config == null) {
            $config = [];
        }

        if(!isset($config["cookies"])) {
            $config["cookies"] = bin2hex(random_bytes(32));
            file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
        }

        self::$secret = $config["cookies"];
        return self::$secret;
    }

    /**
     * Server-side secret shared by cookie signing and CSRF tokens.
     */
    public static function secret(): string {
        return self::getSecret();
    }

    /**
     * Converts the accepted expiration formats into an absolute Unix timestamp.
     *   int    -> seconds from now (0 = session cookie)
     *   string -> strtotime() expression, e.g. "+1 day" or "-1 hour"
     */
    public static function expiresAt(int|string $time): int {
        if (is_int($time)) {
            return $time === 0 ? 0 : time() + $time;
        }
        $ts = strtotime($time);
        if ($ts === false) {
            throw new InvalidArgumentException("Invalid cookie expiration: '$time'");
        }
        return $ts;
    }

    private static function isDeletion(int|string $time): bool {
        return is_int($time) ? $time < 0 : str_starts_with(trim($time), "-");
    }

    private static function options(int $expires): array {
        return [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function signature(string $name, string $value): string {
        //TODO: for some special cookies use session_id
        //. "|" . Request::getSessionId()
        return hash_hmac('sha256', $name . "|" . $value , self::getSecret());
    }

	/*
		Example :
		Cookies::set( array("name" => "test", "permision" => "admin"), "+24 hour", false );
		Cookies::set( array("name", "permision"), array("test", "admin"), "+24 hour" );
		Cookies::set( array("name", "permision"), "admin", "+24 hour" );
		Cookies::set( "name", "test", "+24 hour" );

        $time: int = seconds from now (0 = until the browser closes), string = strtotime() expression.
        Every cookie gets a companion SECURITY_<name> cookie carrying an HMAC so that tampering is detected.
	*/
	public static function set(string | array $name, mixed $value = 1, mixed $time = "+1 hour"): bool {
		if($time === false){
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
            $value = (string)$value;
            $expires = self::expiresAt($time);
            $deleting = self::isDeletion($time);

			if(!$deleting){
				$_COOKIE[$name] = $value;
			}else{
				unset($_COOKIE[$name], $_COOKIE["SECURITY_".$name]);
			}

            setcookie($name, $value, self::options($expires));

			if(!$deleting){
				$hash = self::signature($name, $value);
				$url  = Router::url();
                $security = $hash.";;".time().";;".$url;
                $_COOKIE["SECURITY_".$name] = $security;
				setcookie("SECURITY_".$name, $security, self::options($expires));
			}else{
				setcookie("SECURITY_".$name, "", self::options(time() - 3600));
				return false;
			}
		}

        return true;
	}
	
	public static function delete(string | array $name): bool {
		if(gettype($name) == "array"){
			for($i = 0; $i < count($name); $i++){
				Cookies::set($name[$i], "", "-1 hour");                
			}
            return true;
		}
        
        return Cookies::set($name, "", "-1 hour");
	}
	
	public static function exists(string $name): bool {
		return isset($_COOKIE[$name]);
	}

    public static function get_security(string $name): string | bool {
        if(Cookies::exists($name) && Cookies::security_check($name)) {
            return $_COOKIE[$name];
        } else return false;
    }
	
	public static function security_check(string $name): bool {
		$security = Cookies::security_get($name);
		if($security === false || !isset($_COOKIE[$name])) {
            return false;
        }
        $expected = self::signature($name, (string)$_COOKIE[$name]);
        return hash_equals($expected, (string)$security["hash"]);
	}
	
	public static function security_get(string $name): array | bool {
		if(isset($_COOKIE["SECURITY_".$name])){
			$data = explode(";;", $_COOKIE["SECURITY_".$name], 3);
			return array(
				"hash" 		=> $data[0] ?? "",
				"create" 	=> $data[1] ?? "",
				"url"		=> $data[2] ?? ""
			);
		}else return false;
	}
	
	public static function security_delete(){
		foreach($_COOKIE as $key => $value){
			$dt = explode("_",$key,2);
			if(count($dt)>1){ 
				if($dt[0] == "SECURITY"){ 
					if(!Cookies::exists($dt[1])){ 
						setcookie($key, "", self::options(time() - 3600)); 			
					} 
				} 
			}
		}
	}

	public static function create_ifnotExists(string $name, string $value = "", string $time = "+1 hour"): bool {
		if(Cookies::exists($name)){
			return true;
		}
		Cookies::set($name, $value, $time);
		return true;
	}
	
	public static function dump($onlyName = false) {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		echo "<table style='table-layout: fixed; border-collapse: collapse;width:100%;' border=0 class='snowLeopard'>";
		if($onlyName){
			echo "<tr style='border: 1px solid black;'><th>Key</th></tr>";
			foreach($_COOKIE as $key => $value){
				$dt = explode("_",$key,2);
                $value = $e($value);
				if(count($dt)>1){ if($dt[0] == "SECURITY"){ if(!Cookies::exists($dt[1])){ $value="<i>DELETED SECURITY COOKIES</i>"; } } }
				echo "<tr><td style='".($dt[0] == "SECURITY"?"color:red;font-weight:bold;":"")."'>".$e($key)."<br><small>".$value."</small></td></tr>";
			}
		}else{
			echo "<tr style='border: 1px solid black;'><th width=300>Key</th><th width=600>Value</th></tr>";
			foreach($_COOKIE as $key => $value){
				$dt = explode("_",$key,2);
                $value = $e($value);
				if(count($dt)>1){ if($dt[0] == "SECURITY"){ if(!Cookies::exists($dt[1])){ $value="<i>DELETED SECURITY COOKIES</i>"; } } }
				echo "<tr><td style='".($dt[0] == "SECURITY"?"color:red;font-weight:bold;":"")."'>".$e($key)."</td><td>".$value."</td></tr>";
			}
		}
		if(count($_COOKIE) == 0)
			echo "<tr><td colspan=2>No cookies...</td></tr>";
		echo "</table>";
	}
}

/**
 * Anti-CSRF tokens without PHP sessions: a random nonce lives in an HttpOnly cookie,
 * the form carries "nonce.hmac(secret, nonce)". A request is valid when the token's HMAC verifies
 * and its nonce equals the cookie (double-submit + signature, so a cookie injected from a sibling
 * subdomain is not enough).
 */
class Csrf {
    public const COOKIE = "csrf";
    public const FIELD = "_csrf";
    public const HEADER = "X-CSRF-Token";

    private static ?string $nonce = null;

    public static function enabled(): bool {
        return !defined("CSRF_PROTECTION") || CSRF_PROTECTION;
    }

    private static function nonce(): string {
        if (self::$nonce !== null) return self::$nonce;

        $existing = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($existing) && preg_match('/^[a-f0-9]{32}$/', $existing)) {
            self::$nonce = $existing;
            return self::$nonce;
        }

        self::$nonce = bin2hex(random_bytes(16));
        $_COOKIE[self::COOKIE] = self::$nonce;
        setcookie(self::COOKIE, self::$nonce, [
            'expires'  => 0,
            'path'     => '/',
            'secure'   => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return self::$nonce;
    }

    private static function sign(string $nonce): string {
        return hash_hmac('sha256', "csrf|" . $nonce . "|" . Request::getSessionId(), Cookies::secret());
    }

    /**
     * Token for the current visitor; embed it in forms (see field()) or send it as the X-CSRF-Token header.
     */
    public static function token(): string {
        $nonce = self::nonce();
        return $nonce . "." . self::sign($nonce);
    }

    public static function field(): string {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . self::token() . '">';
    }

    /**
     * Makes sure the csrf cookie exists early in the request (before any output).
     */
    public static function ensureCookie(): void {
        if (self::enabled()) self::nonce();
    }

    public static function validate(?string $token): bool {
        if ($token === null || !str_contains($token, ".")) return false;
        [$nonce, $signature] = explode(".", $token, 2);

        if (!preg_match('/^[a-f0-9]{32}$/', $nonce)) return false;
        if (!hash_equals(self::sign($nonce), $signature)) return false;

        $cookie = $_COOKIE[self::COOKIE] ?? null;
        return is_string($cookie) && hash_equals($cookie, $nonce);
    }

    /**
     * Token as sent by the current request: the _csrf form field or the X-CSRF-Token header.
     */
    public static function tokenFromRequest(Request $request): ?string {
        $token = $_POST[self::FIELD] ?? null;
        if ($token === null) {
            $token = $request->headerIgnoreCase(self::HEADER);
        }
        return is_string($token) ? $token : null;
    }

    /** Test helper: forget the cached nonce so the next call re-reads $_COOKIE. */
    public static function reset(): void {
        self::$nonce = null;
    }
}
/**
 * One-shot messages shown on the next page (the "redirect after POST" pattern), stored in a signed cookie
 * because the framework has no server-side session.
 *
 *   Flash::success("Article saved");           in the controller, before redirecting
 *   {flash}                                    in the layout/view, renders and clears the messages
 */
class Flash {
    public const COOKIE = "flash";
    public const LIFETIME = 300;

    private static ?array $pulled = null;

    public static function add(string $type, string $message): void {
        $messages = self::peek();
        $messages[] = ['type' => $type, 'message' => $message];
        Cookies::set(self::COOKIE, json_encode($messages, JSON_UNESCAPED_UNICODE), self::LIFETIME);
        self::$pulled = null;
    }

    public static function success(string $message): void { self::add('success', $message); }
    public static function error(string $message): void { self::add('error', $message); }
    public static function warning(string $message): void { self::add('warning', $message); }
    public static function info(string $message): void { self::add('info', $message); }

    /** @return array<int, array{type:string, message:string}> */
    private static function peek(): array {
        $raw = Cookies::get_security(self::COOKIE);
        if ($raw === false) return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        return array_values(array_filter($decoded, fn($m) => is_array($m) && isset($m['type'], $m['message'])));
    }

    /**
     * Returns the pending messages and removes the cookie so they are shown only once.
     * @return array<int, array{type:string, message:string}>
     */
    public static function pull(): array {
        if (self::$pulled !== null) return self::$pulled;
        $messages = self::peek();
        if (Cookies::exists(self::COOKIE)) {
            Cookies::delete(self::COOKIE);
        }
        self::$pulled = $messages;
        return $messages;
    }

    public static function has(): bool {
        return self::pull() !== [];
    }

    /**
     * HTML for the pending messages: <div class="flash flash-success">…</div>, escaped.
     */
    public static function render(): string {
        $html = '';
        foreach (self::pull() as $message) {
            $type = preg_replace('/[^a-z]/', '', strtolower($message['type'])) ?: 'info';
            $html .= '<div class="flash flash-' . $type . '" role="alert">' . htmlspecialchars($message['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
        }
        return $html;
    }

    /** Test helper. */
    public static function reset(): void {
        self::$pulled = null;
    }
}

class Database {
    private ?\PDO $connection = null;

    public static function registerConfig(ConfigProvider $configProvider): void {
        $configProvider->register([
                'DB_NAME',
                new ConfigType('DB_DRIVER', false, false, ConfigBasicValidator::enum("mysql", "sqlite")),
                new ConfigType('DB_HOST', false),
                new ConfigType('DB_USER', false),
                new ConfigType('DB_PASS', false, true),
            ],
            function(array $cfg) {
                $db = Container::getInstance()->get(Database::class);
                $driver = strtolower($cfg['DB_DRIVER'] ?? 'mysql');

                if ($driver === 'sqlite') {
                    $db->connectSqlite($cfg['DB_NAME']);
                    return;
                }

                if (!isset($cfg['DB_HOST']) || !isset($cfg['DB_USER'])) {
                    throw new Exception("DB_HOST and DB_USER are required for the mysql driver.");
                }

                $db->connect(
                    $cfg['DB_HOST'],
                    $cfg['DB_NAME'],
                    $cfg['DB_USER'],
                    $cfg['DB_PASS'] ?? ""
                );
            }
        );
    }

    public function connect(string $host, string $database, string $username, string $password) {
        DebugTimer::start("database.connect");
        $this->connection = new PDO("mysql:host=".$host.";dbname=".$database.";charset=utf8mb4", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        DebugTimer::stop("database.connect");
    }

    /**
     * Opens a SQLite database. $path is a file path (relative paths are resolved against ROOT) or ":memory:".
     */
    public function connectSqlite(string $path): void {
        DebugTimer::start("database.connect");
        if ($path !== ':memory:' && !preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path)) {
            $path = ROOT . '/' . ltrim($path, '/');
        }
        $pdo = new PDO("sqlite:" . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON");
        $this->connection = $pdo;
        DebugTimer::stop("database.connect");
    }

    /**
     * Uses an already opened PDO connection (tests, custom drivers).
     */
    public function connectPdo(PDO $pdo): void {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection = $pdo;
    }

    public function isConnected(): bool {
        return $this->connection !== null;
    }

    public function getConnection(): PDO {
        if($this->connection == null) 
            throw new Exception("Connection was not opened");

        return $this->connection;
    }
    /**
     * Name of the PDO driver in use ("mysql", "sqlite", ...); "mysql" when nothing is connected.
     */
    public function driver(): string {
        if ($this->connection === null) return 'mysql';
        return strtolower((string)$this->connection->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public static function getPdoParamType(mixed $value): int {
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
    /** Column name of the primary key */
    public string $primaryKey;
    /** Property name of the primary key (differs from the column when #[Column('...')] renames it) */
    public string $primaryKeyProperty;
    public bool $primaryKeyAutoIncrement = true;
}

#[Attribute(Attribute::TARGET_CLASS)]
final class Table {
    public function __construct(public string $name) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class PrimaryKey {
    public function __construct(public bool $autoIncrement = true) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $defaultValue = null,
        public ?int $length = null,
    ) {}
}

enum RelationType: string
{
    /**
     * One-to-many relation where the foreign key lives on the TARGET table.
     * Example: User hasMany Orders -> `orders.user_id` points back to this record.
     */
    case HasMany = 'hasMany';

    /**
     * Inverse relation where the foreign key lives on THIS table.
     * Example: Order belongsTo User -> `orders.user_id` points to the related User.
     */
    case BelongsTo = 'belongsTo';

    /**
     * One-to-one relation where the foreign key lives on THIS table.
     * Example: User hasOne Permission -> `users.permission_id` points to the related Permission.
     */
    case HasOne = 'hasOne';

    /**
     * One-to-one relation where the foreign key lives on the TARGET table (inverse of HasOne).
     * Example: Manifest hasOneInverse DailyStat -> `daily_stats.manifest_id` points back to this record.
     */
    case HasOneInverse = 'hasOneInverse';
}

/**
 * Defines the type of relationship between models and, implicitly,
 * on which side of the relation the foreign key is stored.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Relation
{
    /**
     * @param bool $cascade For HasMany / HasOneInverse: delete the related rows when this model is deleted.
     */
    public function __construct(
        public RelationType $type,
        public string $class,
        public ?string $foreignKey = null,
        public bool $cascade = false,
    ) {}
}

/**
 * Reflection-derived description of a model class, computed once per class and cached.
 */
final class ModelMetadata {
    /** @var array<string, ModelMetadata> */
    private static array $cache = [];

    public string $class;
    public string $shortName;
    public string $table;
    public ?string $primaryKeyProperty = null;
    public ?string $primaryKeyColumn = null;
    public bool $primaryKeyAutoIncrement = true;
    /** @var array<string,string> property => column */
    public array $mappings = [];
    /** @var array<string,string> column => property */
    public array $mappingsBack = [];
    /** @var array<string,array{length: ?int}> column => definition */
    public array $columnsDefinition = [];
    /** @var array<string,mixed> property => default value */
    public array $defaults = [];
    /** @var array<string,?string> property => backed enum class (or null) */
    public array $enumTypes = [];
    /** @var array<string,array{type: RelationType, class: class-string<Model>, foreignKey: ?string, method: string}> */
    public array $relations = [];
    public bool $hasOnCreated = false;

    private function __construct(string $class) {
        $this->class = $class;
    }

    /**
     * @param class-string<Model> $class
     */
    public static function for(string $class): self {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        $reflector = new ReflectionClass($class);
        $meta = new self($class);
        $meta->shortName = $reflector->getShortName();

        $tableAttributes = $reflector->getAttributes(Table::class);
        if (empty($tableAttributes)) {
            throw new Exception("Table name is not set for model {$class} (missing #[Table('...')] attribute)");
        }
        $meta->table = $tableAttributes[0]->newInstance()->name;
        $meta->hasOnCreated = $reflector->hasMethod('onCreated');

        foreach ($reflector->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;

            $propName = $property->getName();
            $column = $propName;
            $length = null;
            $columnAttr = null;

            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
                $columnAttr = $columnAttributes[0]->newInstance();
                $column = $columnAttr->name ?? $propName;
                $length = $columnAttr->length;
            }

            $primaryKeyAttributes = $property->getAttributes(PrimaryKey::class);
            if (!empty($primaryKeyAttributes)) {
                $meta->primaryKeyProperty = $propName;
                $meta->primaryKeyColumn = $column;
                $meta->primaryKeyAutoIncrement = $primaryKeyAttributes[0]->newInstance()->autoIncrement;
            }

            $meta->mappings[$propName] = $column;
            $meta->mappingsBack[$column] = $propName;
            $meta->columnsDefinition[$column] = ["length" => $length];
            $meta->enumTypes[$propName] = null;
            $meta->defaults[$propName] = self::defaultFor($property, $columnAttr, $meta->enumTypes[$propName]);
        }

        // Register before resolving relations so that mutually related models (User <-> Article) do not recurse forever.
        self::$cache[$class] = $meta;

        foreach ($reflector->getMethods(ReflectionMethod::IS_PRIVATE | ReflectionMethod::IS_PROTECTED | ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(Relation::class);
            if (empty($attributes)) continue;

            $relation = $attributes[0]->newInstance();
            $key = lcfirst(preg_replace('/^get/', '', $method->getName()));
            $meta->relations[$key] = [
                'type' => $relation->type,
                'class' => $relation->class,
                'foreignKey' => $relation->foreignKey,
                'cascade' => $relation->cascade,
                'method' => $method->getName(),
            ];
        }

        return $meta;
    }

    private static function defaultFor(ReflectionProperty $property, ?Column $column, ?string &$enumType): mixed {
        $type = $property->getType();

        if ($column !== null && $column->defaultValue !== null) {
            if (!$type instanceof ReflectionNamedType || $type->getName() !== 'string') {
                throw new Exception("Default can be set only for string data types ({$property->getDeclaringClass()->getName()}::\${$property->getName()})");
            }
            return $column->defaultValue;
        }

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        $typeName = $type->getName();

        if (!$type->isBuiltin() && enum_exists($typeName)) {
            $refEnum = new ReflectionEnum($typeName);
            if ($refEnum->isBacked()) {
                $enumType = $typeName;
            }
            if ($type->allowsNull()) {
                return null;
            }
            $cases = $typeName::cases();
            if (count($cases) === 0) {
                throw new Exception("Enum $typeName has no defined values");
            }
            return $cases[0];
        }

        if ($type->allowsNull()) {
            return null;
        }

        if (!$type->isBuiltin()) {
            throw new Exception("Class $typeName is not an enum, model properties must be scalars or backed enums");
        }

        return match ($typeName) {
            'int' => 0,
            'float' => 0.0,
            'string' => "",
            'bool' => false,
            default => null,
        };
    }

    /**
     * Resolves a relation into concrete column names.
     *
     * HasOne / BelongsTo: the foreign key column lives on THIS table (localColumn) and points at the
     * related model's primary key (referencedColumn).
     * HasMany / HasOneInverse: the foreign key column lives on the TARGET table (targetColumn) and
     * points back at this model's primary key.
     *
     * @return array{type: RelationType, class: class-string<Model>, localColumn: ?string, targetColumn: ?string, referencedColumn: string}
     */
    public function resolveRelation(string $key): array {
        if (!isset($this->relations[$key])) {
            throw new Exception("Relationship $key not defined in {$this->class}");
        }
        $relation = $this->relations[$key];
        $target = self::for($relation['class']);

        switch ($relation['type']) {
            case RelationType::HasOne:
            case RelationType::BelongsTo:
                return [
                    'type' => $relation['type'],
                    'class' => $relation['class'],
                    'localColumn' => $relation['foreignKey'] ?? strtolower($target->shortName) . '_id',
                    'targetColumn' => null,
                    'referencedColumn' => $target->primaryKeyColumn ?? 'id',
                ];
            case RelationType::HasMany:
            case RelationType::HasOneInverse:
                return [
                    'type' => $relation['type'],
                    'class' => $relation['class'],
                    'localColumn' => null,
                    'targetColumn' => $relation['foreignKey'] ?? strtolower($this->shortName) . '_id',
                    'referencedColumn' => $this->primaryKeyColumn ?? 'id',
                ];
        }

        throw new Exception("Unknown relation type");
    }

    /** Test helper / hot-reload helper. */
    public static function flush(): void {
        self::$cache = [];
    }
}

abstract class Model {
    private ModelMetadata $meta;

    /** @var array<string,string> property => column (kept for backward compatibility with subclasses) */
    protected $mappings = [];
    /** @var array<string,string> column => property */
    protected $mappingsBack = [];
    protected $columnsDefinition = [];

    protected Database $database;
    protected $attributes = [];
    protected ModelState $state = ModelState::New;

    private array $relationCache = [];

    public function __construct(array $data = []) {
        $this->database = Container::getInstance()->get(Database::class);
        $this->meta = ModelMetadata::for(static::class);
        $this->mappings = $this->meta->mappings;
        $this->mappingsBack = $this->meta->mappingsBack;
        $this->columnsDefinition = $this->meta->columnsDefinition;

        $this->loadDefaults();
        $this->populate($data);
    }

    /**
     * Cached reflection data for this model class.
     */
    public static function metadata(): ModelMetadata {
        return ModelMetadata::for(static::class);
    }

    protected static function getTableDefinition(): ModelDefinition {
        $meta = ModelMetadata::for(static::class);

        $def = new ModelDefinition();
        $def->tableName = $meta->table;
        $def->primaryKey = $meta->primaryKeyColumn ?? 'id';
        $def->primaryKeyProperty = $meta->primaryKeyProperty ?? 'id';
        $def->primaryKeyAutoIncrement = $meta->primaryKeyAutoIncrement;
        return $def;
    }

    private static function getConnection(): PDO {
        return Container::getInstance()->get(Database::class)->getConnection();
    }

    private function populate(array $data) {
        if(!empty($data))
            $this->state = ModelState::Loaded;
        else if ($this->meta->hasOnCreated)
            $this->onCreated();

        foreach ($data as $column => $value) {
            $propertyName = $this->meta->mappingsBack[$column] ?? null;
            if ($propertyName === null) continue;

            $enumType = $this->meta->enumTypes[$propertyName];
            if ($enumType !== null) {
                if ($value === null) {
                    $this->$propertyName = null;
                    continue;
                }
                $enumValue = $enumType::tryFrom($value);
                if ($enumValue === null) {
                    throw new \UnexpectedValueException("Invalid value '$value' for enum $enumType");
                }
                $this->$propertyName = $enumValue;
                continue;
            }

            $this->$propertyName = $value;
        }
    }

    public function isNew(): bool {
        return $this->state == ModelState::New;
    }

    private function loadDefaults() {
        foreach ($this->meta->defaults as $propertyName => $defaultValue) {
            $this->$propertyName = $defaultValue;
        }
    }

    /**
     * Value of the primary key of this instance (null for unsaved auto-increment models).
     */
    public function getPrimaryKeyValue(): mixed {
        $property = $this->meta->primaryKeyProperty;
        if ($property === null) return null;
        return $this->$property;
    }

    public static function findById(int|string $id): ?static {
        $definition = self::getTableDefinition();
        return self::where([$definition->primaryKey => $id])->fetch();
    }

    /**
     * @return static[]|null
     */
    public static function fetchAll(): ?array {
        $definition = self::getTableDefinition();
        $db = self::getConnection();
        $stmt = $db->prepare("SELECT * FROM `".$definition->tableName."`");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $objects = [];
        foreach ($rows as $row) {
            $objects[] = new static($row);
        }
        return $objects;
    }

    /**
     * @return \QueryBuilder<static>
     */
    public static function where(string|array|callable $condition, $params = []): QueryBuilder {
        return self::toQuery()->where($condition, $params);
    }

    /**
     * @return \QueryBuilder<static>
     */
    public static function toQuery(): QueryBuilder {
        $definition = self::getTableDefinition();
        return new QueryBuilder(self::getConnection(), $definition->tableName, static::class);
    }

    public static function toList(int $limit = 20, ?string $orderBy = null, ?int $page = null, array $options = [], ?QueryBuilder $query = null): ModelListResult {
        if($query == null) $query = self::toQuery();

        if($page != null) $options["currentPage"] = $page;
        // Keep the current query string (search, filters) in the page links; Paginator replaces only its own page parameter.
        $paginator = new \Paginator($query->count(), $limit, \Router::url(true, true), $options);
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
    public function delete(): bool {
        $db = $this->database->getConnection();
        $primaryKeyProperty = $this->meta->primaryKeyProperty;
        $primaryKeyColumn = $this->meta->primaryKeyColumn;

        if($primaryKeyProperty === null || $this->state === ModelState::New) {
            throw new Exception("Primary key is not set for model ".get_class($this).", or the model was not saved so we can't delete the model");
        }

        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) $db->beginTransaction();
        try {
            // Children of relations declared with cascade: true go first (each child cascades further itself).
            foreach ($this->meta->relations as $key => $info) {
                if (!$info['cascade']) continue;
                $relation = $this->meta->resolveRelation($key);
                if ($relation['targetColumn'] === null) continue;
                $children = $relation['class']::where([$relation['targetColumn'] => $this->$primaryKeyProperty])->fetchAll();
                foreach ($children as $child) {
                    $child->delete();
                }
            }

            $stmt = $db->prepare("DELETE FROM `" . $this->meta->table . "` WHERE `$primaryKeyColumn` = ?");
            $stmt->execute([$this->$primaryKeyProperty]);
            $deleted = $stmt->rowCount() > 0;

            if ($ownsTransaction) $db->commit();
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $this->relationCache = [];
        return $deleted;
    }

    /**
     * Stores an already loaded related object so that the relation getter does not query again
     * (used by QueryBuilder::with() for eager loading; also handy in tests).
     */
    public function setLoadedRelation(string $relationName, mixed $value): void {
        $this->relationCache[$relationName] = $value;
    }

    /**
     * Save the model to database if the model is new it will insert the row, if the model is loaded it will update the row
     */
    public function save(): bool {
        $db = $this->database->getConnection();
        $primaryKeyProperty = $this->meta->primaryKeyProperty;
        $primaryKeyColumn = $this->meta->primaryKeyColumn;

        if($primaryKeyProperty === null) {
            throw new Exception("Primary key is not set for model ".get_class($this)." so we can't save the model");
        }

        $isInsert = $this->$primaryKeyProperty === null || $this->state === ModelState::New;

        $columns = [];
        $values = [];
        foreach ($this->meta->mappings as $property => $column) {
            // The auto-increment primary key is generated by the database; a manual primary key is inserted like any column.
            if ($property === $primaryKeyProperty && ($this->meta->primaryKeyAutoIncrement || !$isInsert)) {
                continue;
            }

            $columns[] = $column;
            $value = $this->$property;

            if (is_object($value) && $value instanceof \UnitEnum) {
                if ($value instanceof \BackedEnum) {
                    $value = $value->value;
                } else {
                    throw new Exception("Enum {$column} has no value (not a backed enum)");
                }
            }

            // MySQL/SQLite have no real boolean type; store booleans as 0/1.
            if (is_bool($value)) {
                $value = (int)$value;
            }

            if (is_string($value) && isset($this->meta->columnsDefinition[$column]['length'])) {
                $maxLength = $this->meta->columnsDefinition[$column]['length'];
                if ($maxLength !== null && mb_strlen($value) > $maxLength) {
                    $value = mb_substr($value, 0, $maxLength);
                }
            }

            $values[] = $value;
        }

        $quotedColumns = array_map(fn($c) => "`$c`", $columns);

        if ($isInsert) {
            $placeholders = array_fill(0, count($columns), '?');
            $stmt = $db->prepare("INSERT INTO `" . $this->meta->table . "` (" . implode(", ", $quotedColumns) . ") VALUES (" . implode(", ", $placeholders) . ")");

            if ($stmt->execute($values)) {
                if ($this->meta->primaryKeyAutoIncrement) {
                    $this->$primaryKeyProperty = (int)$db->lastInsertId();
                }

                $this->state = ModelState::Changed;
                return true;
            }
        } else {
            $setColumns = [];
            foreach ($quotedColumns as $column) {
                $setColumns[] = "$column = ?";
            }
            $stmt = $db->prepare("UPDATE `" . $this->meta->table . "` SET " . implode(", ", $setColumns) . " WHERE `$primaryKeyColumn` = ?");
            $values[] = $this->$primaryKeyProperty;

            $this->state = ModelState::Changed;
            return $stmt->execute($values);
        }

        return false;
    }

    protected function loadRelationship(string $method) {
        if (array_key_exists($method, $this->relationCache)) {
            return $this->relationCache[$method];
        }

        $relation = $this->meta->resolveRelation($method);
        $className = $relation['class'];
        $result = null;

        switch ($relation['type']) {
            case RelationType::HasMany:
                // Returns a QueryBuilder so the caller can keep filtering/ordering/paginating.
                return $className::where([$relation['targetColumn'] => $this->getPrimaryKeyValue()]);

            case RelationType::HasOneInverse:
                $result = $className::where([$relation['targetColumn'] => $this->getPrimaryKeyValue()])->fetch();
                break;

            case RelationType::HasOne:
            case RelationType::BelongsTo:
                $localColumn = $relation['localColumn'];
                $property = $this->meta->mappingsBack[$localColumn] ?? null;
                if ($property === null) {
                    throw new Exception("Foreign key column '$localColumn' is not defined in " . get_class($this) . " for relation $method");
                }
                $foreignValue = $this->$property;
                $result = $foreignValue ? $className::where([$relation['referencedColumn'] => $foreignValue])->fetch() : null;
                break;
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

    public function __call(string $method, array $args) {
        if (isset($this->meta->relations[$method])) {
            return $this->loadRelationship($method);
        }

        throw new Exception("Method $method not found in " . get_class($this));
    }

    /**
     * Describes every column of the model (type, collation, nullability, default, primary key flags).
     * Shared by the CREATE TABLE and the sync (ALTER TABLE) generators.
     *
     * @return array<string, array{type:string, collate:string, nullable:bool, default:string, pk:bool, auto:bool}>
     */
    private static function describeColumns(string $modelClass, bool $sqlite, string $defaultCollation): array {
        $meta = ModelMetadata::for($modelClass);
        $reflection = new ReflectionClass($modelClass);
        $result = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) continue;

            $columnName = $meta->mappings[$property->getName()];
            $length = $meta->columnsDefinition[$columnName]['length'];
            $defaultValue = null;

            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
                $defaultValue = $columnAttributes[0]->newInstance()->defaultValue;
            }

            $type = $property->getType();
            $typeName = $type ? $type->getName() : 'string';

            if ($type && !$type->isBuiltin() && class_exists($typeName)) {
                $refClass = new ReflectionClass($typeName);
                if ($refClass->isEnum()) {
                    $refEnum = new ReflectionEnum($typeName);
                    if ($refEnum->isBacked()) {
                        $typeName = $refEnum->getBackingType()->getName();
                    } else {
                        throw new Exception("Enum {$typeName} is not a backed enum, you need to specify its type");
                    }
                }
            }

            $primaryKey = $property->getName() === $meta->primaryKeyProperty;
            $autoIncrement = $primaryKey && $typeName === 'int' && $meta->primaryKeyAutoIncrement;

            $collate = "";
            if ($typeName === 'string') {
                $sqlType = $length ? "varchar({$length})" : "text";
                if (!$sqlite) $collate = " COLLATE {$defaultCollation}";
            } else {
                switch ($typeName) {
                    case 'int':
                        $sqlType = $sqlite ? 'integer' : ($length !== null ? "int({$length})" : "int");
                        break;
                    case 'float':
                        $sqlType = $sqlite ? 'real' : 'float';
                        break;
                    case 'bool':
                        $sqlType = $sqlite ? 'integer' : 'boolean';
                        break;
                    default:
                        $sqlType = 'text';
                        if (!$sqlite) $collate = " COLLATE {$defaultCollation}";
                }
            }

            $defaultClause = "";
            if ($defaultValue !== null) {
                if ($typeName === 'string') {
                    if ($defaultValue[0] !== "'" && $defaultValue[0] !== '"') {
                        $defaultValue = "'" . $defaultValue . "'";
                    }
                } else if ($typeName === 'bool') {
                    $defaultValue = (strtolower($defaultValue) === 'true' || $defaultValue === '1') ? ($sqlite ? '1' : 'TRUE') : ($sqlite ? '0' : 'FALSE');
                }
                $defaultClause = " DEFAULT " . $defaultValue;
            }

            $result[$columnName] = [
                'type'     => $sqlType,
                'collate'  => $collate,
                'nullable' => !$primaryKey && $type && $type->allowsNull(),
                'default'  => $defaultClause,
                'pk'       => $primaryKey,
                'auto'     => $autoIncrement,
            ];
        }

        return $result;
    }

    /**
     * Builds one column definition. $forAlter drops PRIMARY KEY / AUTO_INCREMENT (primary keys are never altered).
     */
    private static function columnSql(string $name, array $c, bool $sqlite, bool $forAlter = false): string {
        $null = $c['nullable'] ? 'NULL' : 'NOT NULL';
        $default = $c['default'];

        // SQLite cannot ADD a NOT NULL column without a default
        if ($forAlter && $sqlite && !$c['nullable'] && $default === '') {
            $default = in_array($c['type'], ['integer', 'real'], true) ? ' DEFAULT 0' : " DEFAULT ''";
        }

        if ($c['pk'] && !$forAlter) {
            if ($sqlite) {
                // SQLite only auto-generates ids for the exact spelling "INTEGER PRIMARY KEY".
                $sql = "`{$name}` {$c['type']} PRIMARY KEY" . ($c['auto'] ? " AUTOINCREMENT" : "");
            } else {
                $sql = "`{$name}` {$c['type']}{$c['collate']} {$null} PRIMARY KEY" . ($c['auto'] ? " AUTO_INCREMENT" : "");
            }
            return $sql . $default;
        }

        return "`{$name}` {$c['type']}{$c['collate']} {$null}" . $default;
    }

    /**
     * Generates an SQL CREATE TABLE statement based on the model's attributes and properties.
     *
     * @param string $modelClass Fully qualified class name of the model.
     * @param string $defaultCharset Default charset for the table (MySQL only, default: utf8)
     * @param string $defaultCollation Default collation for text-based columns (MySQL only, default: utf8_bin)
     * @param string|null $dialect "mysql" or "sqlite"; defaults to the driver of the active connection (mysql when not connected).
     * @return string The generated SQL statement(s).
     * @throws Exception If the table attribute is not found.
     */
    public static function generateCreateTableQuery(string $modelClass, string $defaultCharset = "utf8mb4", string $defaultCollation = "utf8mb4_bin", ?string $dialect = null): string {
        $dialect = strtolower($dialect ?? Container::getInstance()->get(Database::class)->driver());
        $sqlite = $dialect === 'sqlite';

        $meta = ModelMetadata::for($modelClass);
        $reflection = new ReflectionClass($modelClass);
        $tableName = $meta->table;

        $columns = [];
        $definedColumns = [];

        $described = self::describeColumns($modelClass, $sqlite, $defaultCollation);
        $columns = [];
        $definedColumns = [];
        foreach ($described as $columnName => $c) {
            $columns[] = self::columnSql($columnName, $c, $sqlite);
            $definedColumns[$columnName] = true;
        }

        $keys = [];
        $indexes = [];
        $foreignKeys = [];
        $keysAdded = [];

        // Only HasOne and BelongsTo relations carry a real foreign key column on THIS table,
        // so only those need a FK constraint here (HasMany / HasOneInverse point the other way).
        foreach ($meta->relations as $key => $relationInfo) {
            if (!in_array($relationInfo['type'], [RelationType::HasOne, RelationType::BelongsTo], true)) {
                continue;
            }

            $relation = $meta->resolveRelation($key);
            $relatedTable = ModelMetadata::for($relation['class'])->table;
            $localTableKey = $relation['localColumn'];
            $referencedColumn = $relation['referencedColumn'];

            if (!isset($definedColumns[$localTableKey])) {
                continue;
            }

            if (!isset($keysAdded[$localTableKey])) {
                $indexName = preg_replace('/_id$/', '', $localTableKey);
                if ($indexName === "") {
                    $indexName = $localTableKey;
                }
                if ($sqlite) {
                    $indexes[] = "CREATE INDEX IF NOT EXISTS `{$tableName}_{$indexName}` ON `{$tableName}` (`{$localTableKey}`);";
                } else {
                    $keys[] = "KEY `{$indexName}` (`{$localTableKey}`)";
                }
                $keysAdded[$localTableKey] = true;
            }
            $foreignKeys[] = "CONSTRAINT `{$tableName}_ibfk_{$localTableKey}` FOREIGN KEY (`{$localTableKey}`) REFERENCES `{$relatedTable}` (`{$referencedColumn}`) ON DELETE NO ACTION ON UPDATE NO ACTION";
        }

        $columnsSql = implode(",\n    ", $columns);
        $constraints = array_merge($keys, $foreignKeys);
        $constraintsSql = !empty($constraints) ? ",\n    " . implode(",\n    ", $constraints) : "";

        if ($sqlite) {
            $sql = "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n    {$columnsSql}{$constraintsSql}\n);";
            if (!empty($indexes)) {
                $sql .= "\n" . implode("\n", $indexes);
            }
            return $sql;
        }

        return "CREATE TABLE IF NOT EXISTS `{$tableName}` (\n    {$columnsSql}{$constraintsSql}\n) ENGINE=InnoDB DEFAULT CHARSET={$defaultCharset} COLLATE={$defaultCollation};";
    }

    /**
     * Returns the SQL needed to bring the table in sync with the model:
     *  - table does not exist -> CREATE TABLE
     *  - table exists         -> ALTER TABLE with ADD COLUMN for missing columns and (MySQL only) MODIFY COLUMN for changed ones
     * Columns that exist only in the database are NEVER dropped, they are reported in a comment.
     * Returns an empty string when the table is already up to date.
     */
    public static function generateSyncQuery(string $modelClass, string $defaultCharset = "utf8mb4", string $defaultCollation = "utf8mb4_bin", bool $modifyExisting = true): string {
        $db = Container::getInstance()->get(Database::class);
        $pdo = $db->getConnection();
        $dialect = $db->driver();
        $sqlite = $dialect === 'sqlite';

        $meta = ModelMetadata::for($modelClass);
        $table = $meta->table;

        $existing = self::fetchExistingColumns($pdo, $table, $sqlite);
        if ($existing === []) {
            return self::generateCreateTableQuery($modelClass, $defaultCharset, $defaultCollation, $dialect);
        }

        $described = self::describeColumns($modelClass, $sqlite, $defaultCollation);
        $changes = [];
        $previous = null;

        foreach ($described as $name => $c) {
            if (!isset($existing[$name])) {
                $after = (!$sqlite && $previous !== null) ? " AFTER `{$previous}`" : "";
                $changes[] = $sqlite
                    ? "ALTER TABLE `{$table}` ADD COLUMN " . self::columnSql($name, $c, true, true) . ";"
                    : "ADD COLUMN " . self::columnSql($name, $c, false, true) . $after;
            } elseif ($modifyExisting && !$sqlite && !$c['pk']) {
                $actual = $existing[$name];
                $typeDiffers = self::normalizeType($c['type']) !== self::normalizeType($actual['type']);
                $nullDiffers = $c['nullable'] !== $actual['nullable'];

                if ($typeDiffers || $nullDiffers) {
                    $changes[] = "MODIFY COLUMN " . self::columnSql($name, $c, false, true);
                }
            }
            $previous = $name;
        }

        $sql = "";
        if ($changes !== []) {
            $sql = $sqlite
                ? implode("\n", $changes)
                : "ALTER TABLE `{$table}`\n    " . implode(",\n    ", $changes) . ";";
        }

        // Report (but never drop) columns that are not in the model
        foreach (array_diff_key($existing, $described) as $name => $_) {
            $sql .= "\n-- Column `{$name}` exists in the database but not in the model (not dropped)";
        }

        return trim($sql);
    }

    /**
     * @return array<string, array{type:string, nullable:bool}> empty when the table does not exist
     */
    private static function fetchExistingColumns(PDO $pdo, string $table, bool $sqlite): array {
        $result = [];

        if ($sqlite) {
            $rows = $pdo->query("PRAGMA table_info(`" . str_replace('`', '', $table) . "`)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $result[$row['name']] = ['type' => $row['type'], 'nullable' => (int)$row['notnull'] === 0];
            }
            return $result;
        }

        $stmt = $pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
        );
        $stmt->execute([':t' => $table]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['COLUMN_NAME']] = ['type' => $row['COLUMN_TYPE'], 'nullable' => $row['IS_NULLABLE'] === 'YES'];
        }
        return $result;
    }

    /**
     * Makes the expected type ("boolean", "int(11)") and the real one ("tinyint(1)", "int") comparable.
     */
    private static function normalizeType(string $type): string {
        $type = strtolower(trim($type));
        if ($type === 'boolean' || $type === 'bool') {
            return 'tinyint(1)';
        }
        // MySQL 8 dropped display widths of integer types (int(11) -> int); tinyint(1) is kept on purpose
        return preg_replace('/^(smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
    }

    /**
     * Runs the CREATE TABLE statement(s) of the given model classes on the active connection.
     * Handy for tests, SQLite setups and first-run installers.
     *
     * @param class-string<Model> ...$modelClasses
     */
    public static function createTables(string ...$modelClasses): void {
        $db = self::getConnection();
        foreach ($modelClasses as $class) {
            foreach (array_filter(array_map('trim', explode(";\n", self::generateCreateTableQuery($class)))) as $statement) {
                $db->exec(rtrim($statement, ";"));
            }
        }
    }

    /**
     * Runs the generated sync SQL. Comment lines are skipped, statements are executed one by one.
     * Returns the number of executed statements.
     */
    public static function syncTable(string $modelClass): int {
        $sql = self::generateSyncQuery($modelClass);
        if ($sql === '') {
            return 0;
        }

        $pdo = Container::getInstance()->get(Database::class)->getConnection();

        // Drop "-- ..." comment lines, then split by ";" at the end of a line
        $lines = array_filter(
            explode("\n", $sql),
            fn(string $l) => !str_starts_with(ltrim($l), '--')
        );
        $statements = array_filter(array_map('trim', preg_split('/;\s*(\n|$)/', implode("\n", $lines))));

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        return count($statements);
    }

    public static function syncTables(string ...$modelClasses): int {
        $count = 0;
        foreach ($modelClasses as $class) {
            $count += self::syncTable($class);
        }
        return $count;
    }
}

class ModelListResult {
    public QueryBuilder $query;
    public Paginator $paginator;
}

enum DataTableLike: int {
    case Both = 1;
    case Left = 2;
    case Right = 3;
}

class QueryBuilderBindingGenerator {
    private $bindCounter = 0;

    public function __construct(){
        
    }

    public function generateBindName() {
        $this->bindCounter++;
        return ":param" . $this->bindCounter;
    }
}

class QueryBuilderWhere {
    private array $conditions = [];
    private QueryBuilderBindingGenerator $bindingGenerator;

    public function __construct(QueryBuilderBindingGenerator $bindingGenerator) {
        $this->bindingGenerator = $bindingGenerator;
    }

    /**
     * @param string|array|Closure(\QueryBuilderWhere): \QueryBuilderWhere $condition
     */
    public function and(string|array|callable $condition, $params = []): self {
        $this->add('AND', $condition, $params);
        return $this;
    }

    /**
     * @param string|array|Closure(\QueryBuilderWhere): \QueryBuilderWhere $condition
     */
    public function or(string|array|callable $condition, $params = []): self {
        $this->add('OR', $condition, $params);
        return $this;
    }

    /**
     * @param string|array|Closure(\QueryBuilderWhere): \QueryBuilderWhere $condition
     */
    private function add(string $operator, string|array|callable $condition, $params = []): void {
        $prefix = empty($this->conditions) ? '' : $operator;

        if (is_callable($condition)) {
            $subWhere = new self($this->bindingGenerator);
            $condition($subWhere);
            
            $this->conditions[] = [
                'type' => $prefix,
                'nested' => $subWhere
            ];
            return;
        }

        if (is_array($condition)) {
            foreach ($condition as $key => $val) {
                $bindName = $this->bindingGenerator->generateBindName();
                $compare = ($val === null) ? "IS" : "=";
                
                $last2part = substr($key, -2);
                $last1part = substr($key, -1);
                if (in_array($last2part, ["<>", "!=", "<=", ">="], true)) {
                    $compare = $last2part;
                    $key = trim(substr($key, 0, -2));
                } elseif (in_array($last1part, [">", "<"], true)) {
                    $compare = $last1part;
                    $key = trim(substr($key, 0, -1));
                }

                $this->conditions[] = [
                    'type' => $prefix,
                    'column' => $key,
                    'operator' => $compare,
                    'bindName' => $bindName,
                    'value' => $val
                ];
                $prefix = $operator;
            }
            return;
        }

        if (is_string($condition) && !preg_match('/[=><!]|LIKE/i', $condition) && ($params !== [] || $params === null) && !is_array($params)) {
            $bindName = $this->bindingGenerator->generateBindName();
            $this->conditions[] = [
                'type' => $prefix,
                'column' => $condition,
                'operator' => ($params === null) ? 'IS' : '=',
                'bindName' => $bindName,
                'value' => $params
            ];
            return;
        }

        $binds = [];
        if (!is_array($params) && is_string($condition) && str_contains($condition, ':')) {
            if (preg_match('/:\w+/', $condition, $m)) {
                $binds[$m[0]] = $params;
            }
        } elseif (is_array($params) && !empty($params)) {
            $binds = $params;
        } else {
            $regex = '/(\w+)\s*(=|>|<|>=|<=|!=)\s*(\d+|\'[^\']*\'|"[^"]*")/';
            if (preg_match_all($regex, $condition, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $column = $match[1];
                    $op = $match[2];
                    $valRaw = trim($match[3], '\'"');
                    $bindName = $this->bindingGenerator->generateBindName();
                    $condition = str_replace($match[0], "$column $op $bindName", $condition);
                    $binds[$bindName] = is_numeric($valRaw) ? (float)$valRaw : $valRaw;
                }
            }
        }

        $this->conditions[] = [
            'type' => $prefix,
            'raw' => $condition,
            'binds' => $binds
        ];
    }

    public function like(string $column, string $value, DataTableLike $type = DataTableLike::Both, bool $isOr = false): self {
        $mask = match($type) {
            DataTableLike::Right => "$value%",
            DataTableLike::Left => "%$value",
            default => "%$value%"
        };
        
        $bindName = $this->bindingGenerator->generateBindName();
        $condition = "$column LIKE $bindName";
        
        if ($isOr) {
            $this->or($condition, [$bindName => $mask]);
        } else {
            $this->and($condition, [$bindName => $mask]);
        }

        return $this;
    }

    public function likeOr(string $column, string $value, DataTableLike $type = DataTableLike::Both): self {
        return $this->like($column, $value, $type, true);
    }

    public function in(string $column, array $values, bool $isOr = false): self {
        return $this->addInCondition('IN', $column, $values, $isOr);
    }

    public function inOr(string $column, array $values): self {
        return $this->in($column, $values, true);
    }

    public function notIn(string $column, array $values, bool $isOr = false): self {
        return $this->addInCondition('NOT IN', $column, $values, $isOr);
    }

    public function notInOr(string $column, array $values, bool $isOr = false): self {
        return $this->notIn($column, $values, true);
    }

    private function addInCondition(string $operator, string $column, array $values, bool $isOr): self {
        $binds = [];
        $placeholders = [];
        
        foreach ($values as $value) {
            $bindName = $this->bindingGenerator->generateBindName();
            $placeholders[] = $bindName;
            $binds[$bindName] = $value;
        }

        $prefix = empty($this->conditions) ? '' : ($isOr ? 'OR' : 'AND');

        $this->conditions[] = [
            'type' => $prefix,
            'column' => $column,
            'operator' => $operator,
            'placeholders' => $placeholders,
            'values' => $binds
        ];

        return $this;
    }

    public function getConditions(): array {
        return $this->conditions;
    }

    public function buildSql(): string {
        $sql = "";
        foreach ($this->conditions as $cond) {
            $prefix = $cond['type'] !== '' ? " ".$cond['type']." " : "";
            
            if (isset($cond['nested'])) {
                $nestedSql = $cond['nested']->buildSql();
                if ($nestedSql !== '') {
                    $sql .= $prefix . "(" . $nestedSql . ")";
                }
            } elseif (isset($cond['operator']) && ($cond['operator'] === 'IN' || $cond['operator'] === 'NOT IN')) {
                $sql .= $prefix . $cond['column'] . " " . $cond['operator'] . " (" . implode(', ', $cond['placeholders']) . ")";
            } elseif (isset($cond['column'])) {
                $sql .= $prefix . $cond['column'] . " ".$cond['operator']." ".$cond['bindName'];
            } elseif (isset($cond['raw'])) {
                $sql .= $prefix . $cond['raw'];
            }
        }
        return $sql;
    }

    public function getAllBindings(): array {
        $allBinds = [];

        foreach ($this->conditions as $cond) {
            if (isset($cond['nested'])) {
                $allBinds = array_merge($allBinds, $cond['nested']->getAllBindings());
            } elseif (isset($cond['operator']) && ($cond['operator'] === 'IN' || $cond['operator'] === 'NOT IN')) {
                $allBinds = array_merge($allBinds, $cond['values']);
            } elseif (isset($cond['column'])) {
                $allBinds[$cond['bindName']] = $cond['value'];
            } elseif (isset($cond['raw']) && !empty($cond['binds'])) {
                $allBinds = array_merge($allBinds, $cond['binds']);
            }
        }

        return $allBinds;
    }
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
    /** @var array<string,true> relation names to eager-load */
    private array $with = [];
    private QueryBuilderBindingGenerator $bindingGenerator;
    private QueryBuilderWhere $whereBuilder;
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
        $this->bindingGenerator = new QueryBuilderBindingGenerator();
        $this->whereBuilder = new QueryBuilderWhere($this->bindingGenerator);
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function table(string $name): self {
		$this->table = $name;
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function having(): self{
		$this->having[] = array("value" => func_get_args());
		return $this;
	}

    /**
     * @param string|array|Closure(\QueryBuilderWhere): \QueryBuilderWhere $condition
     * 
     * @return \QueryBuilder<T>
     */
    public function where(string|array|callable $condition, $params = []): self {
        $this->whereBuilder->and($condition, $params);
        return $this;
    }

    /**
     * @param string|array|Closure(\QueryBuilderWhere): \QueryBuilderWhere $condition
     * 
     * @return \QueryBuilder<T>
     */
	public function whereOr(string|array|callable $condition, $params = []): self {
        $this->whereBuilder->or($condition, $params);
        return $this;
	}

	/**
     * replace {table} as name of original table
     * {join} as table name of join
     * 
     * @return \QueryBuilder<T>
     */
    public function join(string $table, string $condition, string $name = ""): self {
		if($name == "") $name = $table;
        $this->joins[] = array("type" => "LEFT", "table" => $table, "name" => $name, "condition" => $condition);
        return $this;
    }

	/**
	 * DataTableLike::$BOTH = 0
	 * DataTableLike::$LEFT = 1
	 * DataTableLike::$RIGHT = 2
     * 
     * @return \QueryBuilder<T>
     */
	public function like(string $column, string $value, DataTableLike $type = DataTableLike::Both, bool $isOr = false): self {
        $this->whereBuilder->like($column, $value, $type, $isOr);
        return $this;
    }

    /**
     * @return \QueryBuilder<T>
     */
	public function likeOr(string $column, string $value, DataTableLike $type = DataTableLike::Both): self {
        $this->whereBuilder->like($column, $value, $type, true);
        return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
    public function in(string $column, array $values): self {
        $this->whereBuilder->in($column, $values);
        return $this;
    }

    /**
     * @return \QueryBuilder<T>
     */
    public function inOr(string $column, array $values): self {
        $this->whereBuilder->in($column, $values, true);
        return $this;
    }

    /**
     * @return \QueryBuilder<T>
     */
    public function notIn(string $column, array $values): self {
        $this->whereBuilder->notIn($column, $values);
        return $this;
    }

    /**
     * @return \QueryBuilder<T>
     */
    public function notInOr(string $column, array $values): self {
        $this->whereBuilder->notIn($column, $values, true);
        return $this;
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

    /**
     * @return T[]
     */
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
            $this->eagerLoad($objects);
            return $objects;
        } else {
            return $rows;
        }
	}

    /**
     * Eager-loads relations for the fetched objects to avoid the N+1 query problem:
     *   Article::toQuery()->with('author')->fetchAll()   // one query for the articles, one for all authors
     * Supports HasOne, BelongsTo and HasOneInverse relations (HasMany getters return a QueryBuilder by design).
     *
     * @return \QueryBuilder<T>
     */
    public function with(string ...$relations): self {
        foreach ($relations as $relation) {
            $this->with[$relation] = true;
        }
        return $this;
    }

    /** @param Model[] $objects */
    private function eagerLoad(array $objects): void {
        if (!$this->className || $objects === [] || $this->with === []) return;

        $meta = ModelMetadata::for($this->className);
        foreach (array_keys($this->with) as $name) {
            $relation = $meta->resolveRelation($name);
            $targetClass = $relation['class'];
            $targetMeta = ModelMetadata::for($targetClass);

            switch ($relation['type']) {
                case RelationType::HasOne:
                case RelationType::BelongsTo:
                    $localProperty = $meta->mappingsBack[$relation['localColumn']] ?? null;
                    if ($localProperty === null) {
                        throw new Exception("Foreign key column '{$relation['localColumn']}' is not defined in {$this->className} for relation $name");
                    }
                    $keys = [];
                    foreach ($objects as $object) {
                        if ($object->$localProperty) $keys[$object->$localProperty] = true;
                    }
                    $related = $keys === [] ? [] : $targetClass::toQuery()->in($relation['referencedColumn'], array_keys($keys))->fetchAll();
                    $referencedProperty = $targetMeta->mappingsBack[$relation['referencedColumn']] ?? $relation['referencedColumn'];
                    $byKey = [];
                    foreach ($related as $item) {
                        $byKey[$item->$referencedProperty] = $item;
                    }
                    foreach ($objects as $object) {
                        $object->setLoadedRelation($name, $byKey[$object->$localProperty] ?? null);
                    }
                    break;

                case RelationType::HasOneInverse:
                    $ids = [];
                    foreach ($objects as $object) {
                        $pk = $object->getPrimaryKeyValue();
                        if ($pk !== null) $ids[$pk] = true;
                    }
                    $related = $ids === [] ? [] : $targetClass::toQuery()->in($relation['targetColumn'], array_keys($ids))->fetchAll();
                    $targetProperty = $targetMeta->mappingsBack[$relation['targetColumn']] ?? $relation['targetColumn'];
                    $byKey = [];
                    foreach ($related as $item) {
                        $byKey[$item->$targetProperty] ??= $item;
                    }
                    foreach ($objects as $object) {
                        $object->setLoadedRelation($name, $byKey[$object->getPrimaryKeyValue()] ?? null);
                    }
                    break;

                default:
                    throw new Exception("with('$name'): eager loading supports HasOne, BelongsTo and HasOneInverse relations; '$name' is {$relation['type']->value}");
            }
        }
    }

    /**
     * Deletes the rows matching the WHERE conditions and returns how many were removed.
     * Refuses to run without a condition; use deleteAll() to empty a table on purpose.
     */
    public function delete(): int {
        $whereText = $this->whereBuilder->buildSql();
        if ($whereText === '') {
            throw new Exception("QueryBuilder::delete() needs at least one where() condition; call deleteAll() to delete every row");
        }
        return $this->executeWrite("DELETE FROM `" . $this->table . "` WHERE " . $whereText, $this->whereBuilder->getAllBindings());
    }

    public function deleteAll(): int {
        return $this->executeWrite("DELETE FROM `" . $this->table . "`", []);
    }

    /**
     * Bulk UPDATE of the matching rows, e.g. Article::where(['status' => 'draft'])->update(['status' => ArticleStatus::Published]).
     * Keys are column names; enums are stored by value and booleans as 0/1.
     */
    public function update(array $values): int {
        if ($values === []) return 0;
        $whereText = $this->whereBuilder->buildSql();
        if ($whereText === '') {
            throw new Exception("QueryBuilder::update() needs at least one where() condition");
        }

        $set = [];
        $binds = [];
        $i = 0;
        foreach ($values as $column => $value) {
            if ($value instanceof \BackedEnum) $value = $value->value;
            if (is_bool($value)) $value = (int)$value;
            $bind = ":set" . (++$i);
            $set[] = "`$column` = $bind";
            $binds[$bind] = $value;
        }

        return $this->executeWrite("UPDATE `" . $this->table . "` SET " . implode(", ", $set) . " WHERE " . $whereText, $binds + $this->whereBuilder->getAllBindings());
    }

    private function executeWrite(string $sql, array $binds): int {
        $stmt = $this->connection->prepare($sql);
        foreach ($binds as $name => $value) {
            $stmt->bindValue($name, $value, Database::getPdoParamType($value));
        }
        $stmt->execute();
        $this->debugCapture($stmt, "write");
        return $stmt->rowCount();
    }

    /**
     * @return T|null Returns an instance of the specified class (or the raw row when no model class is set) or null if the record does not exist.
     */
    public function fetch(): Model|array|null {
        $buildData = $this->buildSql(false);
		$stmt = $this->connection->prepare($buildData["sql"]);
        foreach ($buildData["binds"] as $name => $value) {
            $stmt->bindValue($name, $value, Database::getPdoParamType($value));
        }        

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->debugCapture($stmt, "querySingle");

        if ($this->className) {
            if (!$result) return null;
            $object = new $this->className($result);
            $this->eagerLoad([$object]);
            return $object;
        } else {
            return $result === false ? null : $result;
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

    /**
     * @return \QueryBuilder<T>
     */
	public function order(string $value): self {
		$this->orderBy = $value;
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function limit(int $value): self {
		$this->pageLimit = $value;
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function page(int $value): self {
		$this->currentPage = $value;
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function takAllFields(): self {
		$this->takeAllFieldsFromTable = true;
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function item($value, $name = ""): self {
		$this->items[] = array("value" => $value, "name" => $name, "must" => false);
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
	public function itemMust($value, $name): self {
		$this->items[] = array("value" => $value, "name" => $name, "must" => true);
		return $this;
	}

    /**
     * @return \QueryBuilder<T>
     */
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

        $sql[] = "FROM `" . $this->table . "`";

        foreach ($this->joins as $join) {
            $condition = str_replace(
                ["{table}", "{join}"],
                [$this->table, $join["name"]],
                $join["condition"]
            );
            $sql[] = "{$join["type"]} JOIN {$join["table"]} AS {$join["name"]} ON $condition";
        }

        $whereText = $this->whereBuilder->buildSql();
        if($whereText != null && strlen($whereText) > 0) {
            $sql[] = "WHERE " . $whereText;
            $binds = array_merge($binds, $this->whereBuilder->getAllBindings());
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

if(USE_USERS) {
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
        /** Too many failed attempts for this login or IP address; try again later (see LoginThrottle). */
        case Blocked;
    }
    /**
     * Brute-force protection for UserService::login(): after MAX_ATTEMPTS failed logins for the same
     * login name or the same IP address within WINDOW seconds, further attempts are rejected until the
     * window has passed. Failures are stored in the `login_attempts` table (Models\LoginAttempt);
     * when that model does not exist the throttle is disabled.
     */
    class LoginThrottle {
        /** Failed attempts allowed per login name within the window. */
        public const MAX_ATTEMPTS = 5;
        /** Failed attempts allowed per IP address within the window (higher: offices and NAT share one address). */
        public const MAX_ATTEMPTS_PER_IP = 20;
        public const WINDOW = 900;

        private ?bool $enabled = null;

        public function __construct(
            private int $maxAttempts = self::MAX_ATTEMPTS,
            private int $windowSeconds = self::WINDOW,
            private int $maxAttemptsPerIp = self::MAX_ATTEMPTS_PER_IP,
        ) {}

        public function isEnabled(): bool {
            return $this->enabled ??= class_exists(Models\LoginAttempt::class);
        }

        /** Test helper / opt-out: LoginThrottle::disable() */
        public function setEnabled(bool $enabled): void {
            $this->enabled = $enabled && class_exists(Models\LoginAttempt::class);
        }

        /**
         * @return \QueryBuilder<\Models\LoginAttempt>
         */
        private function recentFailures(string $login, string $ip): QueryBuilder {
            return Models\LoginAttempt::where(fn(QueryBuilderWhere $w) => $w->and(['login' => $login])->or(['ip' => $ip]))
                ->where(['created >' => time() - $this->windowSeconds]);
        }

        public function isBlocked(string $login, string $ip): bool {
            if (!$this->isEnabled()) return false;
            $since = time() - $this->windowSeconds;
            if (Models\LoginAttempt::where(['login' => $login, 'created >' => $since])->count() >= $this->maxAttempts) {
                return true;
            }
            return Models\LoginAttempt::where(['ip' => $ip, 'created >' => $since])->count() >= $this->maxAttemptsPerIp;
        }

        /** Seconds until a blocked login/IP may try again (0 when not blocked). */
        public function retryAfter(string $login, string $ip): int {
            if (!$this->isBlocked($login, $ip)) return 0;
            $newest = $this->recentFailures($login, $ip)->order("created DESC")->fetch();
            return $newest === null ? 0 : max(1, $newest->created + $this->windowSeconds - time());
        }

        public function recordFailure(string $login, string $ip): void {
            if (!$this->isEnabled()) return;
            $attempt = new Models\LoginAttempt();
            $attempt->login = mb_substr($login, 0, 100);
            $attempt->ip = mb_substr($ip, 0, 45);
            $attempt->created = time();
            $attempt->save();

            // Opportunistic cleanup of attempts outside the window so the table stays small.
            if (random_int(1, 20) === 1) {
                Models\LoginAttempt::where(['created <' => time() - $this->windowSeconds])->delete();
            }
        }

        public function clear(string $login): void {
            if (!$this->isEnabled()) return;
            Models\LoginAttempt::where(['login' => $login])->delete();
        }
    }

    class UserService {
        private Router $router;
        private AuthentificatorProvider $authentificatorProvider;
        private LoginThrottle $throttle;

        private ?Models\User $currentUser = null;
        private bool $currentUserResolved = false;
        /** Session value the cached user was loaded for; a different cookie value invalidates the cache. */
        private ?string $currentUserKey = null;

        public function __construct(Router $router, AuthentificatorProvider $authentificatorProvider, LoginThrottle $throttle) {
            $this->authentificatorProvider = $authentificatorProvider;
            $this->router = $router;
            $this->throttle = $throttle;
        }

        public function getThrottle(): LoginThrottle {
            return $this->throttle;
        }

        /**
         * Permission level of the signed-in user (0 for anonymous visitors or users without a permission row).
         */
        public function currentLevel(): int {
            $user = $this->current();
            if ($user === null) return 0;
            $permission = $user->getPermission();
            return $permission === null ? 0 : (int)$permission->level;
        }

        public function can(int $level): bool {
            return $this->currentLevel() >= $level;
        }

        /** Forget the cached current user (after login/logout or when the user row changed). */
        public function refreshCurrent(): void {
            $this->currentUser = null;
            $this->currentUserResolved = false;
        }

        /** Minimum accepted password length for register() and the password reset flow. */
        public const MIN_PASSWORD_LENGTH = 8;

        public function check(string $login, string $email): UserServiceCheck {
            if(!Utilities::isEmail($email)) return UserServiceCheck::EmailInvalid;

            $user = Models\User::find($login, $email);
            if($user == null) return UserServiceCheck::Ok;

            if($user->login == $login) return UserServiceCheck::LoginExists;
            if($user->email == $email) return UserServiceCheck::EmailExists;

            return UserServiceCheck::Unknown;
        }

        public function isPasswordAcceptable(string $password): bool {
            return mb_strlen($password) >= self::MIN_PASSWORD_LENGTH;
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

            if($state == UserServiceCheck::Ok && !$this->isPasswordAcceptable($password)) {
                return UserServiceCheck::PasswordBad;
            }

            if($state == UserServiceCheck::Ok) {
                $user = new Models\User();
                $user->login = $login;
                $user->password = $this->hashPassword($password);
                $user->email = $email;
                $user->permissionId = \Models\Permission::LEVEL_USER;
                
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
            return $this->current() !== null;
        }

        public function login(string $login, string $password, ?callable $callback = null): UserServiceLogin | string {
            $ip = Utilities::ip();
            if ($this->throttle->isBlocked($login, $ip)) {
                return UserServiceLogin::Blocked;
            }

            $user = Models\User::find($login, $login);

            if($user == null) {
                $this->throttle->recordFailure($login, $ip);
                return UserServiceLogin::WrongLogin;
            }
            if(!$this->verifyPassword($password, $user->password)) {
                $this->throttle->recordFailure($login, $ip);
                return UserServiceLogin::WrongPassword;
            }
            if($callback != null) {
                $result = $callback($user);
                if($result !== true) {
                    return $result;
                }
            }

            // Transparently upgrade legacy (sha1) or outdated hashes now that we know the plain password.
            if ($this->passwordNeedsRehash($user->password)) {
                $user->password = $this->hashPassword($password);
                $user->save();
            }

            $this->throttle->clear($login);

            $auth = $this->authentificatorProvider->get();
            $auth->authenticate($user->id);
            $this->refreshCurrent();

            return UserServiceLogin::Ok;
        }

        /**
         * Hashes a password with PHP's password_hash (bcrypt today, whatever PASSWORD_DEFAULT is tomorrow).
         * Older releases stored unsalted sha1 hashes; verifyPassword() still accepts those and login() upgrades them.
         */
        public function hashPassword(string $password): string {
            return password_hash($password, PASSWORD_DEFAULT);
        }

        public function verifyPassword(string $password, string $storedHash): bool {
            if ($storedHash === '') return false;

            if (self::isLegacyHash($storedHash)) {
                return hash_equals($storedHash, sha1($password));
            }

            return password_verify($password, $storedHash);
        }

        public function passwordNeedsRehash(string $storedHash): bool {
            return self::isLegacyHash($storedHash) || password_needs_rehash($storedHash, PASSWORD_DEFAULT);
        }

        private static function isLegacyHash(string $storedHash): bool {
            return (bool)preg_match('/^[a-f0-9]{40}$/i', $storedHash);
        }

        public function logout() {
            $auth = $this->authentificatorProvider->get();
            $auth->deauthenticate();
            $this->refreshCurrent();
        }

        /**
         * The signed-in user, loaded once per request (previously every call ran two queries).
         */
        public function current(): ?Models\User {
            $auth = $this->authentificatorProvider->get();
            $key = $auth->isAuthenticated() ? (string)$auth->getValue() : null;

            if ($this->currentUserResolved && $this->currentUserKey === $key) {
                return $this->currentUser;
            }

            $this->currentUserResolved = true;
            $this->currentUserKey = $key;
            $this->currentUser = ($key !== null && $key !== '') ? Models\User::findById($key) : null;

            return $this->currentUser;
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
            $user = null;
            $ticket = TicketService::findByToken($token);
            if($ticket == null) return UserServiceCheck::WrongToken;
            if($ticket->type->value != Models\TicketType::PasswordReset->value) return UserServiceCheck::WrongToken;
            // A ticket is single-use and expires: without these checks a leaked reset link stayed valid forever.
            if($ticket->status !== Models\TicketStatus::New) return UserServiceCheck::WrongToken;
            if(TicketService::isExpired($ticket, TicketService::PASSWORD_RESET_TTL)) return UserServiceCheck::WrongToken;

            $user = Models\User::findById($ticket->entityId);
            if($user == null) return UserServiceCheck::WrongToken;

            return UserServiceCheck::Ok;
        }

        public function changePasswordByTicket(string $token, string $password): UserServiceCheck {
            /** @var ?Models\User $user */
            $user = null;
            /** @var ?Models\Ticket $ticket */
            $ticket = null;

            $state = $this->checkResetPasswordTicket($token, $user, $ticket);
            if($state != UserServiceCheck::Ok) {
                return $state;
            }

             if ($user === null || $ticket === null) {
                return UserServiceCheck::WrongToken;
            }

            if (!$this->isPasswordAcceptable($password)) {
                return UserServiceCheck::PasswordBad;
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
    /** How long a password reset link stays valid (seconds). */
    public const PASSWORD_RESET_TTL = 3600;
    /** Length of generated tokens (alphanumeric, ~190 bits of entropy). */
    public const TOKEN_LENGTH = 32;

    public static function isExpired(Models\Ticket $ticket, int $ttlSeconds): bool {
        return $ticket->created < time() - $ttlSeconds;
    }

    public static function createTicket(Models\TicketType $type, ?int $entityId = null, ?string $data = null): Models\Ticket {
        $ticket = new Models\Ticket();
        $ticket->token = hash('sha256', Utilities::random(self::TOKEN_LENGTH));
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

        $host = strtolower($_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/:\d+$/', '', $host);
        if (!preg_match('/^[a-z0-9.-]+$/', $host)) {
            $host = 'localhost';
        }

        if(empty($fromEmail)) {
            $fromEmail = "no-reply@" . $host;
        }
        if(empty($fromName)) {
            $fromName = $host;
        }
        $fromEmail = str_replace(["\r", "\n"], '', $fromEmail);
        $fromName = str_replace(["\r", "\n"], '', $fromName);

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
	}

    /**
	 * Disables TLS certificate verification (self-signed certificates in development). Off by default since
	 * an unverified HTTPS connection can be intercepted.
	 */
	public function insecure(bool $insecure = true): self {
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, !$insecure);
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, $insecure ? 0 : 2);
		return $this;
	}

	/**
	 * Connection / total timeouts in seconds (defaults: 10 / 30). Without a timeout a stalled remote host stalls this request too.
	 */
	public function setTimeout(int $seconds, ?int $connectSeconds = null): self {
		curl_setopt($this->curl, CURLOPT_TIMEOUT, $seconds);
		curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, $connectSeconds ?? min(10, $seconds));
		return $this;
	}

	public function init(){
		$this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($this->curl, CURLOPT_TIMEOUT, 30);
		curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 10);
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
	
		$port = isset($urlParts['port']) ? ':' . $urlParts['port'] : '';
		$url = ($urlParts['scheme'] ?? 'http') . '://' . ($urlParts['host'] ?? 'localhost') . $port . ($urlParts['path'] ?? '/');
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

		return (int)($this->lastCurlDebug['http_code'] ?? 0);
	}

	public function isError(): bool {
		if(!$this->executed) return false;

		if ($this->lastErrno !== 0 || $this->response === false) {
			return true;
		}

		return $this->getResponseStatusCode() !== $this->expectedStatus;
	}
}

class Request {
    private array $headers = [];

    public function __construct(){
        // getallheaders() is only available under a web SAPI; rebuild the headers from $_SERVER otherwise (CLI, tests).
        if (function_exists('getallheaders') && PHP_SAPI !== 'cli') {
            $this->headers = getallheaders() ?: [];
        } else {
            $this->headers = [];
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                    $this->headers[$name] = $value;
                }
            }
        }
    }

    public static function setSecureHeaders(): void {
        header("X-Frame-Options: DENY");        
        header("X-Content-Type-Options: nosniff");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
        header("Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
        header_remove('X-Powered-By');

        if (Request::isHttps()) {
            // Start with a short max-age, raise it (e.g. to 31536000) once everything works over HTTPS
            header('Strict-Transport-Security: max-age=300; includeSubDomains');
        }

        if(defined('USE_USERS') && USE_USERS) {
            $userService = Container::getInstance()->get(\UserService::class);
            if ($userService->isAuthentificated()) {
                header("Cache-Control: private, no-store");
            }
        }
    }

    /**
     * Case-insensitive header lookup ("X-CSRF-Token" and "x-csrf-token" are the same header).
     */
    public function headerIgnoreCase(string $key): ?string {
        foreach ($this->headers as $name => $value) {
            if (strcasecmp($name, $key) === 0) return $value;
        }
        return null;
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
        $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if(is_string($type))
            return $method === trim(strtolower($type));
        
        if($type == Method::GET)
            return $method === "get" || $method === "head"; //support for head/get
        if($type == Method::POST)
            return $method === "post";
        if($type == Method::PUT)
            return $method === "put";
        if($type == Method::DELETE)
            return $method === "delete";
        if($type == Method::HEAD)
            return $method === "head";

        throw new Exception("Unknown method type = {$type->name}");
    }

    public function method(): Method {
        if($this->is(Method::GET)) return Method::GET;
        if($this->is(Method::POST)) return Method::POST;
        if($this->is(Method::PUT)) return Method::PUT;
        if($this->is(Method::DELETE)) return Method::DELETE;
        if($this->is(Method::HEAD)) return Method::HEAD;

        throw new Exception("Unknown method type");
    }

    public function isAjax(): bool {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }

    public function isJsonRequest(): bool {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return strpos($contentType, 'application/json') !== false || 
               strpos($accept, 'application/json') !== false || 
               $this->isAjax();
    }

    public function isHead(): bool {
        return $this->is(Method::HEAD);
    }

    public function isSecure(): bool {
        return self::isHttps();
    }

    public static function isHttps(): bool {
        return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', Utilities::trustedProxies(), true));
    }

    private ?string $rawBody = null;

    private function getRawBody(): string {
        // Cache the body so php://input is read only once
        return $this->rawBody ??= (string)file_get_contents('php://input');
    }

    /**
     * Returns true only when the media type is exactly "application/json"
     * and the body is really valid JSON (object or array).
     */
    public function hasJsonBody(): bool {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        // Keep only the media type (essence), drop parameters like "; charset=utf-8"
        $essence = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($essence !== 'application/json') {
            return false;
        }

        $raw = $this->getRawBody();
        if ($raw === '') {
            return false;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        // Scalars like "1" or "true" are valid JSON, but not a real JSON request body
        return is_array($decoded);
    }

    /**
     * @param bool $associative
     * @return mixed|null
     */
    public function getJson(bool $associative = true): mixed {
        $input = $this->getRawBody();

        if (empty($input)) {
            return null;
        }

        try {
            $data = json_decode($input, $associative, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (\JsonException $e) {
            return null;
        }
    }

    public static function sessionStart(): void {
        if (session_status() === PHP_SESSION_NONE) {
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');

            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();
        }
    }

    public static function getSessionId(): string {
        if (session_status() === PHP_SESSION_NONE) {
            Request::sessionStart();
        }

        $id = session_id();
        if ($id === '' || $id === false) {
            throw new \RuntimeException('Session is not available.');
        }
        return $id;
    }
}

class Response {
    private Request $request;
    
    public const HTTP_OK = 200;
    public const HTTP_BAD_REQUEST = 400;
    public const HTTP_UNAUTHORIZED = 401;
    public const HTTP_FORBIDDEN = 403;
    public const HTTP_NOT_FOUND = 404;
    public const HTTP_METHOD_NOT_ALLOWED = 405;
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
    case RedirectToRoute;
}

class ControllerMethodNotAllowedException extends HttpException {
    private Method $method;

    public function __construct(Method $method) {
        parent::__construct(Response::HTTP_METHOD_NOT_ALLOWED, "Method Not Allowed");
        $this->method = $method;
    }

    public function getMethod(): Method {
        return $this->method;
    }
}

class CsrfTokenMismatchException extends HttpException {
    public function __construct() {
        parent::__construct(Response::HTTP_FORBIDDEN, "Invalid or missing CSRF token");
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
        return strtolower(str_replace('\\', '/', $class));
    }

    public function shouldClearContent(): bool {
        return (($this->type == ControllerActionType::View && $this->params["clear"]) || $this->type == ControllerActionType::Json);
    }

    public function getView(): string {
        if($this->type != ControllerActionType::View) throw new Exception("getView can be called only for View type");
        return $this->params["view"];
    }

    public function getModel(): array | object {
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

    public function getRedirectRoute(): string {
        if($this->type != ControllerActionType::RedirectToRoute) throw new Exception("getRedirectRoute can be called only for Redirect type");
        return $this->params["route"];
    }

    public function getRedirectRouteParams(): array {
        if($this->type != ControllerActionType::RedirectToRoute) throw new Exception("getRedirectRouteParams can be called only for Redirect type");
        return $this->params["params"];
    }

    public static function makeViewModel(string $class, string $view, array|null|object $model, bool $clearContent): ControllerAction {
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

    public static function makeRedirectToRouteModel(string $routeName, array $params = []): ControllerAction {
        $viewModel = new ControllerAction();
        $viewModel->params = [
            "route" => $routeName,
            "params" => $params
        ];
        $viewModel->type = ControllerActionType::RedirectToRoute;
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

    protected function redirectToRoute(string $routeName, array $params = []): ControllerAction {
        return ControllerAction::makeRedirectToRouteModel($routeName, $params);
    }

    /**
     * Stops the action with an HTTP error status (403, 404, ...). The router renders a plain response.
     */
    protected function abort(int $status, string $message = ""): never {
        throw new HttpException($status, $message);
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
    private ?string $authenticationUrl = null;
    private bool $needAuthentication = false;

    public static function registerConfig(ConfigProvider $configProvider): void {
        $configProvider->register([
                new ConfigType('AUTH_TYPE', true, false, ConfigBasicValidator::enum("cookies")), 
                new ConfigType('AUTH_COOKIE_NAME', false), 
                new ConfigType('AUTH_COOKIE_EXPIRATION', false, false, ConfigBasicValidator::create(ConfigValidatorType::PositiveDateOffset)),
                new ConfigType('AUTH_URL', false),
                new ConfigType('AUTH_REQUIRED', false, false, ConfigBasicValidator::create(ConfigValidatorType::Boolean))
            ],
            function(array $cfg) {
                $authentificatorProvider = Container::getInstance()->get(AuthentificatorProvider::class);
                
                $type = $cfg["AUTH_TYPE"];
                if($type == "cookies") {
                    $auth = (new Authentication())
                        ->setAuthenticationMethods([
                            'cookies' => new CookieAuthentication('session', isset($cfg["AUTH_COOKIE_EXPIRATION"]) && $cfg["AUTH_COOKIE_EXPIRATION"] != ""? $cfg["AUTH_COOKIE_EXPIRATION"]: '+1 day')
                        ])
                        ->setDefaultAuthenticationMethod('cookies')
                        ->setAuthenticationUrl(isset($cfg["AUTH_URL"]) && $cfg["AUTH_URL"] != ""?  $cfg["AUTH_URL"]: "login/")
                        ->requireAuthentication(isset($cfg["AUTH_REQUIRED"]) && $cfg["AUTH_REQUIRED"] != ""? Config::parseBool($cfg["AUTH_REQUIRED"], true): true);
                    $authentificatorProvider->set($auth);
                }else{
                    throw new Exception("Unknown authentificator type: {$type}");
                }
            }
        );
    }

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

    public function setAuthenticationUrl(string $url): self {
        $this->authenticationUrl = $url;
        return $this;
    }

    public function getAuthenticationUrl(): ?string {
        return $this->authenticationUrl;
    }

    public function requireAuthentication(bool $require): self {
        $this->needAuthentication = $require;
        return $this;
    }

    public function isAuthenticationRequired(): bool {
        return $this->needAuthentication;
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
    /** Cookie lifetime in seconds from the moment of login. */
    private int $cookieExpire = 86400;
    private bool $useDbSession = false;    

    /**
     * @param string|int $expiration seconds (int) or a strtotime() offset such as "+1 day"; 0 means the default of one day.
     */
    public function __construct(string $cookieName = "", string | int $expiration = 0) {
        if($cookieName != "") {
            $this->cookieName = $cookieName;
        }
        if (is_int($expiration)) {
            $this->cookieExpire = $expiration > 0 ? $expiration : 86400;
        } else {            
            $absolute = strtotime($expiration);
            if ($absolute === false) {
                throw new InvalidArgumentException("Invalid cookie expiration: '$expiration'");
            }
            $this->cookieExpire = max(1, $absolute - time());
        }

        if(defined("USE_SESSIONS_DB") && USE_SESSIONS_DB) {
            $this->useDbSession = true;
        }
    }

    public function getExpirationSeconds(): int {
        return $this->cookieExpire;
    }

    public function authenticate(string | object $value): bool {
        if($value == null) return false;

        if($this->useDbSession) {
            $token = bin2hex(random_bytes(32));
            $session = new \Models\Sessions();
            $session->hash = \Models\Sessions::hashToken($token); 
            if(!is_numeric($value)) {
                throw new Exception("CookieAuthentication with database session requires a numeric user ID as the value.");
            }
            $session->userId = (int)$value;
            $session->expiresAt = time() + $this->cookieExpire;
            $session->ip = Utilities::ip();
            $session->ipHash = Utilities::ipFingerprint($session->ip);
            $session->userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $session->save();
            Cookies::set($this->cookieName, $token, $this->cookieExpire);
            return true;
        }

        Cookies::set($this->cookieName, $value, $this->cookieExpire);
        return true;
    }

    public function deauthenticate(){
        if(isset($_COOKIE[$this->cookieName])) {
            if($this->useDbSession) {
                $token = $_COOKIE[$this->cookieName];
                $session = \Models\Sessions::findActive($token);
                if($session) {
                    $session->deactivate()->save();
                }
            }
            Cookies::delete($this->cookieName);
        }
    }

    public function isAuthenticated(): bool {
        $isCookiesValid = isset($_COOKIE[$this->cookieName]) && Cookies::security_check($this->cookieName);

        if($isCookiesValid && $this->useDbSession) {
            $token = $_COOKIE[$this->cookieName];
            $session = \Models\Sessions::findActive($token);
            return $session !== null;
        }

        return $isCookiesValid;
    }

    public function getValue(): string | object | null {
        if($this->isAuthenticated()) {
            $token = $_COOKIE[$this->cookieName];
            if($this->useDbSession) {                
                $session = \Models\Sessions::findActive($token);
                if($session) {
                    return $session->userId;
                }
                return null;
            }
            return $token;
        }
        return null;
    }
}

if(USE_EXCEPTION_HANDLER) {
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
            
            // Create the error directory if it does not exist
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
            if ($errno & (E_NOTICE | E_USER_NOTICE)) {
                return false;
            }

            if ($errno & (E_WARNING | E_USER_WARNING)) {
                return false;
            }

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

        private const SENSITIVE_KEYS = [
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'authorization', 'cookie', 'csrf', 'private_key', 'credit_card',
        ];
        private const MASK = '***';

        private function prepareErrorData(Throwable $exception): array {
            $debug = defined('DEBUG') && DEBUG === true;

            $line = $exception->getLine();
            if ($exception instanceof TemplaterV2Exception) {
                $line = $exception->getRow() + 1;
            }

            $trace = $exception->getTrace();
            $traceString = $exception->getTraceAsString();

            if (!$debug) {
                $trace = $this->sanitizeTrace($trace);
                $traceString = $this->formatTrace($trace);
            }

            return [
                'id' => uniqid('error_', true),
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $line,
                'trace' => $trace, // structured trace for the error page
                'trace_string' => $traceString,
                'code' => $exception->getCode(),
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI',
                'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'localhost'
            ];
        }

        private function isSensitiveKey(string|int|null $key): bool {
            if (!is_string($key)) {
                return false;
            }
            $key = strtolower($key);
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($key, $needle)) {
                    return true;
                }
            }
            return false;
        }

        /**
         * Recursively masks sensitive keys in arrays and replaces objects
         * with a short description (objects may hold secrets and are not safe to store).
         */
        private function sanitizeValue(mixed $value, int $depth = 0): mixed {
            if (is_object($value)) {
                return 'Object(' . get_class($value) . ')';
            }
            if (!is_array($value)) {
                return $value;
            }
            if ($depth > 5) {
                return 'Array(...)';
            }

            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->isSensitiveKey($key)
                    ? self::MASK
                    : $this->sanitizeValue($item, $depth + 1);
            }
            return $result;
        }

        /**
         * Returns parameter names of the function/method from a trace frame.
         */
        private function getParameterNames(array $frame): array {
            try {
                $function = $frame['function'] ?? null;
                if ($function === null) {
                    return [];
                }

                $reflection = isset($frame['class'])
                    ? new ReflectionMethod($frame['class'], $function)
                    : new ReflectionFunction($function);

                return array_map(fn(ReflectionParameter $p) => $p->getName(), $reflection->getParameters());
            } catch (ReflectionException) {
                return []; // closures, include/require frames, etc.
            }
        }

        private function sanitizeTrace(array $trace): array {
            foreach ($trace as &$frame) {
                if (!isset($frame['args']) || !is_array($frame['args'])) {
                    continue;
                }

                $names = $this->getParameterNames($frame);
                foreach ($frame['args'] as $index => $arg) {
                    // Named arguments (PHP 8) have string keys, positional ones use the reflection name
                    $name = is_string($index) ? $index : ($names[$index] ?? null);

                    $frame['args'][$index] = $this->isSensitiveKey($name)
                        ? self::MASK
                        : $this->sanitizeValue($arg);
                }
            }
            unset($frame);

            return $trace;
        }

        /**
         * Builds a string similar to getTraceAsString() from the sanitized trace.
         */
        private function formatTrace(array $trace): string {
            $lines = [];
            foreach ($trace as $i => $frame) {
                $location = isset($frame['file'])
                    ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')'
                    : '[internal function]';

                $args = [];
                foreach ($frame['args'] ?? [] as $arg) {
                    $args[] = match (true) {
                        is_string($arg) => "'" . (strlen($arg) > 15 ? substr($arg, 0, 15) . '...' : $arg) . "'",
                        is_array($arg) => 'Array',
                        is_null($arg) => 'NULL',
                        is_bool($arg) => $arg ? 'true' : 'false',
                        default => (string)$arg,
                    };
                }

                $call = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
                $lines[] = "#$i $location: $call(" . implode(', ', $args) . ')';
            }
            $lines[] = '#' . count($trace) . ' {main}';

            return implode("\n", $lines);
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
                
                // Code preview for this frame
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

            $errMessage = str_replace("\n", "<br/>", htmlspecialchars($errorData['message']));

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
$container->setSingleton(ConfigProvider::class);
$container->setSingleton(Config::class);
$container->setSingleton(Autoload::class);
$container->setSingleton(Router::class);
$container->setSingleton(Page::class);
$container->setSingleton(Layout::class);
$container->setSingleton(Database::class);
$container->setSingleton(Request::class);
$container->setSingleton(Response::class);
$container->setSingleton(AuthentificatorProvider::class);

if(USE_USERS) {
    $container->setSingleton(LoginThrottle::class);
    $container->setSingleton(UserService::class);
}

$container->get(Config::class)->load(defined("CONFIG_PATH")? CONFIG_PATH: null);
$configProvider = $container->get(ConfigProvider::class);

/* Basic config */
$configProvider->register(['TIME_ZONE'], function(array $cfg) {
    Date::setTimezoneOffset($cfg['TIME_ZONE']);
});
Database::registerConfig($configProvider);
Authentication::registerConfig($configProvider);

if(USE_EXCEPTION_HANDLER) {
    $container->setSingleton(GlobalErrorHandler::class);
    $container->get(GlobalErrorHandler::class)->register();
}

$configProvider->boot();
$container->get(Autoload::class)->register();

Request::setSecureHeaders(); // Set security headers

DebugTimer::stop("library");