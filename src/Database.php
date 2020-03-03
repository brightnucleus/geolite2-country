<?php
/**
 * Composer-packaged version of the free MaxMind GeoLite2 Country database.
 *
 * @package   BrightNucleus\GeoLite2Country
 * @author    Alain Schlesser <alain.schlesser@gmail.com>
 * @license   MIT
 * @link      http://www.brightnucleus.com/
 * @copyright 2016 Alain Schlesser, Bright Nucleus
 */

namespace BrightNucleus\GeoLite2Country;

/**
 * Class Database.
 *
 * @since   0.1.0
 *
 * @package BrightNucleus\GeoLite2Country
 * @author  Alain Schlesser <alain.schlesser@gmail.com>
 */
class Database
{
    const DB_FILENAME = 'GeoLite2-Country.mmdb';
    const DB_FILENAME_EXT = '.tar.gz';
    const DB_FOLDER = 'data';
    const DB_URL = 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=__LICENSE__&suffix=tar.gz';

    const HASH_URL = 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-Country&license_key=__LICENSE__&suffix=tar.gz.sha256';
    const HASH_ALGOR = 'sha256';
    const HASH_EXT = '.sha256';

    const VAR_PLACEHOLDER_LICENSE = '__LICENSE__';

    const COMPOSER_EXTRA_MAXMIND_LICENSE = 'maxmind-license';

    /**
     * Get the location of the database file.
     *
     * @since 0.1.0
     *
     * @param bool $array   Optional. Whether to return the location as an array. Defaults to false.
     * @return string|array Either a string, containing the absolute path to the file, or an array with the location
     *                      split up into two keys named 'folder' and 'filename'
     */
    public static function getLocation($array = false)
    {
        $folder   = realpath(__DIR__ . '/../') . '/' . self::DB_FOLDER;
        $filepath = $folder . '/' . self::DB_FILENAME;
        if (! $array) {
            return $filepath;
        }

        return [
            'folder' => $folder,
            'file'   => self::DB_FILENAME,
        ];
    }

    /**
     * @param $license
     * @return string|string[]
     */
    public static function getDbUrl($license)
    {
        return self::getLicensedUrl(self::DB_URL, $license);
    }

    /**
     * @param $license
     * @return string|string[]
     */
    public static function getHashUrl($license)
    {
        return self::getLicensedUrl(self::HASH_URL, $license);
    }

    /**
     * @param $url
     * @param $license
     * @return string|string[]
     */
    protected static function getLicensedUrl($url, $license)
    {
        return str_replace(self::VAR_PLACEHOLDER_LICENSE, $license, $url);
    }
}
