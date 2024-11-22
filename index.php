<?php
error_reporting(E_ERROR | E_PARSE);
//defined("DEBUG", true);
define("ROOT", str_replace("\\", "/", getcwd()));
require_once "./library.php";
require_once "./models/user.php";

ob_start();

$container = Container::getInstance();

$page = $container->get(Page::class);
$router = $container->get(Router::class);
$layout = $container->get(Layout::class);
$database = $container->get(Database::class);
$userService = $container->get(UserService::class);

$database->connect("127.0.0.1", "mini", "root", "");

$router->add("", "page=index");
$router->add("login", "page=login");
$router->add("logout", function($args) use($userService, $router) {
    $userService->logout();
    $router->redirect("/");
});
$router->add("apitest", function(){
    echo json_encode(["hello" => "world"]);
    exit();
});

$router->start();

echo "<html>";
    $page->head();
    echo "<body>";
        $isAuthentificated = $userService->isAuthentificated();
        if(!$isAuthentificated || $_GET["page"] == "login") {
            $loginState = null;
            if(isset($_POST["login"])) {
                $loginState = $userService->login($_POST["login"], $_POST["password"]);
                if($loginState == UserServiceLogin::Ok) {
                    $router->redirect("/");
                }
            }
            $layout->render(ROOT."/views/login.view", ["state" => $loginState]);
        } else {
            $user = $userService->current();

            $builder = db\User::where("login = 'admin'")
                ->where("id = :id", [":id" => 1])->limit(10);

            $http = new Http();

            $layout->render(ROOT."/views/index.view", [
                "user" => $user, 
                "query" => $builder->fetch(),
                "api" => $http->getJson(Router::url()."/apitest/")->getResponse()
            ]);
        }

        $page->footer();
    echo "</body>";
echo "</html>";

ob_end_flush();