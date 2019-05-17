<?php

namespace App\Http\Controllers\Logs;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Traits\InteractsWithUserIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchUserListController extends Controller
{
    use InteractsWithUserIndex;

    public function search(Request $request): JsonResponse
    {
        if (auth()->user()->isAn(UserRole::SUPER_ADMIN)) {
            $publicAdministration = $request->input('p');
        } else {
            $publicAdministration = current_public_administration()->ipa_code;
        }
        if (isset($request->q)) {
            $result = $this->searchUser(
                $request->q,
                $publicAdministration
            );
        }

        return response()->json($result ?? []);
    }
}
