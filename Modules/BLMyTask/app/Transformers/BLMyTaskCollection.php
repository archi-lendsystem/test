<?php

namespace Modules\BLMyTask\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;

class BLMyTaskCollection extends ResourceCollection
{
    /**
     *
     * @var \Illuminate\Support\Collection
     */
    protected Collection $assignments;

    public function __construct($resource, Collection $assignments)
    {
        parent::__construct($resource);

        $this->assignments = $assignments;
    }

 
    protected function collects()
    {
        return null;
    }

    public function toArray(Request $request): array
    {
        return $this->collection
            ->map(function ($model) use ($request) {
                return (new BLMyTaskResource($model, $this->assignments))
                    ->toArray($request);
            })
            ->values()
            ->all();
    }

    public function toResponse($request): JsonResponse
    {
        $data = $this->toArray($request);

        return response()->json([
            'success'     => true,
            'status_code' => 200,
            'code'        => 'SUCCESS',
            'message'     => 'My tasks fetched successfully.',
            'request_id'  => (string) Str::uuid(),
            'timestamp'   => now()->toIso8601String(),
            'count'       => count($data),
            'data'        => $data,
        ], 200);
    }
}
