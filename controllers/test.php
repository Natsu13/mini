<?php
namespace Controllers;

class Test extends \Controller {

    #[\Route('_test/<i>', \Method::GET, name: 'test.test')]
    public function test(string $i){
        return $this->json(["hello" => "test ".$i]);
    }

    #[\RequireMethod(\Method::GET)]
    public function test2(string $i){
        return $this->json(["hello" => "test2 ".$i]);
    }

    // Reachable as /test/apiTest through the controller/action fallback. Anonymous and CSRF-exempt because
    // it is an API endpoint called by other clients (the home page calls it with the Http client).
    #[\AllowAnonymous]
    #[\SkipCsrf]
    public function apiTest() {
        return $this->json(["hello" => "article"]);
    }

    #[\RequireMethod(\Method::GET)]
    public function redirectTest(){
        return $this->redirectToRoute("test.test", ["i" => 123]);
    }
}