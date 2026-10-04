<?php
namespace Controllers;

use Models\Article;

/**
 * Public side of the site: published articles, readable without an account.
 */
#[\AllowAnonymous]
class Blog extends \Controller {
    private const PER_PAGE = 10;

    #[\Route('blog', \Method::GET, name: 'blog.index')]
    public function index() {
        $list = Article::toList(self::PER_PAGE, "published DESC", null, [], Article::published());

        return $this->view("index", [
            "articles" => $list->query->fetchAll(),
            "paginator" => $list->paginator,
        ]);
    }

    #[\Route('blog/<slug>', \Method::GET, name: 'blog.show')]
    public function show(string $slug) {
        $article = Article::published()->where(["slug" => $slug])->fetch();
        if ($article === null) {
            $this->abort(404, "This article does not exist or is not published");
        }

        return $this->view("show", ["article" => $article, "author" => $article->getAuthor()]);
    }
}
