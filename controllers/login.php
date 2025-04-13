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

    /** 
     * @method GET 
     * @POST ::registerPost
     */
    public function register(){
        return $this->view("register");
    }

    /** @method POST */
    public function registerPost(string $login, string $email, string $password, string $confirmPassword, string $gender = "") {
        if(empty($login) || empty($email) || empty($password) || empty($confirmPassword) || empty($gender)) {
            return $this->view("register", ["error" => "All fields are required"]);
        }
        if($password != $confirmPassword) {
            return $this->view("register", ["error" => "The passwords do not match"]);
        }

        $registerState = $this->userService->register($login, $password, $email, function(\Models\User $user) use ($gender) {
            $user->gender = $gender;
        });

        if($registerState == \UserServiceCheck::EmailExists) {
            return $this->view("register", ["error" => "The email already exists"]);
        }else if($registerState == \UserServiceCheck::LoginExists) {
            return $this->view("register", ["error" => "The login already exists"]);
        }else if($registerState == \UserServiceCheck::EmailInvalid) {
            return $this->view("register", ["error" => "The email is invalid"]);
        }

        return $this->view("register", ["success" => true]);
    }
}