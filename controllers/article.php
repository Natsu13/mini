<?php
namespace Controllers;

class Article extends \Controller {
    private \UserService $userService;

    public function __construct(\UserService $userService) {
        $this->userService = $userService;        
    }

    /**
     * @method GET
     */
    public function index(\Models\Article $article) {
        return $this->view("index", ["id" => $article->id, "article" => $article]);
    }
    /*Old style that still works, but not as good as the new one with automatic model binding
    public function index(int $id) {
        $article = \Models\Article::findById($id);
        return $this->view("index", ["id" => $id, "article" => $article]);
    }*/

    /**
     * @allowAnonymous
     */
    public function apiTest() {
        return $this->json(["hello" => "article"]);
    }

    /**
     * @route("test/<i>")
     */
    public function test($i){
        return $this->json(["hello" => "test ".$i]);
    }
}