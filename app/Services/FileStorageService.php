<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

/**
 * Service for handling file uploads and storage.
 */
class FileStorageService
{
    /**
     * Store the uploaded file on the specified disk.
     *
     * @param UploadedFile $file The file to be saved.
     * @param string $path The path within the disk to store the file.
     * @param string|null $filename The desired name of the file.
     * @param string $disk The disk to store the file on. Defaults to 'public'.
     * @return string|false The path to the stored file, or false on failure.
     */
    public function save(UploadedFile $file, string $path = '', ?string $filename = null, string $disk = 'public'): string|false
    {
        if ($filename) {
            return $file->storeAs($path, $filename, $disk);
        }

        return $file->store($path, $disk);
    }
}
