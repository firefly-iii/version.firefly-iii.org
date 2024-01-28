<?php
declare(strict_types=1);

require 'vendor/autoload.php';

// set up logger
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

// create a log channel
$log     = new Logger('release');
$handler = new StreamHandler('php://stdout', Level::Debug);
$handler->setFormatter(new LineFormatter(null, null, true, true));
$log->pushHandler($handler);

$applications = [
    'firefly-iii'   => 'https://github.com/firefly-iii/firefly-iii/releases.atom',
    'data-importer' => 'https://github.com/firefly-iii/data-importer/releases.atom',
];

$messages = [
    'firefly-iii'   => "📢 Woohoo! Version #version of Firefly III has just been released 🎉. Check it out over at GitHub, Docker, or download it using your favorite package manager.\n\n#opensource #oss #newrelease #php #software #personalfinance #selfhosted \n\n https://github.com/firefly-iii/firefly-iii/releases/#version",
    'data-importer' => "📢 Yay! A new version Firefly III Data Importer has been released. Version #version is out. Check out the release notes and download it today! #opensource #oss #newrelease #php #software #personalfinance #selfhosted \n\n https://github.com/firefly-iii/data-importer/releases/#version",
];

$existingFile = __DIR__ . '/cache/releases.json';
$information  = readExistingFile($existingFile);

$result = [];
foreach ($applications as $application => $url) {
    $log->debug(sprintf('Now looking for new releases of "%s" at "%s"', $application, $url));
    $information[$application] = processApplication($information, $application, $url);
}

saveFile($existingFile, $information);
postOnMastodon($information);
updateWebsite($information);

function updateWebsite(array $information): void
{
    global $log;
    $log->debug('Updating website.');
    $replacementKeys = [
        'firefly-iii'   => 'firefly_iii',
        'data-importer' => 'data',
    ];
    $links           = [
        'firefly_iii' => 'https://github.com/firefly-iii/firefly-iii/releases/tag/%s',
        'data'        => 'https://github.com/firefly-iii/data-importer/releases/tag/%s',
    ];
    $result = [];
    foreach ($information as $key => $entries) {
        $log->debug(sprintf('Now processing %s', $key));
        // fallback versions
        $stable = [
            'version'     => 'v0.1',
            'date'        => null,
            'from_github' => false,
            'link'        => null,
            'is_stable'   => null,
            'is_beta'     => null,
            'is_alpha'    => null,
        ];

        $beta = [
            'version'     => 'v0.1-beta.0',
            'date'        => null,
            'from_github' => false,
            'link'        => null,
            'is_stable'   => null,
            'is_beta'     => null,
            'is_alpha'    => null,
        ];

        $alpha = [
            'version'     => 'v0.1-alpha.0',
            'date'        => null,
            'from_github' => false,
            'link'        => null,
            'is_stable'   => null,
            'is_beta'     => null,
            'is_alpha'    => null,
        ];
        $replacementKey = $replacementKeys[$key];
        foreach ($entries as $release) {
            $currentVersion = $release['title'];
            $log->debug(sprintf('Now processing %s, version %s', $key, $currentVersion));
            $isAlpha = str_contains($release['title'], 'alpha');
            $isBeta  = str_contains($release['title'], 'beta');

            // is develop nightly? Then skip.
            if (str_contains($release['title'], 'dev-release')) {
                $log->debug(sprintf('Skipping develop version "%s"', $release['title']));
                continue;
            }


            // find stable release in array:
            if (isNewestVersion($currentVersion, $stable['version']) && !$isAlpha && !$isBeta) {
                $log->debug(sprintf('Found a stable version for %s: %s (%s).', $replacementKey, $release['title'], $release['updated']));
                $stable['version']     = $release['title'];
                $stable['date']        = substr($release['updated'], 0, 10);
                $stable['from_github'] = true;
                $stable['link']        = sprintf($links[$replacementKey], $stable['version']);
                $stable['is_stable']   = true;
            }

            // set beta if beta not yet set, and the release is a beta, and is not an alpha.
            if (isNewestVersion($currentVersion, $beta['version']) && !$isAlpha && $isBeta) {
                $log->debug(sprintf('Found a BETA version for %s: %s (%s).', $replacementKey, $release['title'], $release['updated']));
                $beta['version']     = $release['title'];
                $beta['date']        = substr($release['updated'], 0, 10);
                $beta['from_github'] = true;
                $beta['link']        = sprintf($links[$replacementKey], $beta['version']);
                $beta['is_beta']     = true;
            }
            // set alpha is the release is an alpha and the alpha release has not yet been set.
            if (isNewestVersion($currentVersion, $alpha['version']) && $isAlpha && !$isBeta) {
                $log->debug(sprintf('Found an ALPHA version for %s: %s (%s).', $replacementKey, $release['title'], $release['updated']));
                $alpha['version']     = $release['title'];
                $alpha['date']        = substr($release['updated'], 0, 10);
                $alpha['from_github'] = true;
                $alpha['link']        = sprintf($links[$replacementKey], $alpha['version']);
                $alpha['is_alpha']    = true;
            }
        }
        $dateStable = new Carbon($stable['date']);
        $dateBeta   = new Carbon($beta['date']);
        $dateAlpha  = new Carbon($alpha['date']);

        $log->debug(sprintf('Carbon (stable): %s', $dateStable->toW3cString()));
        $log->debug(sprintf('Carbon (beta)  : %s', $dateBeta->toW3cString()));
        $log->debug(sprintf('Carbon (alpha) : %s', $dateAlpha->toW3cString()));

        // overrule beta with stable if no beta:
        if (false === $beta['from_github'] && false !== $stable['from_github']) {
            $log->info(sprintf('There was no beta for %s, so %s is now beta version.', $replacementKey, $stable['version']));
            $beta     = $stable;
            $dateBeta = clone $dateStable;
        }
        // overrule alpha with beta if no alpha:
        if (false === $alpha['from_github'] && false !== $beta['from_github']) {
            $log->info(sprintf('There was no alpha for %s, so %s is now alpha version.', $replacementKey, $beta['version']));
            $alpha     = $beta;
            $dateAlpha = clone $dateBeta;
        }

        // overrule beta with stable if stable is newer (and released after beta).
        if (null !== $stable['version'] && 1 === version_compare($stable['version'], $beta['version']) && $dateStable->gte($dateBeta)) {
        $log->info(
            sprintf(
                'Stable version %s (%s) is newer than beta version %s (%s), so stable version %s is now also beta version of %s.',
                $stable['version'], $dateStable->format('Y-m-d'),
                $beta['version'], $dateBeta->format('Y-m-d'),
                $stable['version'], $replacementKey
            )
        );
            $beta     = $stable;
            $dateBeta = clone $dateStable;
        }
    $log->debug(
        sprintf(
            'Compare beta (%s) with alpha (%s): %d',
            $beta['version'], $alpha['version'], version_compare((string) $beta['version'], (string) $alpha['version'])
        )
    );
    $log->debug(sprintf('beta date: %s', $dateBeta->toW3cString()));
    $log->debug(sprintf('alpha date: %s', $dateAlpha->toW3cString()));
    $log->debug(sprintf('Date compare beta greaterThanOrEqualTo alpha: %s', var_export($dateBeta->gte($dateAlpha), true)));

        // overrule alpha with beta, if beta is newer (and released after alpha):
        if (1 === version_compare((string) $beta['version'], (string) $alpha['version']) && $dateBeta->gte($dateAlpha)) {
        $log->info(
            sprintf(
                'Beta version %s (%s) is newer than alpha version %s (%s), so %s is now alpha version of %s.',
                $beta['version'], $dateBeta->format('Y-m-d'),
                $alpha['version'], $dateAlpha->format('Y-m-d'),
                $beta['version'], $replacementKey
            )
        );
            $alpha     = $beta;
            $dateAlpha = clone $dateBeta;
        }

        $result[$replacementKey] = [
            'stable' => $stable,
            'beta'   => $beta,
            'alpha'  => $alpha,
        ];
    }
    file_put_contents('./site/index.json', json_encode($result, JSON_PRETTY_PRINT));
}

function isNewestVersion($currentVersion, $previousVersion): bool {
    global $log;
    // strip 'v' from the version.
    if (str_starts_with($currentVersion, 'v')) {
        $currentVersion = substr($currentVersion, 1);
    }
    // strip 'v' from the version.
    if (str_starts_with($previousVersion, 'v')) {
        $previousVersion = substr($previousVersion, 1);
    }
    $result = version_compare($currentVersion, $previousVersion);
    $log->debug(sprintf('Compare %s with %s: %d', $currentVersion, $previousVersion, $result));
    return 1 === $result;
}

/**
 * @param array  $information
 * @param string $application
 * @param string $url
 *
 * @return array
 */
function processApplication(array $information, string $application, string $url): array
{
    global $log;
    $client = new Client();

    try {
        $response = $client->request('GET', $url);
    } catch (GuzzleException $e) {
        $log->error(sprintf('Response for %s is %s.', $url, $e->getMessage()));

        return [];
    }
    if (200 !== $response->getStatusCode()) {
        $log->error(sprintf('Response for %s is %d.', $url, $response->getStatusCode()));

        return [];
    }
    $body = (string) $response->getBody();
    return processApplicationBody($information, $application, $body);
}

/**
 * @param array  $information
 * @param string $application
 * @param string $body
 *
 * @return array
 */
function processApplicationBody(array $information, string $application, string $body): array
{
    global $log;
    try {
        $releaseXml = new \SimpleXMLElement($body, LIBXML_NOCDATA);
    } catch (\Exception $e) {
        $log->error($e->getMessage());

        return [];
    }
    if (isset($releaseXml->entry)) {
        foreach ($releaseXml->entry as $entry) {
            $title   = (string) $entry->title;
            $content = (string) $entry->content;

            // remove HTML tags (g-emoji)
            $content = strip_tags($content, ['h3', 'ul', 'li', 'a', 'strong', 'code', 'h2']);

            $array = [
                'id'                 => (string) $entry->id,
                'updated'            => substr((string) $entry->updated, 0, 10),
                'title'              => $title,
                'content'            => $content,
                'announced_mastodon' => false,
            ];
            if (!array_key_exists($application, $information)) {
                $information[$application] = [];
            }
            if (!array_key_exists($title, $information[$application])) {
                $information[$application][$title] = $array;
                $log->info(sprintf('Learned of new %s version %s', $application, $title));
            }
        }
    }
    return $information[$application];
}

function readExistingFile(string $existingFile): array
{
    global $log;
    if (!file_exists($existingFile)) {
        $log->warning(sprintf('File "%s" does not exist. Create it and return [].', $existingFile));
        $content = json_encode([], JSON_PRETTY_PRINT);
        file_put_contents($existingFile, $content);
        return [];
    }
    $content = file_get_contents($existingFile);
    if (false !== json_decode($content)) {
        $log->debug('Found valid JSON, return it.');
        return json_decode($content, true);
    }
    $log->warning(sprintf('File "%s" contains invalid JSON. Recreate it and return [].', $existingFile));
    $content = json_encode([], JSON_PRETTY_PRINT);
    file_put_contents($existingFile, $content);
    return [];
}

/**
 * @param string $existingFile
 * @param array  $information
 *
 * @return void
 */
function saveFile(string $existingFile, array $information): void
{
    file_put_contents($existingFile, json_encode($information, JSON_PRETTY_PRINT));
}

function postOnMastodon(array $information): void
{
    global $log, $existingFile;
    $log->debug('Now posting on Mastodon');
    /**
     * @var string $application
     * @var array  $versions
     */
    foreach ($information as $application => $versions) {
        $log->debug(sprintf('Know of %d %s versions.', count($versions), $application));
        $newestVersion = getNewestVersion($versions);
        $log->debug(sprintf('Newest version is %s', $newestVersion));

        if (!array_key_exists($newestVersion, $versions)) {
            $log->error(sprintf('Unexpected cant find version %s', $newestVersion));

            return;
        }

        // skip beta and alpha versions
        if (str_contains($newestVersion, 'alpha') || str_contains($newestVersion, 'beta')) {
            $log->debug(sprintf('Skip version %s', $newestVersion));

            continue;
        }

        $result = postNewVersion($information, $application, $newestVersion);

        // only post one version at a time, so if the bot posted, return, and ultimately quit.
        if (true === $result) {
            $log->debug('Posted on Mastodon, do nothing else.');

            // update array
            $information[$application][$newestVersion]['announced_mastodon'] = true;
            saveFile($existingFile, $information);

            return;
        }
    }
}

/**
 * @param array $versions
 *
 * @return string
 */
function getNewestVersion(array $versions): string
{
    $version = '0.1';
    foreach (array_keys($versions) as $key) {
        $original = $key;
        // strip 'v' from the version.
        if (str_starts_with($key, 'v')) {
            $key = substr($key, 1);
        }
        $checkVersion = $version;
        if (str_starts_with($version, 'v')) {
            $checkVersion = substr($version, 1);
        }
        if (-1 === version_compare($checkVersion, $key)) {
            $version = $original;
        }
    }

    return $version;
}


/**
 * @param array  $information
 * @param string $application
 * @param string $version
 *
 * @return bool
 */
function postNewVersion(array $information, string $application, string $version): bool
{
    global $log;
    $array     = $information[$application][$version];
    $announced = $array['announced_mastodon'] ?? false;
    if (false === $announced) {
        $log->debug(sprintf('Going to announce %s %s on Mastodon.', $application, $version));
        announceMastodon($application, $array);

        return true;
    }

    if (true === $announced) {
        $log->debug(sprintf('Already announced %s %s on Mastodon.', $application, $version));
    }


    return false;
}

function announceMastodon(string $application, array $info): void
{
    global $log, $messages;
    $url   = (string) getenv('MASTODON_URL');
    $token = (string) getenv('MASTODON_TOKEN');
    if ('' === $url) {
        $log->error('No MASTODON_URL found in environment.');
        exit;
    }
    if ('' === $token) {
        $log->error('No MASTODON_TOKEN found in environment.');
        exit;
    }

    $toot = (string) $messages[$application];
    $toot = str_replace('#version', $info['title'], $toot);

    // post it manually:
    $full   = sprintf('%s/api/v1/statuses', $url);
    $client = new Client();

    $options = [
        'headers'     => [
            'Authorization' => sprintf('Bearer %s', $token),
        ],
        'form_params' => [
            'status' => $toot,
        ],
    ];

    $res  = $client->post($full, $options);
    $body = (string) $res->getBody();
    $json = json_decode($body, true);
//    $json = ['url' => 'fake'];

    $log->info(sprintf('Posted on Mastodon! %s', $json['url']));
}
