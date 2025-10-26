# Mini PHP MVC Framework

![PHP 8.3](https://img.shields.io/badge/PHP-8.3-blue.svg?logo=php)

A very small and lightweight PHP Model-View-Controller (MVC) framework designed for simplicity and ease of use. It features basic routing, user authentication, an ORM-like model interaction with database query building, and HTTP request utilities. Ideal for small projects or learning purposes.

## Installation

### Prerequisites

*   PHP 8.3 or higher.
*   Web server software (Apache or Nginx).
*   MySQL or MariaDB database.

### Setup Steps

1.  **Clone the repository:**
    ```bash
    git clone https://github.com/Natsu13/mini.git
    ```

2.  **Database Configuration:**
    *   Create a database in your MySQL/MariaDB server (e.g., `mini`).
    *   Update the database connection details in `index.php`. Specifically, modify the following line to match your database server, name, username, and password:
        ```php
         $database->connect("127.0.0.1", "mini", "your_db_user", "your_db_password"); // Params: host, db_name, user, password. Use strong credentials for production.
        ```
    *   The framework can help generate table schemas from your model definitions. For example, to get the SQL for a `User` model (as seen in `index.php` for initial setup), you can use:
        ```php
        echo \Model::generateCreateTableQuery(User::class);
        ```

3.  **Web Server Configuration:**
    *   Set the document root of your web server to the project's root directory (where `index.php` and `.htaccess` are located).

    *   **Apache:**
        *   Ensure `mod_rewrite` is enabled.
        *   The provided `.htaccess` file should be processed by Apache to handle routing. Make sure your Apache configuration allows `.htaccess` overrides (e.g., `AllowOverride All` in your virtual host configuration).

    *   **Nginx:**
        *   Use a configuration similar to the following:
            ```nginx
            server {
                listen 80;
                server_name yourdomain.com; # Replace with your domain
                root /path/to/your/project; # Replace with the actual path to the project root
                index index.php;

                location / {
                    try_files $uri $uri/ /index.php?$query_string;
                }

                location ~ \.php$ {
                    include snippets/fastcgi-php.conf;
                    # Adjust to your PHP-FPM version and socket path if necessary
                    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
                    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
                    include fastcgi_params;
                }

                # Deny access to .htaccess files, if present
                location ~ /\.ht {
                    deny all;
                }
            }
            ```
            Remember to replace placeholders like `yourdomain.com` and `/path/to/your/project` with your actual values. Reload or restart Nginx to apply the changes.
        * Here is the **.htaccess** rewrited for **nginx**
            ```nginx
            location / {
                try_files $uri $uri/ @htaccess_fallback;
            }
        
            location @htaccess_fallback {
                rewrite ^/(.*)$ /index.php?url=$1&$args last;
            }
            ```

## Key Features

This framework offers several core functionalities to help you build web applications quickly.

### Routing
The framework provides a straightforward way to define routes:
```php
$router->add("login", "page=login");
$router->add("logout", function($args) use($userService, $router) {
    $userService->logout();
    $router->redirect("/");
});
```

### User Management
User registration, login, and session management are built-in:
```php
$userService->register("test", "password", "test@test.cz");
$userService->login("test", "password");
$userService->isAuthentificated();
$user = $userService->current();
```

### Database and Models
Define database models and interact with your database using an ORM-like approach. Table schemas can also be generated from these model definitions.
```php
/** 
 * @table("users") 
 */
class User extends \Model {
    /** @primaryKey */
    public ?int $id;

    /** @column("login") */
    public string $login;

    public string $password;

    public string $email;
}
```

Build complex queries easily:
```php
$builder = db\User::where("login = 'admin'")
  ->where("id = :id", [":id" => 1])->limit(10);
```

### HTTP Requests
Make HTTP requests to external services:
```php
$http = new Http();
$response = $http->getJson(Router::url()."/apitest/")->getResponse();
```

## Project Structure

The framework follows a standard MVC pattern. Key directories and files include:

*   `controllers/`: Contains controller classes that handle user requests, process input, interact with models, and select views to render.
*   `models/`: Contains model classes that represent database tables, encapsulate business logic, and handle data operations.
*   `views/`: Contains view files (typically PHP templates) responsible for presenting data to the user.
*   `library.php`: Core library file. This may include helper functions, class autoloading mechanisms, framework bootstrap routines, or other essential utilities.
*   `index.php`: The main entry point of the application. It initializes the framework (e.g., autoloader, services, database connection), sets up routing, and dispatches requests to the appropriate controllers.
*   `.htaccess`: Apache web server configuration file. It's primarily used for URL rewriting, ensuring that all relevant requests are directed to `index.php` to be handled by the framework's router.

## Contributing

Contributions are welcome! If you have suggestions or want to improve the framework, please feel free to:
1.  Open an issue to discuss the change.
2.  Fork the repository and submit a pull request with your improvements.

## License

This project is licensed under the MIT License.
