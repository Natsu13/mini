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

$page->title = "Mini";
$page->language = "en";
$page->addStyle(Router::url() . "/assets/app.css");

//echo Model::generateSyncQuery(Models\Sessions::class);exit();

$router->add("apitest", function () use ($response, $request) {
    $response->enableCors();
    if ($request->is("get")) {
        echo json_encode(["hello" => "world"]);
    } else {
        echo json_encode(["hello" => "post"]);
    }
    exit();
});

// Anything else resolves to controllers/<controller>.php::<action>() (defaults to index()).
$router->addControllerActionFallback();

// HTTP errors (404, 403, ...) as a styled page instead of plain text; JSON clients keep the JSON body.
$router->setHttpErrorHandler(function (HttpException $e) use ($router, $request, $layout, $page) {
    if ($request->isJsonRequest()) {
        $router->setHttpErrorHandler(null);
        $router->sendHttpError($e);
    }

    if (ob_get_level()) ob_clean();
    http_response_code($e->getStatus());
    $page->title = $e->getStatus() . " " . HttpException::reasonPhrase($e->getStatus());
    echo "<html>";
    $page->head();
    echo "<body>";
    $layout->render(ROOT . "/views/errors/error.view", ["status" => $e->getStatus(), "message" => $e->getMessage()]);
    echo "</body></html>";
    exit();
});

$router->start();

// Redirects, JSON and standalone components never show the page shell, so send them right away
// (this also keeps flash messages for the page that follows a redirect).
if (!$router->rendersPage()) {
    $router->tryProcessController();
}

echo "<html>";
    $page->head();
    echo "<body>";

        DebugTimer::start("page.draw");

        // Site navigation + pending flash messages, shared by every page.
        $layout->render(ROOT . "/views/partials/nav.view", ["user" => $userService->current(), "level" => $userService->currentLevel()]);

        echo '<main class="container">';
        if (!$router->tryProcessController()) {
            // No controller produced a response for this URL.
            $router->redirectToLoginIfNeeded();
            $response->status(404);
            $layout->render(ROOT . "/views/errors/error.view", ["status" => 404, "message" => "Page not found"]);
        }
        echo '</main>';

        $page->footer();

        DebugTimer::stop("page.draw");
    echo "</body>";
echo "</html>";

DebugTimer::stop("core");
if (DEBUG) {
    DebugTimer::dump();
}

ob_end_flush();