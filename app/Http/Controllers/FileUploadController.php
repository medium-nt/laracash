<?php

namespace App\Http\Controllers;

use App\Services\FileStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileUploadController extends Controller
{
    protected FileStorageService $fileStorageService;

    public function __construct(FileStorageService $fileStorageService)
    {
        $this->fileStorageService = $fileStorageService;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            $filename = $request->input('filename');

            $path = $this->fileStorageService->save(
                $request->file('file'),
                'uploads',
                $filename
            );

            if ($path) {
                return response()->json(['success' => true, 'path' => $path]);
            }
        }

        return response()->json(['success' => false, 'message' => 'File could not be saved.'], 400);
    }
}
