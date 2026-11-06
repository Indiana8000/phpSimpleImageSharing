<?php
declare(strict_types=1);

/**
 * Stores uploaded media outside the public web root, grouped by immutable user ID.
 * Database metadata should store the returned `storage_name`, never an absolute path.
 */
final class MediaStorage
{
    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private const ID_PATTERN = '/\A[a-f0-9]{32,64}\z/';
    private const STORAGE_NAME_PATTERN = '/\A[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z/';

    private string $dataDirectory;

    public function __construct(string $dataDirectory)
    {
        if (is_link($dataDirectory) || !is_dir($dataDirectory)) {
            throw new RuntimeException('The configured data directory must exist and must not be a symbolic link.');
        }

        $realPath = realpath($dataDirectory);
        if ($realPath === false || !is_writable($realPath)) {
            throw new RuntimeException('The configured data directory is not writable.');
        }

        $this->dataDirectory = rtrim($realPath, DIRECTORY_SEPARATOR);
    }

    /**
     * Validate and store a PHP upload under a random name in the owner's directory.
     *
     * @param array<string, mixed> $upload One entry from $_FILES.
     * @return array{id: string, storage_name: string, original_name: string, mime: string, size_bytes: int}
     * @throws InvalidArgumentException For invalid owner IDs or unsupported/invalid uploads.
     * @throws RuntimeException If the file cannot be stored.
     */
    public function storeUploadedFile(string $ownerId, array $upload, int $maxBytes): array
    {
        $this->assertId($ownerId, 'owner');
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The upload size limit must be positive.');
        }

        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage($error));
        }

        $temporaryPath = $upload['tmp_name'] ?? null;
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('The upload is not a valid HTTP file upload.');
        }

        $size = filesize($temporaryPath);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException('The uploaded file is empty or exceeds the upload limit.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($temporaryPath);
        if (!is_string($mime) || !isset(self::MIME_EXTENSIONS[$mime])) {
            throw new InvalidArgumentException('This image format is not supported.');
        }
        if (@getimagesize($temporaryPath) === false) {
            throw new InvalidArgumentException('The uploaded file is not a valid image.');
        }

        $extension = self::MIME_EXTENSIONS[$mime];
        $userDirectory = $this->userDirectory($ownerId, true);

        // A 128-bit random ID keeps paths unpredictable and compact while making collisions negligible.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = bin2hex(random_bytes(16));
            $storageName = $id . '.' . $extension;
            $destination = $userDirectory . DIRECTORY_SEPARATOR . $storageName;
            if (file_exists($destination) || is_link($destination)) {
                continue;
            }
            if (!move_uploaded_file($temporaryPath, $destination)) {
                throw new RuntimeException('The uploaded file could not be moved into the data directory.');
            }

            @chmod($destination, 0600);
            $storedSize = filesize($destination);
            if ($storedSize === false || $storedSize !== $size) {
                @unlink($destination);
                throw new RuntimeException('The stored file size does not match the uploaded file.');
            }

            return [
                'id' => $id,
                'storage_name' => $storageName,
                'original_name' => $this->cleanOriginalName($upload['name'] ?? ''),
                'mime' => $mime,
                'size_bytes' => $storedSize,
            ];
        }

        throw new RuntimeException('Could not allocate a unique media filename. Please retry the upload.');
    }

    /**
     * Copy one legacy root-level image into the owner's directory without removing
     * the original. The returned database ID remains the legacy ID so callers can
     * preserve references while updating the stored filename.
     *
     * @return array{id: string, storage_name: string, original_name: string, mime: string, size_bytes: int}|null
     * @throws InvalidArgumentException If identifiers, extension, MIME type, or source image are invalid.
     * @throws RuntimeException If the legacy file cannot be read or copied.
     */
    public function importLegacyFile(
        string $ownerId,
        string $legacyId,
        string $extension,
        string $mime,
        string $originalName
    ): ?array {
        $this->assertId($ownerId, 'owner');
        if (!preg_match('/\A[a-f0-9]{64}\z/', $legacyId)) {
            throw new InvalidArgumentException('Invalid legacy media ID.');
        }
        if (!in_array($extension, ['jpg', 'png', 'gif', 'webp'], true)) {
            throw new InvalidArgumentException('Unsupported legacy file extension.');
        }
        if (!isset(self::MIME_EXTENSIONS[$mime]) || self::MIME_EXTENSIONS[$mime] !== $extension) {
            throw new InvalidArgumentException('Legacy MIME type and extension do not match.');
        }

        $source = $this->dataDirectory . DIRECTORY_SEPARATOR . $legacyId . '.' . $extension;
        if (!file_exists($source)) {
            return null;
        }
        if (is_link($source) || !is_file($source)) {
            throw new InvalidArgumentException('The legacy source must be a regular file, not a symbolic link.');
        }
        $sourcePath = realpath($source);
        if ($sourcePath === false || dirname($sourcePath) !== $this->dataDirectory) {
            throw new InvalidArgumentException('The legacy source must be directly inside the data directory.');
        }

        $size = filesize($sourcePath);
        if ($size === false || $size < 1) {
            throw new InvalidArgumentException('The legacy image is empty or unreadable.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($sourcePath) !== $mime || @getimagesize($sourcePath) === false) {
            throw new InvalidArgumentException('The legacy image content does not match its stored MIME type.');
        }

        $userDirectory = $this->userDirectory($ownerId, true);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $storageName = bin2hex(random_bytes(16)) . '.' . $extension;
            $destination = $userDirectory . DIRECTORY_SEPARATOR . $storageName;
            if (file_exists($destination) || is_link($destination)) {
                continue;
            }
            if (!@copy($sourcePath, $destination)) {
                throw new RuntimeException('The legacy image could not be copied into the user media directory.');
            }

            @chmod($destination, 0600);
            $copiedSize = filesize($destination);
            if ($copiedSize === false || $copiedSize !== $size) {
                @unlink($destination);
                throw new RuntimeException('The copied legacy image size does not match the source.');
            }

            return [
                'id' => $legacyId,
                'storage_name' => $storageName,
                'original_name' => $this->cleanOriginalName($originalName),
                'mime' => $mime,
                'size_bytes' => $copiedSize,
            ];
        }

        throw new RuntimeException('Could not allocate a unique filename for the legacy image.');
    }

    /** Return the absolute path of an existing stored file, or null if it is absent. */
    public function locate(string $ownerId, string $storageName): ?string
    {
        $this->assertId($ownerId, 'owner');
        if (!preg_match(self::STORAGE_NAME_PATTERN, $storageName)) {
            throw new InvalidArgumentException('Invalid storage name.');
        }

        $directory = $this->userDirectory($ownerId, false);
        if ($directory === null) {
            return null;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $storageName;
        if (is_link($path) || !is_file($path)) {
            return null;
        }

        return $path;
    }

    /** Delete a stored file. Returns false when the file or owner directory is absent. */
    public function delete(string $ownerId, string $storageName): bool
    {
        $path = $this->locate($ownerId, $storageName);
        if ($path === null) {
            return false;
        }

        if (!@unlink($path)) {
            throw new RuntimeException('The stored file could not be deleted.');
        }

        return true;
    }

    private function assertId(string $id, string $kind): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new InvalidArgumentException('Invalid ' . $kind . ' ID.');
        }
    }

    private function userDirectory(string $ownerId, bool $create): ?string
    {
        $usersDirectory = $this->dataDirectory . DIRECTORY_SEPARATOR . 'users';
        if (!file_exists($usersDirectory)) {
            if (!$create) {
                return null;
            }
            if (!@mkdir($usersDirectory, 0700) && !is_dir($usersDirectory)) {
                throw new RuntimeException('Could not create the users data directory.');
            }
        }
        if (is_link($usersDirectory) || !is_dir($usersDirectory)) {
            throw new RuntimeException('The users data path must be a regular directory.');
        }

        $userDirectory = $usersDirectory . DIRECTORY_SEPARATOR . $ownerId;
        if (!file_exists($userDirectory)) {
            if (!$create) {
                return null;
            }
            if (!@mkdir($userDirectory, 0700) && !is_dir($userDirectory)) {
                throw new RuntimeException('Could not create the user media directory.');
            }
        }
        if (is_link($userDirectory) || !is_dir($userDirectory)) {
            throw new RuntimeException('The user media path must be a regular directory.');
        }

        $realPath = realpath($userDirectory);
        $realUsersPath = realpath($usersDirectory);
        if ($realPath === false || $realUsersPath === false || dirname($realPath) !== $realUsersPath) {
            throw new RuntimeException('The user media directory resolves outside the users directory.');
        }

        @chmod($usersDirectory, 0700);
        @chmod($userDirectory, 0700);
        return $realPath;
    }

    private function cleanOriginalName(mixed $name): string
    {
        if (!is_string($name)) {
            return 'image';
        }

        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        if (!is_string($cleaned)) {
            return 'image';
        }

        $cleaned = trim($cleaned);
        return $cleaned === '' || $cleaned === '.' || $cleaned === '..' ? 'image' : $cleaned;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the upload limit.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the upload.',
            default => 'The upload failed for an unknown reason.',
        };
    }
}
