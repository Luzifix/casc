<?php

namespace Erorus\CASC;

use Erorus\CASC\DataSource\Location;

/**
 * DataSources are from where we can extract data for specific files.
 */
abstract class DataSource {
    /** @var bool True to allow extracted files with errors to remain on the filesystem, false to remove them. */
    private static $ignoreErrors = false;

    /**
     * Find a location in this data source for the given encoding hash. Null if not found.
     *
     * @param string $hash An encoding hash, in binary bytes.
     *
     * @return Location|null
     */
    abstract public function findHashInIndexes(string $hash): ?Location;

    /**
     * Given the location of some content in this data source, extract it to the given destination filesystem path.
     *
     * @param Location $locationInfo
     * @param string $destPath
     *
     * @return bool Success
     */
    abstract protected function fetchFile(Location $locationInfo, string $destPath): bool;

    /**
     * Given the location of some content in this data source, extract it to the given destination filesystem path.
     *
     * @param Location $locationInfo
     * @param string $destPath
     * @param string|null $contentHash The md5 hash of the result must match this hash. (hex md5)
     *
     * @return bool Success
     */
    public function extractFile(Location $locationInfo, string $destPath, ?string $contentHash = null): bool {
        $success = $this->fetchFile($locationInfo, $destPath);

        $success = $success && file_exists($destPath);
        $success = $success && filesize($destPath) > 0;
        $success = $success && (is_null($contentHash) || ($contentHash === md5_file($destPath)));

        if (!$success && !self::$ignoreErrors) {
            unlink($destPath);
        }

        return $success;
    }

    /**
     * Get/Set whether we ignore errors during extraction.
     *
     * @param bool $doIgnore
     *
     * @return bool The current state of whether errors are ignored.
     */
    public static function ignoreErrors(?bool $doIgnore = null): bool {
        if (!is_null($doIgnore)) {
            self::$ignoreErrors = $doIgnore;
        }

        return self::$ignoreErrors;
    }
}
