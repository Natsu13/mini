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

    /** 
     * @route("password-reset")
     * @method GET 
     * @POST ::resetPasswordPost
     */
    public function resetPassword() {
        return $this->view("reset_password");
    }

    public function resetPasswordPost(string $email) {
        if(empty($email)) {
            return $this->view("reset_password", ["error" => "Email je povinný"]);
        }

        $result = $this->userService->resetPassword($email);
        if($result === \UserServiceCheck::WrongEmail) {
            return $this->view("reset_password", ["error" => "Tento email není registrován"]);
        } else if($result === \UserServiceCheck::EmailInvalid) {
            return $this->view("reset_password", ["error" => "Email není zadán ve správném tvaru"]);
        }

        return $this->view("reset_password", ["success" => true]);
    }

    /** 
     * @route("password-reset-ticket/<token>")
     * @method GET 
     * @POST ::resetPasswordConfirmSave
     */
    public function resetPasswordConfirm(string $token) {
        $ticket = $this->userService->checkResetPasswordTicket($token, $user, $ticket);
        if($ticket === \UserServiceCheck::WrongToken) {
            return $this->view("reset_password_ticket", ["error" => "Neplatný token"]);
        }

        return $this->view("reset_password_ticket", ["token" => $token, "user" => $user]);
    }

    public function resetPasswordConfirmSave(string $token, string $password, string $confirm_password) {
        if(empty($password) || empty($confirm_password)) {
            return $this->view("reset_password_ticket", ["error" => "Obě pole jsou povinná", "token" => $token]);
        }
        if($password != $confirm_password) {
            return $this->view("reset_password_ticket", ["error" => "Hesla se neschodují", "token" => $token]);
        }

        $result = $this->userService->changePasswordByTicket($token, $password);
        if($result === \UserServiceCheck::WrongToken) {
            return $this->view("reset_password_ticket", ["error" => "Neplatný token", "token" => $token]);
        }

        return $this->view("reset_password_ticket", ["success" => true]);
    }
}