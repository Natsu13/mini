Very small php framework

Simply routing:
```php
$router->add("login", "page=login");
$router->add("logout", function($args) use($userService, $router) {
    $userService->logout();
    $router->redirect("/");
});
```

User handling:
```php
$userService->register("test", "password", "test@test.cz");
$userService->login("test", "password");
$userService->isAuthentificated();
$user = $userService->current();
```

Database models:
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

Build query over Models:
```php
$builder = db\User::where("login = 'admin'")
  ->where("id = :id", [":id" => 1])->limit(10);
```

Building simple http request:
```php
$http = new Http();
$response = $http->getJson(Router::url()."/apitest/")->getResponse();
```
