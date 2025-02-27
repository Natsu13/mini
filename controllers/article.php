<?php
namespace Controllers;

class Article extends \Controller {
    private \UserService $userService;

    public function ___construct(\UserService $userService) {
        $this->userService = $userService;        
    }

    /**
     * @method GET
     */
    public function index(int $id) {
        $article = \Models\Article::findById($id);
        return $this->view("index", ["id" => $id, "article" => $article]);
    }

    public function apiTest() {
        return $this->json(["hello" => "article"]);
    }
}