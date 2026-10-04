<?php
namespace Controllers;

use Flash;
use Models\Permission;
use Validator;

/**
 * Authoring area: create, edit, publish and delete your own articles (administrators may manage everyone's).
 * Demonstrates attribute routes with GET/POST siblings, model binding, Validator, Flash messages and abort().
 */
class Article extends \Controller {
    public function __construct(private \UserService $userService) {
    }

    private function currentUser(): \Models\User {
        $user = $this->userService->current();
        if ($user === null) {
            $this->abort(401);
        }
        return $user;
    }

    private function assertCanManage(\Models\Article $article): void {
        $user = $this->currentUser();
        if ($article->authorId !== $user->id && !$user->hasLevel(Permission::LEVEL_ADMIN)) {
            $this->abort(403, "You can only manage your own articles");
        }
    }

    private function rules(?\Models\Article $article): array {
        return [
            'title' => 'required|max:200',
            'slug' => ['max:220', 'slug'],
            'content' => 'max:65000',
            'publish' => 'boolean',
        ];
    }

    /**
     * Form values as the edit view expects them (the checkbox state must be a real boolean, otherwise
     * checked="" would keep it checked after a failed validation).
     */
    private function formInput(array $validated): array {
        return [
            "title" => (string)($validated['title'] ?? ''),
            "slug" => (string)($validated['slug'] ?? ''),
            "content" => (string)($validated['content'] ?? ''),
            "publish" => \Config::parseBool($validated['publish'] ?? false),
        ];
    }

    private function applyInput(\Models\Article $article, array $input): void {
        $article->title = $input['title'];
        $article->content = (string)($input['content'] ?? '');
        $article->slug = \Models\Article::uniqueSlug($input['slug'] !== null && $input['slug'] !== '' ? $input['slug'] : $input['title'], $article->id);
        $article->updated = time();

        if (\Config::parseBool($input['publish'] ?? false)) {
            $article->publish();
        } else {
            $article->unpublish();
        }
    }

    #[\Route('article/<id>', \Method::GET, name: 'article.show')]
    public function index(\Models\Article $article) {
        $this->assertCanManage($article);
        return $this->view("index", ["article" => $article, "author" => $article->getAuthor()]);
    }

    // priority: 1 makes "article/new" win over the "article/<id>" pattern above.
    #[\Route('article/new', \Method::GET, name: 'article.new', priority: 1)]
    public function create() {
        return $this->view("edit", ["article" => null, "input" => ["title" => "", "slug" => "", "content" => "", "publish" => false], "errors" => []]);
    }

    #[\Route('article/new', \Method::POST, name: 'article.new', priority: 1)]
    public function createPost() {
        $validator = Validator::make($_POST, $this->rules(null));
        if ($validator->fails()) {
            return $this->view("edit", ["article" => null, "input" => $this->formInput($validator->validated()), "errors" => $validator->errors()]);
        }

        $article = new \Models\Article();
        $article->authorId = $this->currentUser()->id;
        $this->applyInput($article, $validator->validated());
        $article->save();

        Flash::success("Article \"{$article->title}\" was created" . ($article->isPublished() ? " and published" : " as a draft") . ".");
        return $this->redirectToRoute('article.edit', ['id' => $article->id]);
    }

    #[\Route('article/edit/<id>', \Method::GET, name: 'article.edit')]
    public function edit(\Models\Article $article) {
        $this->assertCanManage($article);
        return $this->view("edit", [
            "article" => $article,
            "input" => ["title" => $article->title, "slug" => $article->slug, "content" => $article->content, "publish" => $article->isPublished()],
            "errors" => [],
        ]);
    }

    #[\Route('article/edit/<id>', \Method::POST, name: 'article.edit')]
    public function editPost(\Models\Article $article) {
        $this->assertCanManage($article);

        $validator = Validator::make($_POST, $this->rules($article));
        if ($validator->fails()) {
            return $this->view("edit", ["article" => $article, "input" => $this->formInput($validator->validated()), "errors" => $validator->errors()]);
        }

        $this->applyInput($article, $validator->validated());
        $article->save();

        Flash::success("Article saved.");
        return $this->redirectToRoute('article.edit', ['id' => $article->id]);
    }

    // Deleting changes state, so it only answers to POST (a GET link could be triggered by any page you visit).
    #[\Route('article/delete/<id>', \Method::POST, name: 'article.delete')]
    public function delete(\Models\Article $article) {
        $this->assertCanManage($article);
        $article->delete();
        Flash::info("Article \"{$article->title}\" was deleted.");
        return $this->redirectToRoute('home');
    }
}