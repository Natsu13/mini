<?php
namespace Controllers;

use UserService;

/**
 * @allowAnonymous
 */
class Login extends \Controller {
    private UserService $userService;
    
    public function __construct(UserService $userService) {
        $this->userService = $userService;        
    }

    /** 
     * @method GET 
     * @POST ::login
     */
    public function index(?string $back = null) {
        return $this->View("index", ["back" => $back]);
    }

    /** @method POST */
    public function login(string $login, string $password, ?string $back = null) {
        $loginState = $this->userService->login($login, $password);
        if($loginState == \UserServiceLogin::Ok) {
            return $this->redirect(is_null($back)? "/": $back);
        }

        return $this->view("index", ["state" => $loginState, "back" => $back]);
    }
}