<?php

declare(strict_types=1);

define('APP_BOOTSTRAPPED', true);
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function text_length(string $value): int
{
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

function truncate_text(string $value, int $length): string
{
    if (text_length($value) <= $length) {
        return $value;
    }

    preg_match_all('/./us', $value, $matches);
    return implode('', array_slice($matches[0], 0, $length));
}

function body_string(array $body, string $key): string
{
    $value = $body[$key] ?? '';
    return is_scalar($value) ? (string) $value : '';
}

function query_string(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function create_token(array $user, string $secret): string
{
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
    $payload = base64url_encode(json_encode([
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'role' => $user['role'] ?? 'member',
        'exp' => time() + 604800,
    ], JSON_THROW_ON_ERROR));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));

    return "$header.$payload.$signature";
}

function authenticated_user(string $secret, PDO $pdo): array
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authorization && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
        respond(401, ['error' => 'Sign in to continue.']);
    }

    $parts = explode('.', $matches[1]);
    if (count($parts) !== 3) {
        respond(401, ['error' => 'Your session has expired. Please sign in again.']);
    }

    [$headerPart, $payloadPart, $signaturePart] = $parts;
    $header = json_decode(base64_decode(strtr($headerPart, '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($payloadPart, '-_', '+/')), true);
    $expected = base64url_encode(hash_hmac('sha256', "$headerPart.$payloadPart", $secret, true));

    if (
        !is_array($header)
        || ($header['alg'] ?? null) !== 'HS256'
        || !is_array($payload)
        || !isset($payload['id'], $payload['exp'])
        || !is_numeric($payload['id'])
        || (int) $payload['exp'] <= time()
        || !hash_equals($expected, $signaturePart)
    ) {
        respond(401, ['error' => 'Your session has expired. Please sign in again.']);
    }

    $stmt = $pdo->prepare('SELECT id, name, username, email, role, avatar_url AS avatarUrl, created_at AS createdAt FROM users WHERE id = ?');
    $stmt->execute([(int) $payload['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(401, ['error' => 'Account not found.']);
    }

    $user['id'] = (int) $user['id'];
    return $user;
}

function require_role(array $user, array $allowedRoles): void
{
    if (!in_array($user['role'] ?? 'member', $allowedRoles, true)) {
        respond(403, ['error' => 'You do not have permission to perform this action.']);
    }
}

function ensure_title_metadata_columns(PDO $pdo): void
{
    $columns = [
        'catalog_rating' => 'DECIMAL(3,1) NULL',
        'genres' => 'TEXT NULL',
        'tropes' => 'TEXT NULL',
        'tags' => 'TEXT NULL',
    ];
    $check = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );

    foreach ($columns as $name => $definition) {
        $check->execute(['titles', $name]);
        if (!$check->fetch()) {
            try {
                $pdo->exec("ALTER TABLE titles ADD COLUMN `{$name}` {$definition}");
            } catch (PDOException $error) {
                if ($error->getCode() !== '42S21' && (int) ($error->errorInfo[1] ?? 0) !== 1060) {
                    throw $error;
                }
            }
        }
    }
}

function title_metadata_column_exists(PDO $pdo, string $column): bool
{
    $check = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute(['titles', $column]);
    return (bool) $check->fetch();
}

function is_valid_kind(string $kind): bool
{
    return in_array($kind, ['book', 'movie', 'tv', 'podcast', 'game', 'other'], true);
}

function is_valid_status(string $status): bool
{
    return in_array($status, ['planned', 'in_progress', 'completed', 'dropped'], true);
}

function is_valid_post_category(string $category): bool
{
    return in_array($category, ['recommendation', 'discussion', 'likes_dislikes', 'general'], true);
}

function http_get_json(string $url): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; IsItTooSpicy/2.1; +https://sublyime.com)',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw !== false && $code >= 200 && $code < 300) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }
    return null;
}

function clean_description(?string $html): ?string
{
    if ($html === null || trim($html) === '') {
        return null;
    }
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/\s+/u', ' ', trim($text));
    return $text !== '' ? truncate_text($text, 1400) : null;
}

function normalize_search_punctuation(string $input): string
{
    $replacements = [
        '/\bdont\b/i'        => "don't",
        '/\bcant\b/i'        => "can't",
        '/\bwont\b/i'        => "won't",
        '/\bdidnt\b/i'       => "didn't",
        '/\bisnt\b/i'        => "isn't",
        '/\barent\b/i'       => "aren't",
        '/\bwasnt\b/i'       => "wasn't",
        '/\bwerent\b/i'      => "weren't",
        '/\bhasnt\b/i'       => "hasn't",
        '/\bhavent\b/i'      => "haven't",
        '/\bhadnt\b/i'       => "hadn't",
        '/\bwouldnt\b/i'     => "wouldn't",
        '/\bcouldnt\b/i'     => "couldn't",
        '/\bshouldnt\b/i'    => "shouldn't",
        '/\babysitters\b/i'  => "babysitter's",
        '/\bmoms\b/i'        => "mom's",
    ];
    return (string) preg_replace(array_keys($replacements), array_values($replacements), $input);
}

function fetch_external_media_metadata(string $kind, ?string $isbnInput, ?string $queryInput): ?array
{
    $rawIsbn = trim($isbnInput ?? '');
    $rawQuery = trim($queryInput ?? '');

    $cleanCandidate = (string) preg_replace('/[\s-]/u', '', $rawIsbn);
    $isRealIsbn = (bool) preg_match('/^(?:\d{9}[\dXx]|\d{13})$/', $cleanCandidate);

    $searchTerm = $rawQuery !== '' ? $rawQuery : (!$isRealIsbn ? $rawIsbn : '');
    $cleanIsbn = $isRealIsbn ? $cleanCandidate : null;

    if ($cleanIsbn === null && $searchTerm !== '') {
        $titleCandidate = (string) preg_replace('/[\s-]/u', '', $searchTerm);
        if (preg_match('/^(?:\d{9}[\dXx]|\d{13})$/', $titleCandidate)) {
            $cleanIsbn = $titleCandidate;
        }
    }

    if ($kind === 'book') {
        if ($cleanIsbn !== null) {
            $olUrl = "https://openlibrary.org/api/books?bibkeys=ISBN:{$cleanIsbn}&format=json&jscmd=data";
            $olData = http_get_json($olUrl);
            $key = "ISBN:{$cleanIsbn}";
            if (isset($olData[$key])) {
                $book = $olData[$key];
                $authors = [];
                if (!empty($book['authors'])) {
                    foreach ($book['authors'] as $a) {
                        if (!empty($a['name'])) {
                            $authors[] = $a['name'];
                        }
                    }
                }
                $coverUrl = $book['cover']['large'] ?? $book['cover']['medium'] ?? $book['cover']['small'] ?? null;
                $publishDate = $book['publish_date'] ?? '';
                preg_match('/\b\d{4}\b/', $publishDate, $yearMatch);

                $desc = null;
                if (!empty($book['notes'])) {
                    $desc = is_string($book['notes']) ? $book['notes'] : ($book['notes']['value'] ?? null);
                }

                return [
                    'title'       => $book['title'] ?? '',
                    'kind'        => 'book',
                    'creator'     => implode(', ', $authors),
                    'isbn'        => $cleanIsbn,
                    'barcode'     => null,
                    'releaseYear' => isset($yearMatch[0]) ? (int) $yearMatch[0] : null,
                    'coverUrl'    => $coverUrl,
                    'description' => clean_description($desc),
                ];
            }
        }

        $bookQuery = $cleanIsbn ?? $searchTerm;
        if ($bookQuery !== '') {
            $ibooksUrl = 'https://itunes.apple.com/search?term=' . urlencode($bookQuery) . '&entity=ebook&limit=5';
            $ibooksData = http_get_json($ibooksUrl);
            if (!empty($ibooksData['results'][0])) {
                $ebook = $ibooksData['results'][0];
                $art = $ebook['artworkUrl100'] ?? null;
                $hiresCover = $art ? str_replace('100x100bb.jpg', '600x600bb.jpg', $art) : null;
                $year = !empty($ebook['releaseDate']) ? (int) substr($ebook['releaseDate'], 0, 4) : null;

                return [
                    'title'       => $ebook['trackName'] ?? $ebook['collectionName'] ?? $bookQuery,
                    'kind'        => 'book',
                    'creator'     => $ebook['artistName'] ?? null,
                    'isbn'        => $cleanIsbn,
                    'barcode'     => null,
                    'releaseYear' => $year,
                    'coverUrl'    => $hiresCover,
                    'description' => clean_description($ebook['description'] ?? null),
                ];
            }
        }

        if ($searchTerm !== '') {
            $olSearchUrl = 'https://openlibrary.org/search.json?title=' . urlencode($searchTerm) . '&limit=1';
            $olSearch = http_get_json($olSearchUrl);
            if (empty($olSearch['docs'][0])) {
                $olSearchUrl = 'https://openlibrary.org/search.json?q=' . urlencode($searchTerm) . '&limit=1';
                $olSearch = http_get_json($olSearchUrl);
            }
            if (!empty($olSearch['docs'][0])) {
                $doc = $olSearch['docs'][0];
                $coverId = $doc['cover_i'] ?? null;
                $coverUrl = $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg" : null;
                $docIsbn = $cleanIsbn;
                if ($docIsbn === null && !empty($doc['isbn']) && is_array($doc['isbn'])) {
                    foreach ($doc['isbn'] as $candidate) {
                        $cleaned = (string) preg_replace('/[\s-]/u', '', (string) $candidate);
                        if (preg_match('/^(?:\d{9}[\dXx]|\d{13})$/', $cleaned)) {
                            $docIsbn = $cleaned;
                            break;
                        }
                    }
                }

                return [
                    'title'       => $doc['title'] ?? $searchTerm,
                    'kind'        => 'book',
                    'creator'     => isset($doc['author_name']) ? implode(', ', (array) $doc['author_name']) : null,
                    'isbn'        => $docIsbn,
                    'barcode'     => null,
                    'releaseYear' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
                    'coverUrl'    => $coverUrl,
                    'description' => null,
                ];
            }
        }

        if ($bookQuery !== '') {
            $gbUrl = 'https://www.googleapis.com/books/v1/volumes?q=' . urlencode($bookQuery) . '&maxResults=1';
            $gbData = http_get_json($gbUrl);
            if (!empty($gbData['items'][0]['volumeInfo'])) {
                $vol = $gbData['items'][0]['volumeInfo'];
                $thumb = $vol['imageLinks']['thumbnail'] ?? null;
                if ($thumb !== null) {
                    $thumb = str_replace('http://', 'https://', $thumb);
                }
                $year = null;
                if (!empty($vol['publishedDate']) && preg_match('/\b(\d{4})\b/', $vol['publishedDate'], $ym)) {
                    $year = (int) $ym[1];
                }
                return [
                    'title'       => $vol['title'] ?? $bookQuery,
                    'kind'        => 'book',
                    'creator'     => !empty($vol['authors']) ? implode(', ', (array) $vol['authors']) : null,
                    'isbn'        => $cleanIsbn,
                    'barcode'     => null,
                    'releaseYear' => $year,
                    'coverUrl'    => $thumb,
                    'description' => clean_description($vol['description'] ?? null),
                ];
            }
        }

        return null;
    }

    $mediaQuery = $searchTerm !== '' ? $searchTerm : $rawIsbn;
    if ($mediaQuery === '') {
        return null;
    }

    $normalizedQuery = normalize_search_punctuation($mediaQuery);
    $queriesToTry = array_unique([$normalizedQuery, $mediaQuery]);

    $entityMap = [
        'movie'   => 'movie',
        'tv'      => 'tvSeason',
        'podcast' => 'podcast',
        'game'    => 'software',
        'other'   => 'movie',
    ];
    $attributeMap = [
        'movie'   => 'movieTerm',
        'tv'      => 'tvSeasonTerm',
        'podcast' => 'titleTerm',
    ];
    $entity = $entityMap[$kind] ?? 'movie';
    $attr = $attributeMap[$kind] ?? '';

    foreach ($queriesToTry as $qAttempt) {
        $attrParam = $attr !== '' ? "&attribute={$attr}" : '';
        $itunesUrl = 'https://itunes.apple.com/search?term=' . urlencode($qAttempt) . "&entity={$entity}{$attrParam}&limit=3";
        $itunesData = http_get_json($itunesUrl);

        if (empty($itunesData['results'][0]) && $attrParam !== '') {
            $itunesUrl = 'https://itunes.apple.com/search?term=' . urlencode($qAttempt) . "&entity={$entity}&limit=3";
            $itunesData = http_get_json($itunesUrl);
        }

        if (!empty($itunesData['results'][0])) {
            $item = $itunesData['results'][0];
            $art = $item['artworkUrl100'] ?? null;
            $hiresCover = $art ? str_replace('100x100bb.jpg', '600x600bb.jpg', $art) : null;
            $year = !empty($item['releaseDate']) ? (int) substr($item['releaseDate'], 0, 4) : null;

            return [
                'title'       => $item['trackName'] ?? $item['collectionName'] ?? $qAttempt,
                'kind'        => $kind,
                'creator'     => $item['artistName'] ?? null,
                'isbn'        => null,
                'barcode'     => null,
                'releaseYear' => $year,
                'coverUrl'    => $hiresCover,
                'description' => clean_description($item['longDescription'] ?? $item['description'] ?? null),
            ];
        }
    }

    if (in_array($kind, ['tv', 'other'], true)) {
        $tvUrl = 'https://api.tvmaze.com/singlesearch/shows?q=' . urlencode($normalizedQuery);
        $tvData = http_get_json($tvUrl);
        if (!empty($tvData['name'])) {
            $year = !empty($tvData['premiered']) ? (int) substr($tvData['premiered'], 0, 4) : null;
            $cover = $tvData['image']['original'] ?? $tvData['image']['medium'] ?? null;
            return [
                'title'       => $tvData['name'],
                'kind'        => $kind,
                'creator'     => $tvData['network']['name'] ?? $tvData['webChannel']['name'] ?? null,
                'isbn'        => null,
                'barcode'     => null,
                'releaseYear' => $year,
                'coverUrl'    => $cover,
                'description' => clean_description($tvData['summary'] ?? null),
            ];
        }
    }

    if (in_array($kind, ['movie', 'tv', 'game', 'other'], true)) {
        $wikiSearchUrl = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch='
            . urlencode($normalizedQuery . ' (' . $kind . ')')
            . '&utf8=&format=json&srlimit=1';
        $wikiSearch = http_get_json($wikiSearchUrl);
        if (!empty($wikiSearch['query']['search'][0]['title'])) {
            $pageTitle = $wikiSearch['query']['search'][0]['title'];
            $summaryUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $pageTitle));
            $summary = http_get_json($summaryUrl);
            if (!empty($summary['title'])) {
                $extract = $summary['extract'] ?? '';
                $year = null;
                if (preg_match('/\b(19\d{2}|20\d{2})\b/', $extract, $ym)) {
                    $year = (int) $ym[1];
                }
                $creator = null;
                if (preg_match('/directed by ([A-Z][a-z]+ [A-Z][a-z]+)/', $extract, $dm)) {
                    $creator = $dm[1];
                }
                $cleanTitle = (string) preg_replace('/\s*\([^)]*\)\s*$/', '', $summary['title']);

                return [
                    'title'       => $cleanTitle,
                    'kind'        => $kind,
                    'creator'     => $creator,
                    'isbn'        => null,
                    'barcode'     => null,
                    'releaseYear' => $year,
                    'coverUrl'    => $summary['originalimage']['source'] ?? $summary['thumbnail']['source'] ?? null,
                    'description' => clean_description($extract),
                ];
            }
        }
    }

    return null;
}

try {
    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        throw new RuntimeException('Configuration file config.php not found.');
    }

    $config = require $configFile;
    if (!is_array($config)) {
        throw new RuntimeException('API configuration must return an array.');
    }

    foreach (['mysql_host', 'mysql_database', 'mysql_user', 'mysql_password', 'jwt_secret'] as $required) {
        if (!isset($config[$required]) || $config[$required] === '') {
            throw new RuntimeException("Missing API configuration value: $required");
        }
    }

    $pdo = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['mysql_host'],
            (int) ($config['mysql_port'] ?? 3306),
            $config['mysql_database']
        ),
        $config['mysql_user'],
        $config['mysql_password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // Ensure settings table exists
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(64) PRIMARY KEY,
            `value` TEXT NOT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $route = is_string($_GET['route'] ?? null) ? $_GET['route'] : '';
    if ($route === '') {
        respond(400, ['error' => 'Invalid API route.']);
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody !== false && strlen($rawBody) > 10485760) { // Support bulk payloads up to 10MB
        respond(413, ['error' => 'Request payload is too large.']);
    }

    try {
        $body = ($rawBody === false || $rawBody === '') ? [] : json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'Request body must contain valid JSON.']);
    }

    if (!is_array($body)) {
        respond(400, ['error' => 'Request body must be a JSON object.']);
    }

    // --- Health Check ---
    if ($method === 'GET' && $route === '/health') {
        $pdo->query('SELECT 1');
        respond(200, ['status' => 'ok']);
    }

    // --- App Settings Routes ---
    if ($method === 'GET' && $route === '/settings') {
        $stmt = $pdo->query('SELECT `key`, `value` FROM app_settings');
        $rows = $stmt->fetchAll();
        $settings = [
            'allow_emojis' => '1',
            'allow_gifs'   => '1',
        ];
        foreach ($rows as $row) {
            $settings[$row['key']] = (string) $row['value'];
        }
        respond(200, ['settings' => $settings]);
    }

    if ($method === 'PATCH' && $route === '/admin/settings') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $key = trim(body_string($body, 'key'));
        $value = trim(body_string($body, 'value'));

        if (!in_array($key, ['allow_emojis', 'allow_gifs'], true)) {
            respond(400, ['error' => 'Invalid setting key.']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (`key`, `value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `updated_at` = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$key, $value]);

        respond(200, ['message' => "Setting {$key} updated to {$value}."]);
    }

    // --- External & Catalog Metadata Lookup ---
    if ($method === 'GET' && $route === '/metadata/lookup') {
        $kind = query_string('kind');
        $isbn = query_string('isbn');
        $q = query_string('q');
        $externalOnly = query_string('external') === '1';

        if ($kind === '') {
            respond(400, ['error' => 'Media kind is required.']);
        }

        if ($isbn !== '' && !$externalOnly) {
            $cleanIsbn = (string) preg_replace('/[\s-]/u', '', $isbn);
            $stmt = $pdo->prepare('SELECT id, title, kind, creator, isbn, barcode, release_year AS releaseYear, cover_url AS coverUrl, description FROM titles WHERE isbn = ? OR barcode = ? LIMIT 1');
            $stmt->execute([$cleanIsbn, $cleanIsbn]);
            $existing = $stmt->fetch();
            if ($existing) {
                respond(200, ['source' => 'catalog', 'title' => $existing]);
            }
        }

        $external = fetch_external_media_metadata($kind, $isbn, $q);
        if ($external !== null) {
            respond(200, ['source' => 'external', 'title' => $external]);
        }

        respond(404, ['error' => 'No title metadata found. Try refining the title or entering details manually.']);
    }

    // --- ADMIN BULK IMPORT & DYNAMIC SCHEMA REVIEW ROUTES ---

    // 1. Dry Run / Schema Analysis: Checks for missing columns and duplicate records
    if ($method === 'POST' && $route === '/admin/media/bulk-analyze') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $headers = $body['headers'] ?? [];
        $rows = $body['rows'] ?? [];

        if (!is_array($headers) || empty($headers) || !is_array($rows) || empty($rows)) {
            respond(400, ['error' => 'Valid headers and rows array are required for analysis.']);
        }

        // Fetch current columns in titles table
        $colStmt = $pdo->query('SHOW COLUMNS FROM titles');
        $existingCols = [];
        while ($col = $colStmt->fetch()) {
            $existingCols[strtolower((string)$col['Field'])] = true;
        }

        // Standard canonical fields map
        $canonicalMap = [
            'title'        => 'title',
            'name'         => 'title',
            'kind'         => 'kind',
            'type'         => 'kind',
            'media_type'   => 'kind',
            'creator'      => 'creator',
            'author'       => 'creator',
            'director'     => 'creator',
            'artist'       => 'creator',
            'isbn'         => 'isbn',
            'barcode'      => 'barcode',
            'upc'          => 'barcode',
            'ean'          => 'barcode',
            'release_year' => 'release_year',
            'year'         => 'release_year',
            'released'     => 'release_year',
            'cover_url'    => 'cover_url',
            'cover'        => 'cover_url',
            'image'        => 'cover_url',
            'description'  => 'description',
            'synopsis'     => 'description',
            'summary'      => 'description'
        ];

        $missingColumns = [];
        $sanitizedHeaders = [];

        foreach ($headers as $h) {
            $rawHeader = trim((string)$h);
            $cleanKey = strtolower((string)preg_replace('/[^a-zA-Z0-9_]/', '_', $rawHeader));
            $cleanKey = trim($cleanKey, '_');
            if ($cleanKey === '') {
                continue;
            }

            $mapped = $canonicalMap[$cleanKey] ?? $cleanKey;
            $sanitizedHeaders[$rawHeader] = $mapped;

            if (!isset($existingCols[$mapped])) {
                $missingColumns[$mapped] = [
                    'original_header' => $rawHeader,
                    'column_name'     => $mapped,
                    'suggested_type'  => 'TEXT NULL',
                    'alter_sql'       => "ALTER TABLE titles ADD COLUMN `{$mapped}` TEXT NULL",
                ];
            }
        }

        // Scan rows for duplicate entries in database
        $totalRows = count($rows);
        $newRecords = 0;
        $duplicateRecords = 0;
        $sampleItems = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) continue;

            $item = [];
            foreach ($row as $k => $v) {
                $mappedKey = $sanitizedHeaders[$k] ?? null;
                if ($mappedKey) {
                    $item[$mappedKey] = is_scalar($v) ? trim((string)$v) : '';
                }
            }

            $itemTitle = $item['title'] ?? '';
            $itemCreator = $item['creator'] ?? '';
            $itemIsbn = (string)preg_replace('/[\s-]/u', '', $item['isbn'] ?? '');
            $itemBarcode = trim($item['barcode'] ?? '');

            if ($itemTitle === '') {
                continue;
            }

            // Check if title already exists by ISBN, barcode, or exact Title + Creator match
            $isDuplicate = false;
            if ($itemIsbn !== '') {
                $dupQuery = $pdo->prepare('SELECT id FROM titles WHERE isbn = ? LIMIT 1');
                $dupQuery->execute([$itemIsbn]);
                if ($dupQuery->fetch()) $isDuplicate = true;
            }

            if (!$isDuplicate && $itemBarcode !== '') {
                $dupQuery = $pdo->prepare('SELECT id FROM titles WHERE barcode = ? LIMIT 1');
                $dupQuery->execute([$itemBarcode]);
                if ($dupQuery->fetch()) $isDuplicate = true;
            }

            if (!$isDuplicate) {
                if ($itemCreator !== '') {
                    $dupQuery = $pdo->prepare('SELECT id FROM titles WHERE LOWER(title) = LOWER(?) AND LOWER(creator) = LOWER(?) LIMIT 1');
                    $dupQuery->execute([$itemTitle, $itemCreator]);
                    if ($dupQuery->fetch()) $isDuplicate = true;
                } else {
                    $dupQuery = $pdo->prepare('SELECT id FROM titles WHERE LOWER(title) = LOWER(?) LIMIT 1');
                    $dupQuery->execute([$itemTitle]);
                    if ($dupQuery->fetch()) $isDuplicate = true;
                }
            }

            if ($isDuplicate) {
                $duplicateRecords++;
            } else {
                $newRecords++;
                if (count($sampleItems) < 5) {
                    $sampleItems[] = [
                        'title' => $itemTitle,
                        'kind' => $item['kind'] ?? 'other',
                        'creator' => $itemCreator,
                    ];
                }
            }
        }

        respond(200, [
            'total_rows'        => $totalRows,
            'new_records'       => $newRecords,
            'duplicate_records' => $duplicateRecords,
            'missing_columns'   => array_values($missingColumns),
            'sample_items'      => $sampleItems
        ]);
    }

    // 2. Commit Schema Alterations: Executes admin-approved ALTER TABLE statements
    if ($method === 'POST' && $route === '/admin/media/apply-schema-changes') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $columnsToAdd = $body['columns'] ?? [];
        if (!is_array($columnsToAdd) || empty($columnsToAdd)) {
            respond(400, ['error' => 'No approved schema columns provided.']);
        }

        $applied = [];
        foreach ($columnsToAdd as $col) {
            $cleanCol = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$col);
            if ($cleanCol === '') continue;

            // Verify column doesn't already exist
            $check = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
            );
            $check->execute(['titles', $cleanCol]);
            if (!$check->fetch()) {
                $pdo->exec("ALTER TABLE titles ADD COLUMN `{$cleanCol}` TEXT NULL");
                $applied[] = $cleanCol;
            }
        }

        respond(200, [
            'message' => 'Schema successfully upgraded with approved fields.',
            'applied_columns' => $applied
        ]);
    }

    // 3. Execute Bulk Media Ingestion: Inserts non-duplicate media rows
    if ($method === 'POST' && $route === '/admin/media/bulk-import') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $rows = $body['rows'] ?? [];
        if (!is_array($rows) || empty($rows)) {
            respond(400, ['error' => 'No media rows provided for import.']);
        }

        // Fetch valid columns from DB
        $colStmt = $pdo->query('SHOW COLUMNS FROM titles');
        $validCols = [];
        while ($c = $colStmt->fetch()) {
            $validCols[strtolower((string)$c['Field'])] = true;
        }

        $canonicalMap = [
            'title'        => 'title',
            'name'         => 'title',
            'kind'         => 'kind',
            'type'         => 'kind',
            'media_type'   => 'kind',
            'creator'      => 'creator',
            'author'       => 'creator',
            'director'     => 'creator',
            'artist'       => 'creator',
            'isbn'         => 'isbn',
            'barcode'      => 'barcode',
            'upc'          => 'barcode',
            'ean'          => 'barcode',
            'release_year' => 'release_year',
            'year'         => 'release_year',
            'released'     => 'release_year',
            'cover_url'    => 'cover_url',
            'cover'        => 'cover_url',
            'image'        => 'cover_url',
            'description'  => 'description',
            'synopsis'     => 'description',
            'summary'      => 'description'
        ];

        $insertedCount = 0;
        $skippedCount = 0;

        $pdo->beginTransaction();
        try {
            foreach ($rows as $rawRow) {
                if (!is_array($rawRow)) continue;

                $data = [];
                foreach ($rawRow as $k => $v) {
                    $cleanKey = strtolower((string)preg_replace('/[^a-zA-Z0-9_]/', '_', trim((string)$k)));
                    $mapped = $canonicalMap[$cleanKey] ?? $cleanKey;
                    if (isset($validCols[$mapped])) {
                        $data[$mapped] = is_scalar($v) ? trim((string)$v) : '';
                    }
                }

                $title = trim($data['title'] ?? '');
                if ($title === '') {
                    $skippedCount++;
                    continue;
                }

                $kind = strtolower(trim($data['kind'] ?? 'other'));
                if (!is_valid_kind($kind)) {
                    $kind = 'other';
                }

                $creator = trim($data['creator'] ?? '') ?: null;
                $isbn = preg_replace('/[\s-]/u', '', $data['isbn'] ?? '') ?: null;
                $barcode = trim($data['barcode'] ?? '') ?: null;
                $releaseYear = !empty($data['release_year']) ? filter_var($data['release_year'], FILTER_VALIDATE_INT) : null;
                $coverUrl = trim($data['cover_url'] ?? '') ?: null;
                $description = trim($data['description'] ?? '') ?: null;

                // Check for duplicate
                $isDup = false;
                if ($isbn !== null) {
                    $chk = $pdo->prepare('SELECT id FROM titles WHERE isbn = ? LIMIT 1');
                    $chk->execute([$isbn]);
                    if ($chk->fetch()) $isDup = true;
                }
                if (!$isDup && $barcode !== null) {
                    $chk = $pdo->prepare('SELECT id FROM titles WHERE barcode = ? LIMIT 1');
                    $chk->execute([$barcode]);
                    if ($chk->fetch()) $isDup = true;
                }
                if (!$isDup) {
                    if ($creator !== null) {
                        $chk = $pdo->prepare('SELECT id FROM titles WHERE LOWER(title) = LOWER(?) AND LOWER(creator) = LOWER(?) LIMIT 1');
                        $chk->execute([$title, $creator]);
                        if ($chk->fetch()) $isDup = true;
                    } else {
                        $chk = $pdo->prepare('SELECT id FROM titles WHERE LOWER(title) = LOWER(?) LIMIT 1');
                        $chk->execute([$title]);
                        if ($chk->fetch()) $isDup = true;
                    }
                }

                if ($isDup) {
                    $skippedCount++;
                    continue;
                }

                // Prepare dynamic column keys
                $insertCols = ['title', 'kind', 'creator', 'isbn', 'barcode', 'release_year', 'cover_url', 'description', 'created_by'];
                $insertVals = [$title, $kind, $creator, $isbn, $barcode, $releaseYear, $coverUrl, $description, $admin['id']];

                // Append any newly reviewed and approved dynamic fields
                foreach ($data as $dKey => $dVal) {
                    if (!in_array($dKey, $insertCols, true) && isset($validCols[$dKey])) {
                        $insertCols[] = $dKey;
                        $insertVals[] = $dVal !== '' ? $dVal : null;
                    }
                }

                $colsSql = implode('`, `', $insertCols);
                $placeholders = implode(', ', array_fill(0, count($insertVals), '?'));
                $insertSql = "INSERT INTO titles (`{$colsSql}`) VALUES ({$placeholders})";

                $stmt = $pdo->prepare($insertSql);
                $stmt->execute($insertVals);
                $insertedCount++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        respond(200, [
            'message' => "Import complete! Added {$insertedCount} new titles. Skipped {$skippedCount} existing entries.",
            'inserted' => $insertedCount,
            'skipped'  => $skippedCount
        ]);
    }

    // --- Auth Routes ---
    if ($method === 'POST' && $route === '/auth/register') {
        $name = trim(body_string($body, 'name'));
        $username = trim(body_string($body, 'username')) ?: null;
        $email = strtolower(trim(body_string($body, 'email')));
        $password = body_string($body, 'password');

        if (text_length($name) < 2 || text_length($name) > 80) {
            respond(400, ['error' => 'Name must be between 2 and 80 characters.']);
        }
        if ($username !== null && (text_length($username) < 3 || text_length($username) > 30 || !preg_match('/^[a-zA-Z0-9_]+$/', $username))) {
            respond(400, ['error' => 'Username must be 3-30 alphanumeric characters or underscores.']);
        }
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) || strlen($email) > 254) {
            respond(400, ['error' => 'Enter a valid email address.']);
        }
        if (text_length($password) < 8 || strlen($password) > 72) {
            respond(400, ['error' => 'Password must be between 8 and 72 characters.']);
        }

        $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([$email]);
        if ($statement->fetch()) {
            respond(409, ['error' => 'An account with that email already exists.']);
        }

        try {
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $statement = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role) VALUES (?, ?, ?, ?, "member")');
            $statement->execute([$name, $username, $email, $passwordHash]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                respond(409, ['error' => 'An account with that email or handle already exists.']);
            }
            throw $error;
        }

        $user = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'username' => $username, 'email' => $email, 'role' => 'member', 'avatarUrl' => null];
        respond(201, ['user' => $user, 'token' => create_token($user, $config['jwt_secret'])]);
    }

    if ($method === 'POST' && $route === '/auth/login') {
        $email = strtolower(trim(body_string($body, 'email')));
        $password = body_string($body, 'password');
        $statement = $pdo->prepare('SELECT id, name, username, email, password_hash, role, avatar_url AS avatarUrl FROM users WHERE email = ?');
        $statement->execute([$email]);
        $row = $statement->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            respond(401, ['error' => 'Email or password is incorrect.']);
        }

        $user = ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'], 'role' => $row['role'], 'avatarUrl' => $row['avatarUrl']];
        respond(200, ['user' => $user, 'token' => create_token($user, $config['jwt_secret'])]);
    }

    if ($method === 'GET' && $route === '/auth/me') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        respond(200, ['user' => $user]);
    }

    if ($method === 'PUT' && $route === '/users/me') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $name = trim(body_string($body, 'name'));
        $username = trim(body_string($body, 'username')) ?: null;
        $avatarUrl = trim(body_string($body, 'avatarUrl')) ?: null;

        if (text_length($name) < 2 || text_length($name) > 80) {
            respond(400, ['error' => 'Name must be between 2 and 80 characters.']);
        }
        if ($username !== null && (text_length($username) < 3 || text_length($username) > 30 || !preg_match('/^[a-zA-Z0-9_]+$/', $username))) {
            respond(400, ['error' => 'Username must be 3-30 alphanumeric characters or underscores.']);
        }
        if ($avatarUrl !== null && (!preg_match('/^https?:\/\//i', $avatarUrl) || text_length($avatarUrl) > 2048)) {
            respond(400, ['error' => 'Avatar URL must be a valid http(s) link.']);
        }

        $stmt = $pdo->prepare('UPDATE users SET name = ?, username = ?, avatar_url = ? WHERE id = ?');
        $stmt->execute([$name, $username, $avatarUrl, $user['id']]);

        $user['name'] = $name;
        $user['username'] = $username;
        $user['avatarUrl'] = $avatarUrl;

        respond(200, ['user' => $user]);
    }

    // --- Public Community Users Directory ---
    if ($method === 'GET' && $route === '/users') {
        $stmt = $pdo->query(
            'SELECT 
                u.id, 
                u.name, 
                u.username,
                u.role, 
                u.avatar_url AS avatarUrl,
                u.created_at AS joinedAt,
                COUNT(DISTINCT ul.id) AS libraryCount,
                COUNT(DISTINCT r.id) AS reviewCount
             FROM users u
             LEFT JOIN user_libraries ul ON ul.user_id = u.id
             LEFT JOIN reviews r ON r.user_id = u.id
             GROUP BY u.id, u.name, u.username, u.role, u.avatar_url, u.created_at
             ORDER BY libraryCount DESC, u.created_at DESC
             LIMIT 100'
        );
        $users = $stmt->fetchAll();
        foreach ($users as &$u) {
            $u['id'] = (int) $u['id'];
            $u['libraryCount'] = (int) $u['libraryCount'];
            $u['reviewCount'] = (int) $u['reviewCount'];
        }
        unset($u);
        respond(200, ['users' => $users]);
    }

    // --- Admin Role Management ---
    if ($method === 'PATCH' && preg_match('#^/admin/users/([0-9]+)/role$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $targetUserId = (int) $matches[1];
        $newRole = body_string($body, 'role');

        if (!in_array($newRole, ['admin', 'moderator', 'member'], true)) {
            respond(400, ['error' => 'Invalid role specified. Use admin, moderator, or member.']);
        }

        $statement = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
        $statement->execute([$newRole, $targetUserId]);

        respond(200, ['message' => "User role updated to {$newRole}."]);
    }

    // --- Titles Catalog ---
    if ($method === 'POST' && $route === '/admin/titles/bulk-update') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $ids = $body['ids'] ?? null;
        $fields = $body['fields'] ?? null;
        if (!is_array($ids) || count($ids) === 0 || !is_array($fields) || count($fields) === 0) {
            respond(400, ['error' => 'Select at least one title and one field to update.']);
        }

        $titleIds = [];
        foreach ($ids as $id) {
            $validatedId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedId === false) {
                respond(400, ['error' => 'Selected title IDs must be positive integers.']);
            }
            $titleIds[] = (int) $validatedId;
        }
        $titleIds = array_values(array_unique($titleIds));

        $fieldColumns = [
            'kind' => 'kind',
            'creator' => 'creator',
            'releaseYear' => 'release_year',
            'coverUrl' => 'cover_url',
            'description' => 'description',
            'catalogRating' => 'catalog_rating',
            'genres' => 'genres',
            'tropes' => 'tropes',
            'tags' => 'tags',
        ];
        $updates = [];
        $values = [];
        foreach ($fields as $field => $value) {
            if (!isset($fieldColumns[$field])) {
                respond(400, ['error' => "Unsupported bulk field: {$field}."]);
            }

            if ($field === 'kind') {
                if (!is_string($value) || !is_valid_kind($value)) {
                    respond(400, ['error' => 'Choose a valid media category.']);
                }
                $normalized = $value;
            } elseif ($field === 'releaseYear') {
                $normalized = $value === '' || $value === null ? null : filter_var($value, FILTER_VALIDATE_INT);
                if ($normalized === false || ($normalized !== null && ($normalized < 1 || $normalized > 9999))) {
                    respond(400, ['error' => 'Enter a valid release year (1–9999).']);
                }
            } elseif ($field === 'catalogRating') {
                $normalized = $value === '' || $value === null ? null : (is_numeric($value) ? (float) $value : false);
                if ($normalized === false || ($normalized !== null && ($normalized < 0 || $normalized > 5))) {
                    respond(400, ['error' => 'Catalog rating must be a number from 0 to 5.']);
                }
            } else {
                if (!is_string($value)) {
                    respond(400, ['error' => "The {$field} field must be text."]);
                }
                $normalized = trim($value);
                $maxLength = match ($field) {
                    'creator' => 200,
                    'coverUrl' => 2048,
                    'description' => 1500,
                    default => 10000,
                };
                if (text_length($normalized) > $maxLength) {
                    respond(400, ['error' => "The {$field} field exceeds its maximum length of {$maxLength} characters."]);
                }
                if ($field === 'coverUrl' && $normalized !== '' && !preg_match('/^https?:\\/\\//i', $normalized)) {
                    respond(400, ['error' => 'Cover URL must be a valid http(s) URL.']);
                }
                $normalized = $normalized !== '' ? $normalized : null;
            }

            $updates[] = "`{$fieldColumns[$field]}` = ?";
            $values[] = $normalized;
        }

        if (array_intersect(['catalogRating', 'genres', 'tropes', 'tags'], array_keys($fields))) {
            ensure_title_metadata_columns($pdo);
        }

        $idPlaceholders = implode(', ', array_fill(0, count($titleIds), '?'));
        $values = array_merge($values, $titleIds);
        $statement = $pdo->prepare(
            'UPDATE titles SET ' . implode(', ', $updates) . " WHERE id IN ({$idPlaceholders})"
        );
        $statement->execute($values);

        respond(200, [
            'message' => 'Bulk metadata update complete.',
            'updated' => $statement->rowCount(),
        ]);
    }

    if ($method === 'GET' && $route === '/titles') {
        $rawQuery = query_string('q');
        $query = truncate_text($rawQuery, 120);
        $kind = query_string('kind');
        $minSpice = query_string('minSpice');
        $maxSpice = query_string('maxSpice');
        $needsInfo = query_string('needsInfo');
        if ($needsInfo === '1') {
            $admin = authenticated_user($config['jwt_secret'], $pdo);
            require_role($admin, ['admin']);
            ensure_title_metadata_columns($pdo);
        }

        $catalogRatingColumn = title_metadata_column_exists($pdo, 'catalog_rating')
            ? 't.catalog_rating'
            : 'NULL';
        $genresColumn = title_metadata_column_exists($pdo, 'genres') ? 't.genres' : 'NULL';
        $tropesColumn = title_metadata_column_exists($pdo, 'tropes') ? 't.tropes' : 'NULL';
        $tagsColumn = title_metadata_column_exists($pdo, 'tags') ? 't.tags' : 'NULL';

        $conditions = [];
        $values = [];

        if ($query !== '') {
            $like = "%$query%";
            $identifier = (string) preg_replace('/[\s-]/u', '', $query);
            $conditions[] = "(t.title LIKE ? OR t.creator LIKE ? OR t.isbn LIKE ? OR t.barcode LIKE ? OR REPLACE(t.isbn, '-', '') LIKE ?)";
            array_push($values, $like, $like, $like, $like, "%$identifier%");
        }

        if (is_valid_kind($kind)) {
            $conditions[] = 't.kind = ?';
            $values[] = $kind;
        }

        if ($minSpice !== '' && is_numeric($minSpice)) {
            $conditions[] = 'COALESCE(stats.averageSpice, 0) >= ?';
            $values[] = (float) $minSpice;
        }

        if ($maxSpice !== '' && is_numeric($maxSpice)) {
            $conditions[] = 'COALESCE(stats.averageSpice, 0) <= ?';
            $values[] = (float) $maxSpice;
        }

        if ($needsInfo === '1') {
            $conditions[] = '(t.cover_url IS NULL OR t.cover_url = "" OR t.description IS NULL OR t.description = "" OR t.creator IS NULL OR t.creator = "")';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $order = '';
        if ($query !== '') {
            $order = 'CASE WHEN t.title = ? THEN 0 WHEN t.title LIKE ? THEN 1 ELSE 2 END, ';
            array_push($values, $query, "$query%");
        }

        $sql = "SELECT 
                    t.id, 
                    t.title, 
                    t.kind, 
                    t.creator, 
                    t.isbn, 
                    t.barcode, 
                    t.release_year AS releaseYear,
                    t.cover_url AS coverUrl, 
                    t.description,
                    {$catalogRatingColumn} AS catalogRating,
                    {$genresColumn} AS genres,
                    {$tropesColumn} AS tropes,
                    {$tagsColumn} AS tags,
                    COALESCE(stats.reviewCount, 0) AS reviewCount,
                    stats.averageSpice AS averageSpice,
                    stats.averageRating AS averageRating
                FROM titles t
                LEFT JOIN (
                    SELECT 
                        title_id,
                        COUNT(id) AS reviewCount,
                        ROUND(AVG(spice_level), 1) AS averageSpice,
                        ROUND(AVG(rating), 1) AS averageRating
                    FROM reviews
                    GROUP BY title_id
                ) stats ON stats.title_id = t.id
                $where
                ORDER BY $order t.created_at DESC
                " . ($needsInfo === '1' ? '' : 'LIMIT 60');

        $statement = $pdo->prepare($sql);
        $statement->execute($values);
        $rows = $statement->fetchAll();

        foreach ($rows as &$item) {
            $item['id'] = (int) $item['id'];
            $item['releaseYear'] = $item['releaseYear'] !== null ? (int) $item['releaseYear'] : null;
            $item['catalogRating'] = $item['catalogRating'] !== null ? (float) $item['catalogRating'] : null;
            $item['reviewCount'] = (int) $item['reviewCount'];
            $item['averageSpice'] = $item['averageSpice'] !== null ? (float) $item['averageSpice'] : null;
            $item['averageRating'] = $item['averageRating'] !== null ? (float) $item['averageRating'] : null;
        }
        unset($item);

        respond(200, ['titles' => $rows]);
    }

    // --- Title Reviews ---
    if ($method === 'GET' && preg_match('#^/titles/([0-9]+)/reviews$#', $route, $matches)) {
        $statement = $pdo->prepare(
            'SELECT r.id, r.user_id AS userId, r.rating, r.spice_level AS spiceLevel, r.notes, r.warnings,
                r.contains_spoilers AS containsSpoilers, r.created_at AS createdAt, u.name AS reviewer, u.username AS reviewerUsername, u.avatar_url AS reviewerAvatar, u.role AS reviewerRole
             FROM reviews r JOIN users u ON u.id = r.user_id
             WHERE r.title_id = ? ORDER BY r.created_at DESC LIMIT 50'
        );
        $statement->execute([$matches[1]]);
        $reviews = $statement->fetchAll();

        foreach ($reviews as &$review) {
            $review['id'] = (int) $review['id'];
            $review['userId'] = (int) $review['userId'];
            $review['rating'] = (int) $review['rating'];
            $review['spiceLevel'] = (int) $review['spiceLevel'];
            $review['warnings'] = json_decode($review['warnings'] ?? '[]', true) ?? [];
            $review['containsSpoilers'] = (bool) $review['containsSpoilers'];
        }
        unset($review);

        respond(200, ['reviews' => $reviews]);
    }

    if ($method === 'POST' && $route === '/titles') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $title = trim(body_string($body, 'title'));
        $kind = body_string($body, 'kind');
        $creator = trim(body_string($body, 'creator')) ?: null;
        $isbn = preg_replace('/[\s-]/u', '', body_string($body, 'isbn')) ?: null;
        $barcode = trim(body_string($body, 'barcode')) ?: null;
        $releaseInput = body_string($body, 'releaseYear');
        $releaseYear = $releaseInput !== '' ? filter_var($releaseInput, FILTER_VALIDATE_INT) : null;
        $coverUrl = trim(body_string($body, 'coverUrl')) ?: null;
        $description = trim(body_string($body, 'description')) ?: null;

        if ($title === '' || text_length($title) > 200) {
            respond(400, ['error' => 'Title is required and must be 200 characters or fewer.']);
        }
        if (!is_valid_kind($kind)) {
            respond(400, ['error' => 'Choose a valid media type.']);
        }
        if ($creator !== null && text_length($creator) > 200) {
            respond(400, ['error' => 'Creator must be 200 characters or fewer.']);
        }
        if ($isbn !== null && !preg_match('/^(?:\d{9}[\dXx]|\d{13})$/', $isbn)) {
            respond(400, ['error' => 'ISBN must be 10 or 13 characters, excluding spaces and hyphens.']);
        }
        if ($barcode !== null && text_length($barcode) > 64) {
            respond(400, ['error' => 'Barcode must be 64 characters or fewer.']);
        }
        if ($releaseInput !== '' && ($releaseYear === false || $releaseYear < 1 || $releaseYear > 9999)) {
            respond(400, ['error' => 'Enter a valid release year.']);
        }
        if ($description !== null && text_length($description) > 1500) {
            respond(400, ['error' => 'Description must be 1,500 characters or fewer.']);
        }
        if ($coverUrl !== null && (!preg_match('/^https?:\/\//i', $coverUrl) || text_length($coverUrl) > 2048)) {
            respond(400, ['error' => 'Cover URL must be a valid http(s) URL.']);
        }

        $titleId = null;
        try {
            $statement = $pdo->prepare(
                'INSERT INTO titles (title, kind, creator, isbn, barcode, release_year, cover_url, description, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$title, $kind, $creator, $isbn, $barcode, $releaseYear, $coverUrl, $description, $user['id']]);
            $titleId = (int) $pdo->lastInsertId();
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                $existing = $pdo->prepare('SELECT id FROM titles WHERE (isbn IS NOT NULL AND isbn = ?) OR (barcode IS NOT NULL AND barcode = ?)');
                $existing->execute([$isbn, $barcode]);
                $matched = $existing->fetch();
                if ($matched) {
                    $titleId = (int) $matched['id'];
                } else {
                    respond(409, ['error' => 'That ISBN or barcode already exists in the catalog.']);
                }
            } else {
                throw $error;
            }
        }

        $libStmt = $pdo->prepare(
            'INSERT INTO user_libraries (user_id, title_id, status) VALUES (?, ?, "completed")
             ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
        );
        $libStmt->execute([$user['id'], $titleId]);

        respond(201, ['title' => [
            'id' => $titleId,
            'title' => $title,
            'kind' => $kind,
            'creator' => $creator,
            'isbn' => $isbn,
            'barcode' => $barcode,
            'releaseYear' => $releaseYear,
            'coverUrl' => $coverUrl,
            'description' => $description,
            'reviewCount' => 0,
            'averageSpice' => null,
            'averageRating' => null,
        ]]);
    }

    if ($method === 'PUT' && preg_match('#^/titles/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        require_role($user, ['admin', 'moderator']);

        $titleId = (int) $matches[1];
        $title = trim(body_string($body, 'title'));
        $kind = body_string($body, 'kind');
        $creator = trim(body_string($body, 'creator')) ?: null;
        $isbn = preg_replace('/[\s-]/u', '', body_string($body, 'isbn')) ?: null;
        $barcode = trim(body_string($body, 'barcode')) ?: null;
        $releaseInput = body_string($body, 'releaseYear');
        $releaseYear = $releaseInput !== '' ? filter_var($releaseInput, FILTER_VALIDATE_INT) : null;
        $coverUrl = trim(body_string($body, 'coverUrl')) ?: null;
        $description = trim(body_string($body, 'description')) ?: null;
        $metadataFields = [];
        if (array_key_exists('catalogRating', $body)) {
            $catalogRatingInput = body_string($body, 'catalogRating');
            $catalogRating = $catalogRatingInput !== '' ? (is_numeric($catalogRatingInput) ? (float) $catalogRatingInput : false) : null;
            if ($catalogRating === false || ($catalogRating !== null && ($catalogRating < 0 || $catalogRating > 5))) {
                respond(400, ['error' => 'Catalog rating must be a number from 0 to 5.']);
            }
            $metadataFields['catalog_rating'] = $catalogRating;
        }
        foreach (['genres', 'tropes', 'tags'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim(body_string($body, $field)) ?: null;
                if ($value !== null && text_length($value) > 10000) {
                    respond(400, ucfirst($field) . ' must be 10,000 characters or fewer.');
                }
                $metadataFields[$field] = $value;
            }
        }

        if ($title === '' || text_length($title) > 200) {
            respond(400, ['error' => 'Title is required and must be 200 characters or fewer.']);
        }
        if (!is_valid_kind($kind)) {
            respond(400, ['error' => 'Choose a valid media type.']);
        }
        $setClause = 'title = ?, kind = ?, creator = ?, isbn = ?, barcode = ?, release_year = ?, cover_url = ?, description = ?';
        $values = [$title, $kind, $creator, $isbn, $barcode, $releaseYear, $coverUrl, $description];
        if ($metadataFields !== []) {
            ensure_title_metadata_columns($pdo);
            foreach ($metadataFields as $column => $value) {
                $setClause .= ", `{$column}` = ?";
                $values[] = $value;
            }
        }
        $values[] = $titleId;
        $stmt = $pdo->prepare("UPDATE titles SET {$setClause} WHERE id = ?");
        $stmt->execute($values);

        respond(200, ['message' => 'Title updated successfully.']);
    }

    if ($method === 'DELETE' && preg_match('#^/titles/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        require_role($user, ['admin']);

        $titleId = (int) $matches[1];
        $pdo->prepare('DELETE FROM reviews WHERE title_id = ?')->execute([$titleId]);
        $pdo->prepare('DELETE FROM user_libraries WHERE title_id = ?')->execute([$titleId]);
        $pdo->prepare('DELETE FROM titles WHERE id = ?')->execute([$titleId]);

        respond(200, ['message' => 'Title deleted successfully.']);
    }

    // --- User Libraries ---
    if ($method === 'GET' && preg_match('#^/users/([0-9]+)/library$#', $route, $matches)) {
        $targetUserId = (int) $matches[1];

        $userStmt = $pdo->prepare('SELECT id, name, username, role, avatar_url AS avatarUrl, created_at AS joinedAt FROM users WHERE id = ?');
        $userStmt->execute([$targetUserId]);
        $owner = $userStmt->fetch();
        if (!$owner) {
            respond(404, ['error' => 'User not found.']);
        }
        $owner['id'] = (int) $owner['id'];

        $stmt = $pdo->prepare(
            'SELECT 
                ul.id AS libraryEntryId,
                ul.status,
                ul.created_at AS addedAt,
                t.id AS titleId,
                t.title,
                t.kind,
                t.creator,
                t.isbn,
                t.barcode,
                t.release_year AS releaseYear,
                t.cover_url AS coverUrl,
                t.description,
                r.rating AS userRating,
                r.spice_level AS userSpice
             FROM user_libraries ul
             JOIN titles t ON t.id = ul.title_id
             LEFT JOIN reviews r ON r.title_id = t.id AND r.user_id = ul.user_id
             WHERE ul.user_id = ?
             ORDER BY ul.created_at DESC'
        );
        $stmt->execute([$targetUserId]);
        $library = $stmt->fetchAll();

        foreach ($library as &$entry) {
            $entry['libraryEntryId'] = (int) $entry['libraryEntryId'];
            $entry['titleId'] = (int) $entry['titleId'];
            $entry['releaseYear'] = $entry['releaseYear'] !== null ? (int) $entry['releaseYear'] : null;
            $entry['userRating'] = $entry['userRating'] !== null ? (int) $entry['userRating'] : null;
            $entry['userSpice'] = $entry['userSpice'] !== null ? (int) $entry['userSpice'] : null;
        }
        unset($entry);

        respond(200, ['owner' => $owner, 'library' => $library, 'relations' => ['isBlocked' => false, 'friendStatus' => null]]);
    }

    if ($method === 'POST' && $route === '/library/add') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $titleId = filter_var($body['titleId'] ?? null, FILTER_VALIDATE_INT);
        $status = body_string($body, 'status') ?: 'completed';

        if (!$titleId) {
            respond(400, ['error' => 'A valid titleId is required.']);
        }
        if (!is_valid_status($status)) {
            respond(400, ['error' => 'Invalid status. Choose planned, in_progress, completed, or dropped.']);
        }

        $checkTitle = $pdo->prepare('SELECT id FROM titles WHERE id = ?');
        $checkTitle->execute([$titleId]);
        if (!$checkTitle->fetch()) {
            respond(404, ['error' => 'Title not found.']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO user_libraries (user_id, title_id, status) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([$user['id'], $titleId, $status]);

        respond(200, ['message' => 'Library updated.', 'titleId' => $titleId, 'status' => $status]);
    }

    if ($method === 'DELETE' && preg_match('#^/library/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $titleId = (int) $matches[1];

        $stmt = $pdo->prepare('DELETE FROM user_libraries WHERE user_id = ? AND title_id = ?');
        $stmt->execute([$user['id'], $titleId]);

        respond(200, ['message' => 'Item removed from your library.']);
    }

    // --- Submit / Update Review & Spice Level ---
    if ($method === 'POST' && preg_match('#^/titles/([0-9]+)/reviews$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $ratingValue = body_string($body, 'rating');
        $spiceValue = body_string($body, 'spiceLevel');
        $rating = filter_var($ratingValue, FILTER_VALIDATE_INT);
        $spiceLevel = filter_var($spiceValue, FILTER_VALIDATE_INT);
        $notes = trim(body_string($body, 'notes'));
        $warnings = $body['warnings'] ?? [];
        if (!is_array($warnings)) {
            $warnings = [];
        }
        $warnings = array_slice(array_values(array_filter(array_map(
            static fn ($warning): string => is_scalar($warning) ? trim((string) $warning) : '',
            $warnings
        ), static fn (string $warning): bool => $warning !== '')), 0, 20);
        $containsSpoilers = filter_var($body['containsSpoilers'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($rating === false || $rating < 1 || $rating > 5) {
            respond(400, ['error' => 'Rating must be between 1 and 5.']);
        }
        if ($spiceLevel === false || $spiceLevel < 0 || $spiceLevel > 5) {
            respond(400, ['error' => 'Spice level must be between 0 and 5.']);
        }
        if ($notes === '' || text_length($notes) > 3000) {
            respond(400, ['error' => 'Add a review note of up to 3,000 characters.']);
        }
        foreach ($warnings as $warning) {
            if (text_length($warning) > 80) {
                respond(400, ['error' => 'Each content warning must be 80 characters or fewer.']);
            }
        }

        try {
            $statement = $pdo->prepare(
                'INSERT INTO reviews (title_id, user_id, rating, spice_level, notes, warnings, contains_spoilers)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE rating = VALUES(rating), spice_level = VALUES(spice_level),
                    notes = VALUES(notes), warnings = VALUES(warnings),
                    contains_spoilers = VALUES(contains_spoilers), updated_at = CURRENT_TIMESTAMP'
            );
            $statement->execute([
                $matches[1],
                $user['id'],
                $rating,
                $spiceLevel,
                $notes,
                json_encode($warnings, JSON_THROW_ON_ERROR),
                $containsSpoilers ? 1 : 0,
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1452) {
                respond(404, ['error' => 'That title could not be found.']);
            }
            throw $error;
        }

        respond($statement->rowCount() === 1 ? 201 : 200, ['message' => 'Your spice rating & review is live!']);
    }

    // --- Community Lounge & Recommendations Feed ---
    if ($method === 'GET' && $route === '/community/posts') {
        $category = query_string('category');
        $where = '';
        $params = [];
        if (is_valid_post_category($category)) {
            $where = 'WHERE p.category = ?';
            $params[] = $category;
        }

        $stmt = $pdo->prepare(
            "SELECT 
                p.id,
                p.category,
                p.topic,
                p.body,
                p.likes_count AS likesCount,
                p.created_at AS createdAt,
                u.id AS authorId,
                u.name AS authorName,
                u.username AS authorUsername,
                u.avatar_url AS authorAvatar,
                u.role AS authorRole,
                t.id AS linkedTitleId,
                t.title AS linkedTitleName,
                t.kind AS linkedTitleKind,
                t.cover_url AS linkedTitleCover
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN titles t ON t.id = p.linked_title_id
             $where
             ORDER BY p.created_at DESC
             LIMIT 50"
        );
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        $postIds = array_column($posts, 'id');
        $commentsByPost = [];
        if (!empty($postIds)) {
            $placeholders = implode(',', array_fill(0, count($postIds), '?'));
            $cStmt = $pdo->prepare(
                "SELECT c.id, c.post_id AS postId, c.body, c.created_at AS createdAt,
                        u.id AS authorId, u.name AS authorName, u.avatar_url AS authorAvatar, u.role AS authorRole
                 FROM community_comments c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.post_id IN ($placeholders)
                 ORDER BY c.created_at ASC"
            );
            $cStmt->execute($postIds);
            foreach ($cStmt->fetchAll() as $comment) {
                $comment['id'] = (int) $comment['id'];
                $comment['postId'] = (int) $comment['postId'];
                $comment['authorId'] = (int) $comment['authorId'];
                $commentsByPost[$comment['postId']][] = $comment;
            }
        }

        foreach ($posts as &$post) {
            $post['id'] = (int) $post['id'];
            $post['authorId'] = (int) $post['authorId'];
            $post['likesCount'] = (int) $post['likesCount'];
            $post['linkedTitleId'] = $post['linkedTitleId'] !== null ? (int) $post['linkedTitleId'] : null;
            $post['comments'] = $commentsByPost[$post['id']] ?? [];
        }
        unset($post);

        respond(200, ['posts' => $posts]);
    }

    if ($method === 'POST' && $route === '/community/posts') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $category = body_string($body, 'category') ?: 'general';
        $topic = trim(body_string($body, 'topic'));
        $content = trim(body_string($body, 'body'));
        $linkedTitleId = filter_var($body['linkedTitleId'] ?? null, FILTER_VALIDATE_INT) ?: null;

        if (!is_valid_post_category($category)) {
            respond(400, ['error' => 'Invalid post category.']);
        }
        if ($topic === '' || text_length($topic) > 160) {
            respond(400, ['error' => 'Topic headline is required (max 160 chars).']);
        }
        if ($content === '' || text_length($content) > 4000) {
            respond(400, ['error' => 'Post body is required (max 4,000 chars).']);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO community_posts (user_id, category, topic, body, linked_title_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$user['id'], $category, $topic, $content, $linkedTitleId]);

        respond(201, ['message' => 'Posted to the community lounge!', 'postId' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'POST' && preg_match('#^/community/posts/([0-9]+)/comments$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $postId = (int) $matches[1];
        $content = trim(body_string($body, 'body'));

        if ($content === '' || text_length($content) > 1500) {
            respond(400, ['error' => 'Comment must be between 1 and 1,500 characters.']);
        }

        $stmt = $pdo->prepare('INSERT INTO community_comments (post_id, user_id, body) VALUES (?, ?, ?)');
        $stmt->execute([$postId, $user['id'], $content]);

        respond(201, ['message' => 'Reply added.']);
    }

    if ($method === 'POST' && preg_match('#^/community/posts/([0-9]+)/like$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $postId = (int) $matches[1];
        $stmt = $pdo->prepare('UPDATE community_posts SET likes_count = likes_count + 1 WHERE id = ?');
        $stmt->execute([$postId]);
        respond(200, ['message' => 'Liked!']);
    }

    // --- Messages & Friends / Blocks Stub Handlers ---
    if ($method === 'GET' && $route === '/messages/inbox') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        respond(200, ['inbox' => []]);
    }

    if ($method === 'GET' && $route === '/friends') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        respond(200, ['friends' => []]);
    }

    if ($method === 'GET' && $route === '/blocks') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        respond(200, ['blocks' => []]);
    }

    respond(404, ['error' => 'Not found.']);
} catch (Throwable $error) {
    error_log($error->__toString());

    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';

    respond(500, [
        'error' => 'Something went wrong. Please try again.',
        'debug' => $debug ? [
            'message' => $error->getMessage(),
            'file'    => $error->getFile(),
            'line'    => $error->getLine(),
        ] : null,
    ]);
}