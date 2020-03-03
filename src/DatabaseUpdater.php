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

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Class DatabaseUpdater.
 *
 * @since   0.1.5
 *
 * @package BrightNucleus\GeoLite2Country
 * @author  Alain Schlesser <alain.schlesser@gmail.com>
 */
class DatabaseUpdater implements PluginInterface, EventSubscriberInterface
{

    /**
     * Get the event subscriber configuration for this plugin.
     *
     * @return array<string,string> The events to listen to, and their associated handlers.
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::POST_INSTALL_CMD => 'update',
            ScriptEvents::POST_UPDATE_CMD  => 'update',
        );
    }

    /**
     * Update the stored database.
     *
     * @since 0.1.0
     *
     * @param Event $event
     */
    public static function update(Event $event)
    {
        // Retrieve license from root compose package
        $composerExtra = $event->getComposer()
            ->getPackage()
            ->getExtra();
        $maxmindLicense = $composerExtra[Database::COMPOSER_EXTRA_MAXMIND_LICENSE] ?? false;

        $io = $event->getIO();

        if (!$maxmindLicense) {
            $io->write(
                '<error>No MaxMind license set in root composer.json. Please provide one under \'extra\' with key \'' . Database::COMPOSER_EXTRA_MAXMIND_LICENSE . '\'</error>'
            );

            return;
        }

        $dbFilename = Database::getLocation();
        $io->write('Making sure the DB folder exists: ' . dirname($dbFilename), true, IOInterface::VERBOSE);
        self::maybeCreateDBFolder(dirname($dbFilename));

        $oldHash = self::getHashFromFile($dbFilename . Database::HASH_EXT);
        $newHashFile = $dbFilename . Database::HASH_EXT . '.new';
        $io->write('Hash of existing local DB file: ' . $oldHash, true, IOInterface::VERBOSE);

        $io->write('Fetching remote hash...');
        $io->write(
            sprintf(
                'Downloading file: %1$s => %2$s',
                Database::HASH_URL,
                $newHashFile
            ),
            true,
            IOInterface::VERBOSE
        );
        self::downloadFile($newHashFile, Database::getHashUrl($maxmindLicense));

        $newHash = self::getHashFromFile($newHashFile);

        $io->write('Hash of current remote DB file: ' . $newHash, true, IOInterface::VERBOSE);
        if ($newHash === $oldHash) {
            $io->write(
                sprintf(
                    '<info>The local MaxMind GeoLite2 Country database is already up to date</info>. (%1$s)',
                    $dbFilename
                ),
                true
            );

            return;
        }

        // If the download was corrupted, retry three times before aborting.
        // If the update is aborted, the currently active DB file stays in place, to not break a site on failed updates.
        $retry = 3;
        $dbFilenameCompressed = $dbFilename . Database::DB_FILENAME_EXT;
        $dbFilenameUrl = Database::getDbUrl($maxmindLicense);
        while ($retry > 0) {
            $io->write('Fetching new version of the MaxMind GeoLite2 Country database...', true);
            $io->write(
                sprintf(
                    'Downloading file: %1$s => %2$s',
                    $dbFilenameUrl,
                    $dbFilenameCompressed
                ),
                true,
                IOInterface::VERBOSE
            );
            self::downloadFile($dbFilenameCompressed, $dbFilenameUrl);

            $io->write('Verifying integrity of the downloaded database file...', true);
            $downloadHash = self::calculateHash($dbFilenameCompressed);
            $io->write('Hash of downloaded DB file: ' . $downloadHash, true, IOInterface::VERBOSE);

            // Download was successful, so now we replace the existing DB file with the freshly downloaded one.
            if ($downloadHash === $newHash) {
                // We unzip into a temporary file, so as not to destroy the DB that is known to be working.
                $io->write('Extracting the database...', true);

                $io->write('Extracting file: ' . $dbFilenameCompressed . ' => ' . $dbFilename . '.tmp', true, IOInterface::VERBOSE);
                self::extractFile($dbFilenameCompressed, $dbFilename . '.extracted', true);

                $io->write('Seeking database file in extracted archive', true, IOInterface::VERBOSE);
                $dbFilenameExtracted = self::seekFilename(basename($dbFilename), $dbFilename . '.extracted');

                if (!$dbFilenameExtracted) {
                    $io->write('Could not find database file. Retrying..', true, IOInterface::VERBOSE);
                    $retry--;
                    continue;
                }

                $io->write('Moving extracted file to ' . $dbFilename, true, IOInterface::VERBOSE);
                self::renameFile($dbFilenameExtracted, $dbFilename . '.tmp');

                $io->write('Removing extracted folder file: ' . $dbFilename . '.extracted', true, IOInterface::VERBOSE);
                self::removeDirectory($dbFilename . '.extracted');

                $io->write('Replacing previous version of the database with the downloaded one...', true);

                $io->write('Removing file: ' . $dbFilename, true, IOInterface::VERBOSE);
                self::removeFile($dbFilename);

                $io->write('Removing file: ' . $dbFilename . Database::HASH_EXT, true, IOInterface::VERBOSE);
                self::removeFile($dbFilename . Database::HASH_EXT);

                $io->write('Renaming file: ' . $dbFilename . '.tmp => ' . $dbFilename, true, IOInterface::VERBOSE);
                self::renameFile($dbFilename . '.tmp', $dbFilename);

                $io->write(
                    'Renaming file: ' . $newHashFile . ' => ' . $dbFilename . Database::HASH_EXT,
                    true,
                    IOInterface::VERBOSE
                );
                self::renameFile($newHashFile, $dbFilename . Database::HASH_EXT);

                $retry = 0;
                continue;
            }

            // The download was fishy, so we remove intermediate files and retry.
            $io->write('<comment>Downloaded file did not match expected hash, retrying...</comment>', true);

            $io->write('Removing file: ' . $dbFilename . '.tmp', true, IOInterface::VERBOSE);
            self::removeFile($dbFilename . '.tmp');

            $retry--;
        }

        // Even several retries did not produce a proper download, so we remove intermediate files and let the user know
        // about the issue.
        if (!isset($downloadHash) || $downloadHash !== $newHash) {
            $io->write('Removing file: ' . $newHashFile, true, IOInterface::VERBOSE);
            self::removeFile($newHashFile);

            $io->writeError('<error>Failed to download the MaxMind GeoLite2 Country database! Aborting update.</error>');

            return;
        }

        $io->write(
            sprintf(
                '<info>The local MaxMind GeoLite2 Country database has been updated.</info> (%1$s)',
                $dbFilename
            ),
            true
        );
    }

    /**
     * Create the DB folder if it does not exist yet.
     *
     * @since 0.1.0
     *
     * @param string $folder Name of the DB folder.
     */
    protected static function maybeCreateDBFolder($folder)
    {
        if (!is_dir($folder)) {
            mkdir($folder);
        }
    }

    /**
     * Seek for the filename within a path. Can be used for finding the file in
     * the extracted folder.
     *
     * @param string $filename The filename to seek
     * @param string $path     The path to seek from
     * @return bool|mixed
     */
    protected static function seekFilename($filename, $path)
    {
        foreach (glob($path . '/*/' . $filename) as $found) {
            return $found;
        }

        return false;
    }

    /**
     * Get the content from within a file.
     *
     * @since 0.2.1
     *
     * @param string $filename Filename.
     * @return string File content.
     */
    protected static function getContents($filename)
    {
        if (!is_file($filename)) {
            return '';
        }

        return file_get_contents($filename);
    }

    /**
     * Calculate the hash of a file.
     *
     * @since 0.2.1
     *
     * @param string $filename Filename of the hash file.
     * @return string Hash contained within the file. Empty string if not found.
     */
    protected static function calculateHash($filename)
    {
        return hash(Database::HASH_ALGOR, self::getContents($filename));
    }

    /**
     * Download a file from an URL.
     *
     * @since 0.1.0
     *
     * @param string $filename Filename of the file to download.
     * @param string $url      URL of the file to download.
     */
    protected static function downloadFile($filename, $url)
    {
        $fileHandle = fopen($filename, 'w');
        $options    = [
            CURLOPT_FILE    => $fileHandle,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_URL     => $url,
        ];

        $curl = curl_init();
        curl_setopt_array($curl, $options);
        curl_exec($curl);
        curl_close($curl);
    }

    /**
     * Extract a tar.gz file
     *
     * @since 0.3.0
     *
     * @param string $source        Source, tar.gz filename to extract.
     * @param string $destination   Destination filename to write the extracted contents to.
     * @param bool   $delete        Delete acrhive(s) afterwards
     */
    protected static function extractFile($source, $destination, $delete = false)
    {
        // Decompress .gz first?
        if (stristr($source, '.gz')) {
            $archive = new \PharData($source);
            $archive->decompress();

            // Delete the archive file?
            if ($delete) {
                self::removeFile($source);
            }

            $source = str_ireplace('.gz', '', $source);
        }

        $archive = new \PharData($source);
        $archive->extractTo($destination, null, true);

        // Delete the decompressed archive?
        if ($delete) {
            self::removeFile($source);
        }
    }

    /**
     * Delete a file.
     *
     * @since 0.1.2
     * @param string $filename Filename of the file to delete.
     */
    protected static function removeFile($filename)
    {
        if (is_file($filename)) {
            unlink($filename);
        }
    }

    /**
     * Recursively remove directories
     *
     * @param string $dir
     * @return bool
     */
    protected static function removeDirectory($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? self::removeDirectory("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * Rename a file.
     *
     * @since 0.1.2
     *
     * @param string $source      Source filename of the file to rename.
     * @param string $destination Destination filename to rename the file to.
     */
    protected static function renameFile($source, $destination)
    {
        if (is_file($source)) {
            rename($source, $destination);
        }
    }

    /**
     * Retrieve hash from hash file
     *
     * @param string $file Path to filename containing the hash
     * @return bool|mixed
     */
    protected static function getHashFromFile($file)
    {
        $newHashContents = array_values(
            array_filter(
                explode(' ', self::getContents($file))
            )
        );
        return $newHashContents[0] ?? false;
    }

    /**
     * Activate the plugin.
     *
     * @since 0.1.3
     *
     * @param Composer    $composer The main Composer object.
     * @param IOInterface $io       The i/o interface to use.
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        // no action required
    }
}
