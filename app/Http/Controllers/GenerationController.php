<?php

namespace App\Http\Controllers;

use App\Exceptions\PixelforgeRequestFailed;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Models\User;
use App\Services\Generations\GenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public function create(GenerationService $service): Response
    {
        return Inertia::render('Generations/Create', [
            'initialEstimate' => $service->estimate([
                'prompt' => '',
                'size' => '1024x1024',
                'quality' => 'medium',
                'reference_images' => [],
            ]),
            'options' => [
                'sizes' => Generation::SIZES,
                'qualities' => Generation::QUALITIES,
            ],
        ]);
    }

    public function estimate(GenerationRequest $request, GenerationService $service): JsonResponse
    {
        return response()->json($service->estimate($request->validated()));
    }

    public function store(GenerationRequest $request, GenerationService $service): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $service->create($user, $request->validated());
        } catch (PixelforgeRequestFailed $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()->route('generations.index')
            ->with('success', 'Your image has been queued. It will appear in your history when it finishes.');
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $generations = $user->generations()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations)->response()->getData(true),
            'balanceNano' => $user->balance_nano,
        ]);
    }

    public function refresh(Request $request, string $generation, GenerationService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Generation $record */
        $record = Generation::where('id', $generation)
            ->where('user_id', $user->id)
            ->firstOrFail();

        try {
            $service->sync($record);
        } catch (PixelforgeRequestFailed) {
            return response()->json(new GenerationResource($record->fresh() ?? $record));
        }

        return response()->json(new GenerationResource($record->fresh() ?? $record));
    }
}
