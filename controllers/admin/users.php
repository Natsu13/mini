<?php
namespace Controllers\Admin;

use Flash;
use Models\Permission;
use Models\User;
use Validator;

/**
 * User administration: search, change e-mail and permission level, delete.
 */
#[\Authorize(level: Permission::LEVEL_ADMIN)]
class Users extends \Controller {
    private const PER_PAGE = 20;

    public function __construct(private \UserService $userService) {
    }

    #[\Route('admin/users', \Method::GET, name: 'admin.users')]
    public function index(string $q = "") {
        $query = User::toQuery()->with("permission");
        $q = trim($q);
        if ($q !== "") {
            $query->where(fn(\QueryBuilderWhere $w) => $w->like("login", $q)->likeOr("email", $q));
        }

        $list = User::toList(self::PER_PAGE, "id ASC", null, [], $query);

        return $this->view("index", [
            "users" => $list->query->fetchAll(),
            "paginator" => $list->paginator,
            "q" => $q,
            "me" => $this->userService->current(),
        ]);
    }

    #[\Route('admin/users/edit/<id>', \Method::GET, name: 'admin.users.edit')]
    public function edit(User $user) {
        return $this->view("edit", [
            "user" => $user,
            "input" => ["email" => $user->email, "permission_id" => $user->permissionId],
            "permissions" => Permission::toQuery()->order("level ASC")->fetchAll(),
            "errors" => [],
        ]);
    }

    #[\Route('admin/users/edit/<id>', \Method::POST, name: 'admin.users.edit')]
    public function editPost(User $user) {
        $me = $this->userService->current();
        $permissions = Permission::toQuery()->order("level ASC")->fetchAll();
        $permissionIds = array_map(fn(Permission $p) => (string)$p->id, $permissions);

        $validator = Validator::make($_POST, [
            'email' => ['required', 'email', 'max:100', function ($value) use ($user) {
                $other = User::findByEmail($value);
                return ($other === null || $other->id === $user->id) ? true : "This e-mail is already used by another user";
            }],
            'permission_id' => ['required', 'in:' . implode(',', $permissionIds), function ($value) use ($user, $me) {
                if ($user->id !== $me->id) return true;
                $permission = Permission::findById((int)$value);
                return ($permission !== null && $permission->level >= Permission::LEVEL_ADMIN) ? true : "You cannot remove your own administrator rights";
            }],
        ]);

        if ($validator->fails()) {
            return $this->view("edit", ["user" => $user, "input" => $validator->validated(), "permissions" => $permissions, "errors" => $validator->errors()]);
        }

        $input = $validator->validated();
        $user->email = $input['email'];
        $user->permissionId = (int)$input['permission_id'];
        $user->save();
        $this->userService->refreshCurrent();

        Flash::success("User \"{$user->login}\" was saved.");
        return $this->redirectToRoute('admin.users');
    }

    #[\Route('admin/users/delete/<id>', \Method::POST, name: 'admin.users.delete')]
    public function delete(User $user) {
        if ($user->id === $this->userService->current()->id) {
            $this->abort(403, "You cannot delete your own account");
        }

        $user->delete();   // cascades to the user's articles (see Models\User::getArticles)
        Flash::info("User \"{$user->login}\" and their articles were deleted.");
        return $this->redirectToRoute('admin.users');
    }
}
