<?php

namespace Erorus\CASC\DataSource;

use Erorus\CASC\BLTE;
use Erorus\CASC\Cache;
use Erorus\CASC\DataSource;
use Erorus\CASC\DataSource\Location\TACT as TACTLocation;
use Erorus\CASC\HTTP;
use Erorus\CASC\Util;

class TACT extends DataSource {
    private $cache;

    private $indexPath = false;
    private $indexLocations = [];
    private $indexProperties = [];

    private $hashMapCache = [];

    private $hosts;
    private $cdnPath;

    const LOCATION_NONE = 0;
    const LOCATION_CACHE = 1;
    const LOCATION_WOW = 2;

    public function __construct(Cache $cache, $hosts, $cdnPath, $hashes, $wowPath = null) {
        $this->cache = $cache;
        $this->hosts = $hosts;
        $this->cdnPath = $cdnPath;

        if (!is_null($wowPath)) {
            $wowPath = rtrim($wowPath, DIRECTORY_SEPARATOR);

            $this->indexPath = sprintf('%2$s%1$sData%1$sindices', DIRECTORY_SEPARATOR, $wowPath);
            if (!is_dir($this->indexPath)) {
                fwrite(STDERR, sprintf("Could not find remote indexes locally at %s\n", $this->indexPath));
                $this->indexPath = false;
            } else {
                $this->indexPath .= DIRECTORY_SEPARATOR;
            }
        }

        foreach ($hashes as $hash) {
            if ($this->indexPath && file_exists($this->indexPath . $hash . '.index')) {
                $this->indexLocations[$hash] = static::LOCATION_WOW;
            } elseif ($cache->fileExists(static::buildCacheLocation($hash))) {
                $this->indexLocations[$hash] = static::LOCATION_CACHE;
            } else {
                $this->indexLocations[$hash] = static::LOCATION_NONE;
            }
        }

        arsort($this->indexLocations);
    }

    private static function buildCacheLocation(string $hash): string {
        return 'data/' . $hash . '.index';
    }

    /**
     * Find a location in this data source for the given encoding hash. Null if not found.
     *
     * @param string $hash An encoding hash, in binary bytes.
     *
     * @return Location|null
     */
    public function findHashInIndexes(string $hash): ?Location {
        $result = null;
        foreach ($this->indexLocations as $index => $location) {
            switch ($location) {
                case static::LOCATION_WOW:
                    $result = $this->findHashInIndex($index, $this->indexPath . $index . '.index', $hash);
                    break;
                case static::LOCATION_CACHE:
                    $result = $this->findHashInIndex($index, $this->cache->getFullPath(static::buildCacheLocation($index)), $hash);
                    break;
                case static::LOCATION_NONE:
                    if ($this->fetchIndex($index)) {
                        $result = $this->findHashInIndex($index, $this->cache->getFullPath(static::buildCacheLocation($index)), $hash);
                    }
                    break;
            }
            if ($result) {
                break;
            }
        }
        if (!$result) {
            $result = $this->findHashOnCDN($hash);
        }

        return $result;
    }

    /**
     * @param string $indexHash
     * @param string $indexPath
     * @param string $hash
     *
     * @return TACTLocation|null
     */
    private function findHashInIndex(string $indexHash, string $indexPath, string $hash): ?TACTLocation {
        $f = false;
        if (!isset($this->hashMapCache[$indexHash])) {
            $f = $this->populateIndexHashMapCache($indexHash, $indexPath);
            if ($f === false) {
                return null;
            }
        }

        $x = static::FindInMap($this->hashMapCache[$indexHash], $hash);
        if ($x < 0) {
            if ($f !== false) {
                fclose($f);
            }
            return null;
        }

        list($keySize, $blockSize) = $this->indexProperties[$indexHash];

        $empty = str_repeat(chr(0), $keySize);

        if ($f === false) {
            $f = fopen($indexPath, 'rb');
        }
        if ($f === false) {
            fwrite(STDERR, "Could not open for reading $indexPath\n");

            return null;
        }
        fseek($f, $x * $blockSize);
        for ($pos = 0; $pos < $blockSize; $pos += ($keySize + 8)) {
            $test = fread($f, $keySize);
            if ($test == $empty) {
                break;
            }
            if ($test == $hash) {
                $entry = unpack('N*', fread($f, 8));
                fclose($f);

                return new TACTLocation([
                    'archive' => $indexHash,
                    'length' => $entry[1],
                    'offset' => $entry[2],
                ]);
            }
            fseek($f, 8, SEEK_CUR);
        }
        fclose($f);

        return null;
    }

    private function populateIndexHashMapCache($indexHash, $indexPath) {
        $lof = filesize($indexPath);
        $f = fopen($indexPath, 'rb');

        $foundSize = false;
        for ($checksumSize = 16; $checksumSize >= 0; $checksumSize--) {
            $checksumSizeFieldPos = $lof - $checksumSize - 4 - 1;
            fseek($f, $checksumSizeFieldPos);
            $possibleChecksumSize = current(unpack('C', fread($f, 1)));
            if ($possibleChecksumSize == $checksumSize) {
                fseek($f, $lof - (0x14 + $checksumSize));
                $archiveNameCheck = md5(fread($f, (0x14 + $checksumSize)));

                if ($archiveNameCheck == $indexHash) {
                    $foundSize = true;
                    break;
                }
            }
        }
        if (!$foundSize) {
            fclose($f);
            fwrite(STDERR, "Could not find checksum size in $indexPath\n");
            return false;
        }

        $footerSize = 12 + $checksumSize * 3;
        $footerPos = $lof - $footerSize;

        fseek($f, $footerPos);
        $bytes = fread($f, $footerSize);

        $footer = [
            'index_block_hash' => substr($bytes, 0, $checksumSize),
            'toc_hash' => substr($bytes, $checksumSize, $checksumSize),
            'lower_md5_footer' => substr($bytes, $footerSize - $checksumSize),
        ];
        $footer = array_merge($footer, unpack('C4unk/Coffset/Csize/CkeySize/CchecksumSize/InumElements', substr($bytes, $checksumSize * 2, 12)));

        $blockSize = 4096;

        for ($blockCount = floor(($lof - $footerSize) / $blockSize);
            ($blockCount * $blockSize + static::getTocSize($footer['keySize'], $footer['checksumSize'], $blockCount)) > $footerPos;
            $blockCount--);

        $tocPosition = $blockCount * $blockSize;
        $tocSize = static::getTocSize($footer['keySize'], $footer['checksumSize'], $blockCount);
        if ($tocPosition + $tocSize != $footerPos) {
            fclose($f);
            fwrite(STDERR, "Could not place toc in $indexPath\n");
            return false;
        }

        $keySize = $footer['keySize'];

        $this->hashMapCache[$indexHash] = [];
        for ($x = 0; $x < $blockCount; $x++) {
            fseek($f, $x * $blockSize);
            $this->hashMapCache[$indexHash][$x] = fread($f, $keySize);
        }

        $this->indexProperties[$indexHash] = [$keySize, $blockSize];

        return $f;
    }

    private static function getTocSize($keySize, $checksumSize, $blockCount) {
        return ($blockCount * $keySize) + (($blockCount - 1) * $checksumSize);
    }

    private static function FindInMap($map, $needle) {
        $lo = 0;
        $hi = count($map) - 1;

        while ($lo <= $hi) {
            $mid = (int)(($hi - $lo) / 2) + $lo;
            $cmp = strcmp($map[$mid], $needle);
            if ($cmp < 0) {
                $lo = $mid + 1;
            } elseif ($cmp > 0) {
                $hi = $mid - 1;
            } else {
                return $mid;
            }
        }

        return $lo - 1;
    }

    private function fetchIndex($hash) {
        $cachePath = static::buildCacheLocation($hash);
        if ($this->cache->fileExists($cachePath)) {
            return true;
        }

        $line = " - Fetching remote index $hash ";
        echo $line, sprintf("\x1B[%dD", strlen($line));

        $oldProgressOutput = HTTP::$writeProgressToStream;
        HTTP::$writeProgressToStream = null;
        $success = false;
        foreach ($this->hosts as $host) {
            $url = Util::buildTACTUrl($host, $this->cdnPath, 'data', $hash) . '.index';

            $f = $this->cache->getWriteHandle($cachePath);
            if (is_null($f)) {
                throw new \Exception("Cannot create write handle for index file at $cachePath\n");
            }

            $success = HTTP::get($url, $f);

            fclose($f);

            if (!$success) {
                $this->cache->delete($cachePath);
            } else {
                break;
            }
        }

        HTTP::$writeProgressToStream = $oldProgressOutput;
        echo "\x1B[K";

        if (!$success) {
            return false;
        }

        $this->indexLocations[$hash] = static::LOCATION_CACHE;

        return true;
    }

    /**
     * Sometimes content won't be embedded in an archive file, and is fetched at its own URL. See if a file exists for
     * the given encoding hash, and return it as a location if one does.
     *
     * @param string $hash A binary encoding hash.
     *
     * @return TACTLocation|null
     * @throws \Exception
     */
    private function findHashOnCDN(string $hash): ?TACTLocation {
        $hash = bin2hex($hash);
        foreach ($this->hosts as $host) {
            $headers = HTTP::head(Util::buildTACTUrl($host, $this->cdnPath, 'data', $hash));
            if ($headers['responseCode'] === 200) {
                return new TACTLocation(['archive' => $hash]);
            }
        }

        return null;
    }

    /**
     * Given the location of some content in this data source, extract it to the given destination filesystem path.
     *
     * @param TACTLocation $locationInfo
     * @param string $destPath
     *
     * @return bool Success
     */
    protected function fetchFile(Location $locationInfo, string $destPath): bool {
        if (!is_a($locationInfo, TACTLocation::class)) {
            throw new \Exception("Unexpected location info object type.");
        }

        if (!Util::assertParentDir($destPath, 'output')) {
            return false;
        }

        $hash = $locationInfo->archive;
        foreach ($this->hosts as $host) {
            $url = Util::buildTACTUrl($host, $this->cdnPath, 'data', $hash);

            $writePath = 'blte://' . $destPath;
            $writeHandle = fopen($writePath, 'wb');
            if ($writeHandle === false) {
                throw new \Exception(sprintf("Unable to open %s for writing\n", $writePath));
            }

            $range = isset($locationInfo->offset) ? sprintf('%d-%d', $locationInfo->offset,
                $locationInfo->offset + $locationInfo->length - 1) : null;
            try {
                $success = HTTP::get($url, $writeHandle, $range);
            } catch (BLTE\Exception $e) {
                $success = false;
            }

            fclose($writeHandle);
            if (!$success) {
                unlink($destPath);
            } else {
                break;
            }
        }

        return !!$success;
    }
}
