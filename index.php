<?php
//defined("DEBUG", true);
if(!defined("DEBUG")) {
    error_reporting(E_ERROR | E_PARSE);
}
require_once "./library.php";

ob_start();

use Models\User;
use Models\Article;

$container = Container::getInstance();

$page = $container->get(Page::class);
$router = $container->get(Router::class);
$layout = $container->get(Layout::class);
$database = $container->get(Database::class);
$userService = $container->get(UserService::class);
$request = $container->get(Request::class);
$response = $container->get(Response::class);

Date::setTimezoneOffset(1); // +1 Europe/Prague
$database->connect("127.0.0.1", "mini", "root", "");

$router->add("", "view=index");
$router->add("login", "view=login");
$router->add("logout", function($args) use($userService, $router) {   
    $userService->logout();
    $router->redirect("/");
});
$router->add("apitest", function() use($response, $request) {
    $response->enableCors();
    if($request->is("get")) {
        echo json_encode(["hello" => "world"]);
    }else{
        echo json_encode(["hello" => "post"]);
    }
    exit();
});
$router->add("article[/<action>][/<id>]", "view=article&action=<action>&id=<id>");

$router->start();

echo "<html>";
    $page->head();
    echo "<body>";
        $isAuthentificated = $userService->isAuthentificated();
        if(!$isAuthentificated || $_GET["view"] == "login") {
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

            $builder = User::where("login = 'admin'")
                ->where("id = :id", [":id" => 1])->limit(10);

            $user1 = User::findById(1);

            $http = new Http();
                        
            $articleLimitOnPage = 10;
            $articles = $user->articles();
            $paginator = new Paginator($articles->count(), $articleLimitOnPage, Router::url(true));
            $articles = $articles->order("created DESC")->limit($articleLimitOnPage)->page($paginator->getCurrentPage());

            $model = [
                "user" => $user, 
                "permission" => $user->permission(),
                "query" => $user1/*$builder->fetchAll()*/,
                "api" => $http->postJson(Router::url()."/apitest/")->getResponse(),
                "articlesPaginator" => $paginator,
                "articles" => $articles,
                "sql" => \Model::generateCreateTableQuery(Article::class)
            ];

            if($_GET["view"] == "article" && $_GET["action"] == "new") {                
                if(isset($_POST["title"])) {
                    $article = new Article();
                    $article->title = $_POST["title"];
                    $article->content = $_POST["content"];
                    $article->authorId = $user->id;
                    $article->save();
                    $router->redirect("/article/edit/".$article->id);
                    exit();
                }

                $model["action"] = "article.edit";
                $model["article"] = null;

                $layout->render(ROOT."/views/index.view", $model);
            } else if($_GET["view"] == "article" && $_GET["action"] == "edit") {
                $article = Article::findById($_GET["id"]);
                if($article == null) {
                    $response->status(404);
                    $response->write("Article not found");
                }

                if(isset($_POST["title"])) {
                    $article->title = $_POST["title"];
                    $article->content = $_POST["content"];
                    $article->save();
                    $router->redirect("/article/edit/".$article->id);
                    exit();
                }

                $model["action"] = "article.edit";
                $model["article"] = $article;

                $layout->render(ROOT."/views/index.view", $model);
            } else if($_GET["view"] == "article" && $_GET["action"] == "delete") {
                $article = Article::findById($_GET["id"]);
                $article->delete();
                $router->redirect("/");
            } else {
                $layout->render(ROOT."/views/index.view", $model);
            }                    
        }

        $page->footer();
    echo "</body>";
echo "</html>";

ob_end_flush();