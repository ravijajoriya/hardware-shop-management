<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportProductsRequest;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductImportController extends Controller
{
    public function __construct(private readonly ProductImportService $imports) {}

    /**
     * The Excel template to fill in.
     */
    public function template(): Response
    {
        return response($this->imports->template(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="products-template.xlsx"',
        ]);
    }

    /**
     * What importing the file would do: what is ready, what is already in the app and every
     * problem by row. Nothing is saved.
     */
    public function check(ImportProductsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->imports->check($request->file('file'))]);
    }

    /**
     * Add the products in the file that are ready.
     */
    public function store(ImportProductsRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->imports->import($request->file('file'), $request->boolean('skip_problems')),
        ], 201);
    }
}
