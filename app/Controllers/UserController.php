<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Kanin\Http\JsonResponse;
use Kanin\Http\Request;

/**
 * The /api/users resource: list, show, create, update and delete users.
 *
 * Arrays and models returned here are turned into JSON responses automatically.
 */
class UserController
{
    /**
     * GET /api/users?page=1&per_page=15: one page of users plus pagination info.
     */
    public function index(Request $request): array
    {
        return User::query()
            ->orderBy('id')
            ->paginate((int) $request->query('per_page', 15), (int) $request->query('page', 1));
    }

    /**
     * GET /api/users/{id}: one user, or 404 when it does not exist.
     */
    public function show(int $id): User
    {
        return User::findOrFail($id);
    }

    /**
     * POST /api/users: validate the input, create the user, answer 201 Created.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response(User::create($data), 201);
    }

    /**
     * PUT or PATCH /api/users/{id}: validate the input and update the user.
     */
    public function update(Request $request, int $id): User
    {
        $user = User::findOrFail($id);
        $data = $request->validate($this->rules($id));

        $user->fill($data)->save();

        return $user;
    }

    /**
     * DELETE /api/users/{id}: delete the user, answer 204 No Content.
     */
    public function destroy(int $id): JsonResponse
    {
        User::findOrFail($id)->delete();

        return response(null, 204);
    }

    /**
     * The validation rules for creating or updating a user.
     *
     * When updating, $ignoreId tells the unique rule to skip the user's own
     * row, so saving an unchanged email address is not "already taken".
     */
    private function rules(?int $ignoreId = null): array
    {
        $unique = 'unique:users,email' . ($ignoreId === null ? '' : ',' . $ignoreId);

        return [
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255|' . $unique,
        ];
    }
}
