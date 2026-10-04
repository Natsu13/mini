<?php
namespace Controllers;

use Models\Article;
use Models\ArticleStatus;

/**
 * Dashboard of the signed-in user: their articles and a few numbers.
 */
class Home extends \Controller {
    private const ARTICLES_PER_PAGE = 10;

    public function __construct(private \UserService $userService) {
    }

    #[\Route('', \Method::GET, name: 'home')]
    public function index() {
        $user = $this->userService->current();
        if ($user === null) {
            // Authentication is enforced by the router; this is only a safety net when AUTH_REQUIRED=false.
            return $this->redirectToRoute('auth.login');
        }

        $list = Article::toList(self::ARTICLES_PER_PAGE, "updated DESC", null, [], $user->getArticles());

        return $this->view("index", [
            "user" => $user,
            "permission" => $user->getPermission(),
            "articles" => $list->query->fetchAll(),
            "paginator" => $list->paginator,
            "stats" => [
                "total" => $user->getArticles()->count(),
                "published" => $user->getArticles()->where(["status" => ArticleStatus::Published->value])->count(),
            ],
        ]);
    }
}
