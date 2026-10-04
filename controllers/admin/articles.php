<?php
namespace Controllers\Admin;

use Flash;
use Models\Article;
use Models\ArticleStatus;
use Models\Permission;

/**
 * Editorial overview of every article with publish / unpublish / delete actions.
 */
#[\Authorize(level: Permission::LEVEL_ADMIN)]
class Articles extends \Controller {
    private const PER_PAGE = 20;

    #[\Route('admin/articles', \Method::GET, name: 'admin.articles')]
    public function index(string $status = "") {
        $query = Article::toQuery()->with("author");
        if (in_array($status, [ArticleStatus::Draft->value, ArticleStatus::Published->value], true)) {
            $query->where(["status" => $status]);
        }

        $list = Article::toList(self::PER_PAGE, "updated DESC", null, [], $query);

        return $this->view("index", [
            "articles" => $list->query->fetchAll(),
            "paginator" => $list->paginator,
            "status" => $status,
        ]);
    }

    #[\Route('admin/articles/status/<id>', \Method::POST, name: 'admin.articles.status')]
    public function status(Article $article, string $status) {
        if ($status === ArticleStatus::Published->value) {
            $article->publish();
            Flash::success("\"{$article->title}\" is now published.");
        } else {
            $article->unpublish();
            Flash::info("\"{$article->title}\" was moved back to drafts.");
        }
        $article->updated = time();
        $article->save();

        return $this->redirectToRoute('admin.articles');
    }

    #[\Route('admin/articles/delete/<id>', \Method::POST, name: 'admin.articles.delete')]
    public function delete(Article $article) {
        $article->delete();
        Flash::info("Article \"{$article->title}\" was deleted.");
        return $this->redirectToRoute('admin.articles');
    }
}
