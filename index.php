<?php
define("DEBUG", true);
define("APP_KEY", "strong-key-123");

require_once "./library.php";

DebugTimer::start("core");

ob_start();

use Models\User;
use Models\Article;

$container = Container::getInstance();

$config = $container->get(Config::class);
if(isset($_GET["encrypt"]) && defined("APP_KEY")) {
    $config->encrypt(key: APP_KEY);
    echo "Config was encrypted!";
    exit();
}

$page = $container->get(Page::class);
$router = $container->get(Router::class);
$layout = $container->get(Layout::class);
$userService = $container->get(UserService::class);
$request = $container->get(Request::class);
$response = $container->get(Response::class);

$router->add("", "view=index");
$router->add("login", [Controllers\Login::class]);
$router->add("register", [Controllers\Login::class, "register"]);
$router->add("logout", function ($args) use ($userService, $router) {
    $userService->logout();
    $router->redirect("/");
});

$router->add("apitest", function () use ($response, $request) {
    $response->enableCors();
    if ($request->is("get")) {
        echo json_encode(["hello" => "world"]);
    } else {
        echo json_encode(["hello" => "post"]);
    }
    exit();
});
$router->add("apitest2", [Controllers\Test::class, "apiTest"]);

$router->add("article/<id>", [Controllers\Article::class]);
$router->add("article/<action>/<id>", "view=article&action=<action>&id=<id>");

$router->addControllerActionFallback();

$router->start();

echo "<html>";
    $page->head();
    echo "<body>";        

        DebugTimer::start("page.draw");

        if (!$router->tryProcessController()) {
            $router->redirectToLoginIfNeeded();

            $user = $userService->current();

            DebugTimer::start("page.logic");

            $builder = User::where(fn($w) => $w->and("login", "admin")->and("id = :id", [":id" => 1]))->limit(10);

            $user1 = User::findById(1);

            $http = new Http();

            $articleLimitOnPage = 10;
            $articles = $user->getArticles();
            $paginator = new Paginator($articles->count(), $articleLimitOnPage, Router::url(true));
            $articles = $articles->order("created DESC")->limit($articleLimitOnPage)->page($paginator->getCurrentPage());

            $model = [
                "user" => $user,
                "permission" => $user->getPermission(),
                "query" => $user1/*$builder->fetchAll()*/,
                "api" => $http->postJson(Router::url() . "/apitest/")->getResponse(),
                "api2" => (new Http())->postJson(Router::url() . "/apitest2/")->getResponse(),
                "articlesPaginator" => $paginator,
                "articles" => $articles,
                "sql" => \Model::generateCreateTableQuery(User::class),
                "action" => "none"                
            ];

            DebugTimer::stop("page.logic");

            if ($_GET["view"] == "article" && $_GET["action"] == "new") {
                if (isset($_POST["title"])) {
                    $article = new Article();
                    $article->title = $_POST["title"];
                    $article->content = $_POST["content"];
                    $article->authorId = $user->id;
                    $article->save();
                    $router->redirect("/article/edit/" . $article->id);
                    exit();
                }

                $model["action"] = "article.edit";
                $model["article"] = null;

                $layout->render(ROOT . "/views/index.view", $model);
            } else if ($_GET["view"] == "article" && $_GET["action"] == "edit") {
                $article = Article::findById($_GET["id"]);
                if ($article == null) {
                    $response->status(404);
                    $response->write("Article not found");
                }

                if (isset($_POST["title"])) {
                    $article->title = $_POST["title"];
                    $article->content = $_POST["content"];
                    $article->save();
                    $router->redirect("/article/edit/" . $article->id);
                    exit();
                }

                $model["action"] = "article.edit";
                $model["article"] = $article;

                $layout->render(ROOT . "/views/index.view", $model);
            } else if ($_GET["view"] == "article" && $_GET["action"] == "delete") {
                $article = Article::findById($_GET["id"]);
                $article->delete();
                $router->redirect("/");
            } else {
                $layout->render(ROOT . "/views/index.view", $model);                
            }
        }
        $page->footer();        

        DebugTimer::stop("page.draw");
    echo "</body>";
echo "</html>";

DebugTimer::stop("core");
DebugTimer::dump();

ob_end_flush();