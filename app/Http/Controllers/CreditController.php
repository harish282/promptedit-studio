<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Generations\GenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreditController extends Controller
{
    public function purchase(Request $request, GenerationService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $service->purchaseCredits($user, 5_000_000_000);

        return back()->with('success', '$5.00 of credits was added to your balance.');
    }
}
