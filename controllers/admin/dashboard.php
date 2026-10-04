<?php
namespace Controllers\Admin;

use Models\Article;
use Models\ArticleStatus;
use Models\Permission;
use Models\User;

/**
 * Administration overview. The whole controller requires the admin level (see #[Authorize]).
 */
#[\Authorize(level: Permission::LEVEL_ADMIN)]
class Dashboard extends \Controller {

    #[\Route('admin', \Method::GET, name: 'admin.dashboard')]
    public function index() {
        return $this->view("index", [
            "users" => User::toQuery()->count(),
            "articles" => Article::toQuery()->count(),
            "published" => Article::where(["status" => ArticleStatus::Published->value])->count(),
            "drafts" => Article::where(["status" => ArticleStatus::Draft->value])->count(),
            "latest" => Article::toQuery()->with("author")->order("updated DESC")->limit(5)->fetchAll(),
        ]);
    }
}
