<?php
namespace Controllers;

use Flash;
use UserService;

#[\AllowAnonymous]
class Login extends \Controller {
    private UserService $userService;

    public function __construct(UserService $userService) {
        $this->userService = $userService;
    }

    #[\Route('login', \Method::GET, name: 'auth.login')]
    public function index(?string $back = null) {
        if ($this->userService->isAuthentificated()) {
            return $this->redirectToRoute('home');
        }
        return $this->View("index", ["back" => $back]);
    }

    #[\Route('login', \Method::POST, name: 'auth.login')]
    public function login(string $login, string $password, ?string $back = null) {
        $loginState = $this->userService->login($login, $password);
        if($loginState == \UserServiceLogin::Ok) {
            return $this->redirect(self::safeBackUrl($back));
        }

        $model = ["state" => $loginState, "back" => $back, "login" => $login];
        if ($loginState == \UserServiceLogin::Blocked) {
            $minutes = (int)ceil($this->userService->getThrottle()->retryAfter($login, \Utilities::ip()) / 60);
            $model["retryMinutes"] = max(1, $minutes);
        }

        return $this->view("index", $model);
    }

    #[\Route('logout', \Method::POST, name: 'auth.logout')]
    public function logout() {
        $this->userService->logout();
        Flash::info("You have been signed out.");
        return $this->redirectToRoute('auth.login');
    }

    /**
     * Only relative paths within this application are accepted as a "back" target.
     */
    private static function safeBackUrl(?string $back): string {
        if ($back === null || $back === "" || str_starts_with($back, "//") || preg_match('#^[a-z][a-z0-9+.-]*:#i', $back)) {
            return "/";
        }
        return $back;
    }

    #[\Route('register', \Method::GET, name: 'auth.register')]
    public function register(){
        return $this->view("register");
    }

    #[\Route('register', \Method::POST, name: 'auth.register')]
    public function registerPost(string $login, string $email, string $password, string $confirmPassword, string $gender = "") {
        $validator = \Validator::make($_POST, ['login' => 'alpha_dash|min:3|max:32']);
        if ($validator->fails()) {
            return $this->view("register", ["error" => "Validation failed", "validator" => $validator->errors()]);
        }

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
        }else if($registerState == \UserServiceCheck::PasswordBad) {
            return $this->view("register", ["error" => "The password must have at least " . UserService::MIN_PASSWORD_LENGTH . " characters"]);
        }

        return $this->view("register", ["success" => true]);
    }

    #[\Route('password-reset', \Method::GET, name: 'auth.password.reset')]
    public function resetPassword() {
        return $this->view("reset_password");
    }

    #[\Route('password-reset', \Method::POST, name: 'auth.password.reset')]
    public function resetPasswordPost(string $email) {
        if(empty($email)) {
            return $this->view("reset_password", ["error" => "Email is required"]);
        }

        $result = $this->userService->resetPassword($email);
        if($result === \UserServiceCheck::EmailInvalid) {
            return $this->view("reset_password", ["error" => "Email is not entered in the correct format"]);
        }

        // WrongEmail is deliberately reported as success so the form cannot be used to find out which emails are registered.
        return $this->view("reset_password", ["success" => true]);
    }

    #[\Route('password-reset-ticket/<token>', \Method::GET, name: 'auth.password.reset.ticket')]
    public function resetPasswordConfirm(string $token) {
        $ticket = $this->userService->checkResetPasswordTicket($token, $user, $ticket);
        if($ticket === \UserServiceCheck::WrongToken) {
            return $this->view("reset_password_ticket", ["error" => "Invalid or expired token"]);
        }

        return $this->view("reset_password_ticket", ["token" => $token, "user" => $user]);
    }

    #[\Route('password-reset-ticket/<token>', \Method::POST, name: 'auth.password.reset.ticket')]
    public function resetPasswordConfirmSave(string $token, string $password, string $confirm_password) {
        $state = $this->userService->checkResetPasswordTicket($token, $user, $ticket);
        if($state === \UserServiceCheck::WrongToken) {
            return $this->view("reset_password_ticket", ["error" => "Invalid or expired token"]);
        }

        if(empty($password) || empty($confirm_password)) {
            return $this->view("reset_password_ticket", ["error" => "Both fields are required", "token" => $token, "user" => $user]);
        }
        if($password != $confirm_password) {
            return $this->view("reset_password_ticket", ["error" => "Passwords don't match", "token" => $token, "user" => $user]);
        }

        $result = $this->userService->changePasswordByTicket($token, $password);
        if($result === \UserServiceCheck::WrongToken) {
            return $this->view("reset_password_ticket", ["error" => "Invalid or expired token"]);
        }
        if($result === \UserServiceCheck::PasswordBad) {
            return $this->view("reset_password_ticket", ["error" => "The password must have at least " . UserService::MIN_PASSWORD_LENGTH . " characters", "token" => $token, "user" => $user]);
        }

        return $this->view("reset_password_ticket", ["success" => true]);
    }
}