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
    public function index(int $id) {
        $article = \Models\Article::findById($id);
        return $this->view("index", ["id" => $id, "article" => $article]);
    }

    /**
     * @allowAnonymous
     */
    public function apiTest() {
        return $this->json(["hello" => "article"]);
    }
}