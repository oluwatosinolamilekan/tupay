<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function store(CreateAccountRequest $request, CreateAccountAction $createAccount): JsonResponse
    {
        $account = $createAccount->execute(
            userId: $request->integer('user_id'),
            currency: $request->string('currency')->toString(),
        );

        return (new AccountResource($account))
            ->response()
            ->setStatusCode($account->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Account $account): JsonResponse
    {
        return (new AccountResource($account))->response();
    }
}
