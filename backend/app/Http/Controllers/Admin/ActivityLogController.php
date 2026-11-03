<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivityLogIndexRequest;
use App\Http\Resources\ActivityLogResource;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The activity log is read-only: entries are written by the actions themselves.
 */
class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * List what admins did, newest first.
     */
    public function index(ActivityLogIndexRequest $request): AnonymousResourceCollection
    {
        return ActivityLogResource::collection($this->activity->paginate($request->validated()));
    }

    /**
     * The admins and sections the log can be filtered by.
     */
    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->activity->filterOptions()]);
    }
}
