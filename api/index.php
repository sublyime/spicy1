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

function metadata_tokens(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }

    $tokens = preg_split('/[,;|]+/u', $value) ?: [];
    $normalized = [];
    foreach ($tokens as $token) {
        $token = strtolower((string) preg_replace('/\s+/u', '', trim($token)));
        if ($token !== '') {
            $normalized[$token] = true;
        }
    }
    return array_keys($normalized);
}

function ensure_user_active_column(PDO $pdo): void
{
    $check = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute(['users', 'is_active']);
    if (!$check->fetch()) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1');
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S21' && (int) ($error->errorInfo[1] ?? 0) !== 1060) {
                throw $error;
            }
        }
    }
}

function ensure_community_lock_column(PDO $pdo): void
{
    $check = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute(['community_posts', 'is_locked']);
    if (!$check->fetch()) {
        try {
            $pdo->exec('ALTER TABLE community_posts ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0');
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S21' && (int) ($error->errorInfo[1] ?? 0) !== 1060) {
                throw $error;
            }
        }
    }
}

function ensure_password_reset_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS password_reset_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_password_reset_user (user_id, expires_at),
            UNIQUE KEY uq_password_reset_hash (token_hash),
            CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function can_send_password_reset(array $config): bool
{
    $appUrl = $config['app_url'] ?? '';
    $mailFrom = $config['mail_from'] ?? '';
    if (!is_string($appUrl) || !is_string($mailFrom)
        || filter_var($appUrl, FILTER_VALIDATE_URL) === false
        || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }

    $parts = parse_url($appUrl);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true));
}

function issue_password_reset(PDO $pdo, array $config, int $userId, string $email, string $name): void
{
    if (!can_send_password_reset($config)) {
        throw new RuntimeException('Configure app_url and mail_from in the private API configuration before sending reset emails.');
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL')
        ->execute([$userId]);
    $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))')
        ->execute([$userId, $tokenHash]);

    $resetUrl = rtrim($config['app_url'], '/') . '/?resetToken=' . rawurlencode($token);
    $subject = 'Reset your Is It Too Spicy? password';
    $message = "Hello {$name},\n\nUse the link below to set a new password. This link expires in one hour and can only be used once.\n\n{$resetUrl}\n\nIf you did not request this, you can ignore this email.\n";
    $headers = [
        'From' => $config['mail_from'],
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'Is It Too Spicy',
    ];
    if (!mail($email, $subject, $message, $headers)) {
        $pdo->prepare('DELETE FROM password_reset_tokens WHERE token_hash = ?')->execute([$tokenHash]);
        throw new RuntimeException('The password reset email could not be sent. Check the server mail configuration.');
    }
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

function request_authorization_header(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authorization && function_exists('getallheaders')) {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    return is_string($authorization) ? $authorization : '';
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
    $authorization = request_authorization_header();

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

    ensure_user_active_column($pdo);
    $stmt = $pdo->prepare('SELECT id, name, username, email, role, avatar_url AS avatarUrl, created_at AS createdAt, is_active AS isActive FROM users WHERE id = ?');
    $stmt->execute([(int) $payload['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(401, ['error' => 'Account not found.']);
    }
    if (!(bool) $user['isActive']) {
        respond(403, ['error' => 'This account has been deactivated. Contact an administrator for help.']);
    }

    $user['id'] = (int) $user['id'];
    $user['isActive'] = (bool) $user['isActive'];
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
        'series' => 'VARCHAR(200) NULL',
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

function ensure_social_tables(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS friendships (
            requester_id BIGINT UNSIGNED NOT NULL,
            addressee_id BIGINT UNSIGNED NOT NULL,
            status ENUM(\'pending\', \'accepted\') NOT NULL DEFAULT \'pending\',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (requester_id, addressee_id),
            KEY idx_friendships_addressee_status (addressee_id, status),
            CONSTRAINT fk_friendships_requester FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_friendships_addressee FOREIGN KEY (addressee_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_blocks (
            blocker_id BIGINT UNSIGNED NOT NULL,
            blocked_id BIGINT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (blocker_id, blocked_id),
            CONSTRAINT fk_user_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sender_id BIGINT UNSIGNED NOT NULL,
            receiver_id BIGINT UNSIGNED NOT NULL,
            content TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_conversation (sender_id, receiver_id, created_at),
            KEY idx_inbox (receiver_id, is_read, created_at),
            CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_messages_receiver FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB'
    );
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

function fetch_public_related_media(string $type, string $kind, string $query): array
{
    $results = [];
    if ($kind === 'book' || $kind === 'all') {
        $parameter = $type === 'creator' ? 'author' : 'subject';
        $url = 'https://openlibrary.org/search.json?' . $parameter . '=' . urlencode($query)
            . '&limit=20&fields=key,title,author_name,first_publish_year,isbn,cover_i';
        $data = http_get_json($url);
        foreach (($data['docs'] ?? []) as $doc) {
            if (empty($doc['title'])) {
                continue;
            }
            $isbn = null;
            foreach (($doc['isbn'] ?? []) as $candidate) {
                $candidate = (string) preg_replace('/[\s-]/u', '', (string) $candidate);
                if (preg_match('/^(?:\d{9}[\dXx]|\d{13})$/', $candidate)) {
                    $isbn = $candidate;
                    break;
                }
            }
            $coverId = $doc['cover_i'] ?? null;
            $results[] = [
                'title' => (string) $doc['title'],
                'kind' => 'book',
                'creator' => implode(', ', (array) ($doc['author_name'] ?? [])),
                'isbn' => $isbn,
                'barcode' => null,
                'releaseYear' => isset($doc['first_publish_year']) ? (int) $doc['first_publish_year'] : null,
                'coverUrl' => $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-L.jpg" : null,
                'description' => null,
                'source' => 'Open Library',
                'sourceUrl' => isset($doc['key']) && str_starts_with($doc['key'], '/')
                    ? 'https://openlibrary.org' . $doc['key']
                    : null,
            ];
        }
    }

    $itunesEntities = [
        'movie' => ['movie', 'trackName'],
        'tv' => ['tvEpisode', 'trackName'],
        'podcast' => ['podcast', 'collectionName'],
        'game' => ['software', 'trackName'],
    ];
    $kinds = $kind === 'all' ? array_keys($itunesEntities) : [$kind];
    foreach ($kinds as $itemKind) {
        if (!isset($itunesEntities[$itemKind])) {
            continue;
        }
        [$entity, $titleField] = $itunesEntities[$itemKind];
        $url = 'https://itunes.apple.com/search?term=' . urlencode($query)
            . '&entity=' . urlencode($entity) . '&limit=20';
        $data = http_get_json($url);
        foreach (($data['results'] ?? []) as $item) {
            $title = $item[$titleField] ?? null;
            if (!is_string($title) || trim($title) === '') {
                continue;
            }
            $coverUrl = $item['artworkUrl100'] ?? $item['artworkUrl600'] ?? null;
            if (is_string($coverUrl)) {
                $coverUrl = preg_replace('/\d+x\d+bb(?=\.)/', '600x600bb', $coverUrl);
            }
            $results[] = [
                'title' => $title,
                'kind' => $itemKind,
                'creator' => $item['artistName'] ?? null,
                'isbn' => null,
                'barcode' => null,
                'releaseYear' => !empty($item['releaseDate']) ? (int) substr($item['releaseDate'], 0, 4) : null,
                'coverUrl' => $coverUrl,
                'description' => clean_description($item['longDescription'] ?? $item['description'] ?? null),
                'source' => 'Apple iTunes',
                'sourceUrl' => $item['trackViewUrl'] ?? $item['collectionViewUrl'] ?? null,
            ];
        }
    }

    if ($kind === 'other') {
        $wikiUrl = 'https://en.wikipedia.org/w/api.php?action=query&generator=search&gsrsearch='
            . urlencode($query)
            . '&gsrlimit=10&prop=pageimages%7Cextracts&piprop=thumbnail&pithumbsize=300&exintro=1&explaintext=1&format=json';
        $wikiData = http_get_json($wikiUrl);
        foreach (($wikiData['query']['pages'] ?? []) as $page) {
            if (empty($page['title'])) {
                continue;
            }
            $extract = (string) ($page['extract'] ?? '');
            $year = preg_match('/\b(19\d{2}|20\d{2})\b/', $extract, $yearMatch)
                ? (int) $yearMatch[0]
                : null;
            $results[] = [
                'title' => (string) $page['title'],
                'kind' => 'other',
                'creator' => null,
                'isbn' => null,
                'barcode' => null,
                'releaseYear' => $year,
                'coverUrl' => $page['thumbnail']['source'] ?? null,
                'description' => clean_description($extract),
                'source' => 'Wikipedia',
                'sourceUrl' => 'https://en.wikipedia.org/?curid=' . (int) ($page['pageid'] ?? 0),
            ];
        }
    }

    $unique = [];
    foreach ($results as $item) {
        $key = strtolower(trim($item['kind'] . '|' . $item['title']));
        $unique[$key] ??= $item;
    }
    return array_values($unique);
}

try {
    $webRootConfigFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    $localConfigFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
    $parentConfigFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';
    $configFile = null;
    foreach ([$webRootConfigFile, $localConfigFile, $parentConfigFile] as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $configFile = $candidate;
            break;
        }
    }
    if ($configFile === null) {
        throw new RuntimeException('Configuration file config.php not found in the web root, api directory, or private parent directory.');
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

    // --- Visitor Counter Route ---
    if ($route === '/stats/visitors' && in_array($method, ['GET', 'POST'], true)) {
        if ($method === 'POST') {
            $pdo->exec(
                "INSERT INTO app_settings (`key`, `value`) VALUES ('visitor_count', '1')
                 ON DUPLICATE KEY UPDATE `value` = CAST(CAST(`value` AS UNSIGNED) + 1 AS CHAR)"
            );
        } else {
            $pdo->exec(
                "INSERT IGNORE INTO app_settings (`key`, `value`) VALUES ('visitor_count', '0')"
            );
        }

        $stmt = $pdo->prepare("SELECT `value` FROM app_settings WHERE `key` = 'visitor_count' LIMIT 1");
        $stmt->execute();
        $count = (int) ($stmt->fetchColumn() ?: 0);

        respond(200, ['visitors' => $count]);
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

    if ($method === 'GET' && $route === '/discovery/search') {
        $type = query_string('type');
        $kind = query_string('kind') ?: 'all';
        $query = truncate_text(query_string('q'), 120);
        if (!in_array($type, ['creator', 'genre'], true) || $query === '') {
            respond(400, ['error' => 'Choose a creator or genre and provide a search term.']);
        }
        if ($kind !== 'all' && !is_valid_kind($kind)) {
            respond(400, ['error' => 'Choose a valid media type.']);
        }

        ensure_title_metadata_columns($pdo);
        $conditions = [];
        $values = [];
        if ($type === 'creator') {
            $conditions[] = 't.creator LIKE ?';
            $values[] = '%' . $query . '%';
        } else {
            $conditions[] = 'FIND_IN_SET(LOWER(?), REPLACE(LOWER(COALESCE(t.genres, \'\')), \' \', \'\')) > 0';
            $values[] = str_replace(' ', '', strtolower(trim($query)));
        }
        if ($kind !== 'all') {
            $conditions[] = 't.kind = ?';
            $values[] = $kind;
        }

        $catalogStatement = $pdo->prepare(
            'SELECT t.id, t.title, t.kind, t.creator, t.isbn, t.barcode,
                    t.release_year AS releaseYear, t.cover_url AS coverUrl, t.description,
                    t.catalog_rating AS catalogRating, t.genres, t.tropes, t.tags
             FROM titles t WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY t.title ASC LIMIT 100'
        );
        $catalogStatement->execute($values);
        $catalog = $catalogStatement->fetchAll();
        foreach ($catalog as &$item) {
            $item['id'] = (int) $item['id'];
            $item['releaseYear'] = $item['releaseYear'] !== null ? (int) $item['releaseYear'] : null;
            $item['catalogRating'] = $item['catalogRating'] !== null ? (float) $item['catalogRating'] : null;
            $item['source'] = 'Catalog';
            $item['inCatalog'] = true;
        }
        unset($item);

        $publicResults = fetch_public_related_media($type, $kind, $query);
        $catalogByIsbn = [];
        $catalogByTitle = [];
        foreach ($catalog as $item) {
            if (!empty($item['isbn'])) {
                $catalogByIsbn[strtolower((string) $item['isbn'])] = $item;
            }
            $catalogByTitle[strtolower(trim((string) $item['kind'] . '|' . $item['title']))] = $item;
        }
        foreach ($publicResults as &$item) {
            $isbnKey = strtolower((string) ($item['isbn'] ?? ''));
            $titleKey = strtolower(trim($item['kind'] . '|' . $item['title']));
            $existing = ($isbnKey !== '' ? ($catalogByIsbn[$isbnKey] ?? null) : null)
                ?? ($catalogByTitle[$titleKey] ?? null);
            $item['inCatalog'] = $existing !== null;
            $item['catalogId'] = $existing['id'] ?? null;
        }
        unset($item);

        respond(200, [
            'query' => $query,
            'type' => $type,
            'kind' => $kind,
            'catalog' => $catalog,
            'publicResults' => $publicResults,
        ]);
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
            'summary'      => 'description',
            'series'       => 'series',
            'book_series'  => 'series'
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
            'summary'      => 'description',
            'series'       => 'series',
            'book_series'  => 'series'
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
        ensure_user_active_column($pdo);
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
            $statement = $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role, is_active) VALUES (?, ?, ?, ?, "member", 1)');
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
        ensure_user_active_column($pdo);
        $email = strtolower(trim(body_string($body, 'email')));
        $password = body_string($body, 'password');
        $statement = $pdo->prepare('SELECT id, name, username, email, password_hash, role, avatar_url AS avatarUrl, is_active FROM users WHERE email = ?');
        $statement->execute([$email]);
        $row = $statement->fetch();

        if (!$row || !password_verify($password, $row['password_hash']) || !(bool) $row['is_active']) {
            respond(401, ['error' => 'Email or password is incorrect.']);
        }

        $user = ['id' => (int) $row['id'], 'name' => $row['name'], 'username' => $row['username'], 'email' => $row['email'], 'role' => $row['role'], 'avatarUrl' => $row['avatarUrl']];
        respond(200, ['user' => $user, 'token' => create_token($user, $config['jwt_secret'])]);
    }

    if ($method === 'POST' && $route === '/auth/forgot-password') {
        $email = strtolower(trim(body_string($body, 'email')));
        if (!can_send_password_reset($config)) {
            respond(503, ['error' => 'Password reset email is not configured. Please contact an administrator.']);
        }

        ensure_user_active_column($pdo);
        $statement = $pdo->prepare('SELECT id, name FROM users WHERE email = ? AND is_active = 1');
        $statement->execute([$email]);
        $target = $statement->fetch();
        if ($target) {
            try {
                ensure_password_reset_table($pdo);
                issue_password_reset($pdo, $config, (int) $target['id'], $email, $target['name']);
            } catch (Throwable $error) {
                error_log('Password reset email failed: ' . $error->getMessage());
            }
        }
        respond(200, ['message' => 'If an active account uses that email, a password reset link has been sent.']);
    }

    if ($method === 'POST' && $route === '/auth/reset-password') {
        $token = trim(body_string($body, 'token'));
        $password = body_string($body, 'password');
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            respond(400, ['error' => 'This password reset link is invalid or expired. Request a new link.']);
        }
        if (text_length($password) < 8 || strlen($password) > 72) {
            respond(400, ['error' => 'Password must be between 8 and 72 characters.']);
        }

        ensure_user_active_column($pdo);
        ensure_password_reset_table($pdo);
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT t.id, t.user_id
                 FROM password_reset_tokens t
                 JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ? AND t.used_at IS NULL
                   AND t.expires_at > UTC_TIMESTAMP() AND u.is_active = 1
                 FOR UPDATE'
            );
            $statement->execute([hash('sha256', $token)]);
            $reset = $statement->fetch();
            if (!$reset) {
                $pdo->rollBack();
                respond(400, ['error' => 'This password reset link is invalid or expired. Request a new link.']);
            }

            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $reset['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP WHERE user_id = ? AND used_at IS NULL')
                ->execute([$reset['user_id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(200, ['message' => 'Password reset successfully. You can now sign in.']);
    }

    if ($method === 'GET' && $route === '/auth/me') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        respond(200, ['user' => $user]);
    }

    if ($method === 'POST' && $route === '/auth/change-password') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $currentPassword = body_string($body, 'currentPassword');
        $newPassword = body_string($body, 'newPassword');

        if (text_length($newPassword) < 8 || strlen($newPassword) > 72) {
            respond(400, ['error' => 'New password must be between 8 and 72 characters.']);
        }

        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$user['id']]);
        $passwordHash = $statement->fetchColumn();
        if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
            respond(401, ['error' => 'Current password is incorrect.']);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $user['id']]);
        respond(200, ['message' => 'Password updated.']);
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
        ensure_user_active_column($pdo);
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
             WHERE u.is_active = 1
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
    if ($method === 'GET' && $route === '/admin/users') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        ensure_user_active_column($pdo);
        $query = truncate_text(query_string('q'), 120);
        $offsetInput = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        $offset = $offsetInput !== false ? max(0, $offsetInput) : 0;
        $where = '';
        $values = [];
        if ($query !== '') {
            $where = 'WHERE u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?';
            $like = '%' . $query . '%';
            $values = [$like, $like, $like];
        }
        $count = $pdo->prepare("SELECT COUNT(*) FROM users u $where");
        $count->execute($values);
        $total = (int) $count->fetchColumn();
        $usersStatement = $pdo->prepare(
            "SELECT u.id, u.name, u.username, u.email, u.role,
                    u.is_active AS isActive, u.created_at AS createdAt,
                    (SELECT COUNT(*) FROM reviews r WHERE r.user_id = u.id) AS reviewCount,
                    (SELECT COUNT(*) FROM community_posts p WHERE p.user_id = u.id) AS postCount
             FROM users u
             $where
             ORDER BY u.created_at DESC
             LIMIT 100 OFFSET $offset"
        );
        $usersStatement->execute($values);
        $users = $usersStatement->fetchAll();
        foreach ($users as &$listedUser) {
            $listedUser['id'] = (int) $listedUser['id'];
            $listedUser['isActive'] = (bool) $listedUser['isActive'];
            $listedUser['reviewCount'] = (int) $listedUser['reviewCount'];
            $listedUser['postCount'] = (int) $listedUser['postCount'];
        }
        unset($listedUser);
        respond(200, ['users' => $users, 'total' => $total, 'hasMore' => $offset + count($users) < $total]);
    }

    if ($method === 'POST' && $route === '/admin/users') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        ensure_user_active_column($pdo);
        $name = trim(body_string($body, 'name'));
        $email = strtolower(trim(body_string($body, 'email')));
        if (text_length($name) < 2 || text_length($name) > 80) {
            respond(400, ['error' => 'Name must be between 2 and 80 characters.']);
        }
        if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) || strlen($email) > 254) {
            respond(400, ['error' => 'Enter a valid email address.']);
        }
        if (!can_send_password_reset($config)) {
            respond(503, ['error' => 'Configure password reset email before inviting users.']);
        }

        ensure_password_reset_table($pdo);
        $pdo->beginTransaction();
        try {
            $temporaryPassword = bin2hex(random_bytes(24));
            $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, is_active) VALUES (?, ?, ?, "member", 1)');
            $insert->execute([$name, $email, password_hash($temporaryPassword, PASSWORD_BCRYPT, ['cost' => 12])]);
            $newUserId = (int) $pdo->lastInsertId();
            issue_password_reset($pdo, $config, $newUserId, $email, $name);
            $pdo->commit();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                respond(409, ['error' => 'An account with that email already exists.']);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            respond(503, ['error' => $error->getMessage()]);
        }
        respond(201, ['message' => 'User created and password setup email sent.', 'userId' => $newUserId]);
    }

    if ($method === 'POST' && preg_match('#^/admin/users/([0-9]+)/password-reset$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        $userId = (int) $matches[1];
        $targetStatement = $pdo->prepare('SELECT name, email FROM users WHERE id = ? AND is_active = 1');
        $targetStatement->execute([$userId]);
        $target = $targetStatement->fetch();
        if (!$target) respond(404, ['error' => 'Active user not found.']);
        try {
            ensure_password_reset_table($pdo);
            issue_password_reset($pdo, $config, $userId, $target['email'], $target['name']);
        } catch (Throwable $error) {
            error_log('Admin password reset email failed: ' . $error->getMessage());
            respond(503, ['error' => $error->getMessage()]);
        }
        respond(200, ['message' => 'Password reset email sent.']);
    }

    if ($method === 'PATCH' && preg_match('#^/admin/users/([0-9]+)/status$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        ensure_user_active_column($pdo);
        $userId = (int) $matches[1];
        $isActive = $body['isActive'] ?? null;
        if (!is_bool($isActive)) respond(400, ['error' => 'isActive must be true or false.']);
        if (!$isActive && $userId === (int) $admin['id']) {
            respond(409, ['error' => 'You cannot deactivate your own account.']);
        }
        $targetStatement = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
        $targetStatement->execute([$userId]);
        $target = $targetStatement->fetch();
        if (!$target) respond(404, ['error' => 'User not found.']);
        if (!$isActive && $target['role'] === 'admin' && (bool) $target['is_active']) {
            $activeAdmins = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1')->fetchColumn();
            if ($activeAdmins <= 1) respond(409, ['error' => 'The last active administrator cannot be deactivated.']);
        }
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$isActive ? 1 : 0, $userId]);
        respond(200, ['message' => $isActive ? 'User reactivated.' : 'User deactivated.', 'isActive' => $isActive]);
    }

    if ($method === 'PATCH' && preg_match('#^/admin/users/([0-9]+)/role$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);

        $targetUserId = (int) $matches[1];
        $newRole = body_string($body, 'role');

        if (!in_array($newRole, ['admin', 'moderator', 'member'], true)) {
            respond(400, ['error' => 'Invalid role specified. Use admin, moderator, or member.']);
        }

        ensure_user_active_column($pdo);
        $targetStatement = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
        $targetStatement->execute([$targetUserId]);
        $target = $targetStatement->fetch();
        if (!$target) respond(404, ['error' => 'User not found.']);
        if ($target['role'] === 'admin' && $newRole !== 'admin' && (bool) $target['is_active']) {
            $activeAdmins = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE role = "admin" AND is_active = 1')->fetchColumn();
            if ($activeAdmins <= 1) respond(409, ['error' => 'The last active administrator cannot be demoted.']);
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
            'series' => 'series',
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
                    'series' => 200,
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

        if (array_intersect(['catalogRating', 'genres', 'tropes', 'tags', 'series'], array_keys($fields))) {
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

    if ($method === 'GET' && preg_match('#^/titles/([0-9]+)/related$#', $route, $matches)) {
        $titleId = (int) $matches[1];
        $catalogRatingColumn = title_metadata_column_exists($pdo, 'catalog_rating') ? 't.catalog_rating' : 'NULL';
        $genresColumn = title_metadata_column_exists($pdo, 'genres') ? 't.genres' : 'NULL';
        $tropesColumn = title_metadata_column_exists($pdo, 'tropes') ? 't.tropes' : 'NULL';
        $tagsColumn = title_metadata_column_exists($pdo, 'tags') ? 't.tags' : 'NULL';
        $seriesColumn = title_metadata_column_exists($pdo, 'series') ? 't.series' : 'NULL';
        $titleStatement = $pdo->prepare(
            "SELECT t.id, t.title, t.kind, t.creator, t.isbn, t.barcode,
                    t.release_year AS releaseYear, t.cover_url AS coverUrl, t.description,
                    {$catalogRatingColumn} AS catalogRating, {$genresColumn} AS genres,
                    {$tropesColumn} AS tropes, {$tagsColumn} AS tags, {$seriesColumn} AS series,
                    COALESCE(stats.reviewCount, 0) AS reviewCount,
                    stats.averageSpice, stats.averageRating
             FROM titles t
             LEFT JOIN (
                SELECT title_id, COUNT(*) AS reviewCount,
                       ROUND(AVG(spice_level), 1) AS averageSpice,
                       ROUND(AVG(rating), 1) AS averageRating
                FROM reviews
                GROUP BY title_id
             ) stats ON stats.title_id = t.id
             WHERE t.id = ?"
        );
        $titleStatement->execute([$titleId]);
        $title = $titleStatement->fetch();
        if (!$title) respond(404, ['error' => 'Title not found.']);

        $title['id'] = (int) $title['id'];
        $title['releaseYear'] = $title['releaseYear'] !== null ? (int) $title['releaseYear'] : null;
        $title['catalogRating'] = $title['catalogRating'] !== null ? (float) $title['catalogRating'] : null;
        $title['reviewCount'] = (int) $title['reviewCount'];
        $title['averageSpice'] = $title['averageSpice'] !== null ? (float) $title['averageSpice'] : null;
        $title['averageRating'] = $title['averageRating'] !== null ? (float) $title['averageRating'] : null;

        $viewerStatus = null;
        if (request_authorization_header() !== '') {
            $viewer = authenticated_user($config['jwt_secret'], $pdo);
            $statusStatement = $pdo->prepare('SELECT status FROM user_libraries WHERE user_id = ? AND title_id = ?');
            $statusStatement->execute([$viewer['id'], $titleId]);
            $viewerStatus = $statusStatement->fetchColumn();
            if ($viewerStatus === false) $viewerStatus = null;
        }

        $seriesName = is_string($title['series']) ? trim($title['series']) : '';
        $creator = is_string($title['creator']) ? trim($title['creator']) : '';
        $genres = metadata_tokens(is_string($title['genres']) ? $title['genres'] : null);
        $tropes = metadata_tokens(is_string($title['tropes']) ? $title['tropes'] : null);
        $conditions = [];
        $relatedValues = [$titleId, $title['kind']];

        if ($seriesName !== '' && $seriesColumn !== 'NULL') {
            $conditions[] = 'LOWER(TRIM(' . $seriesColumn . ')) = LOWER(TRIM(?))';
            $relatedValues[] = $seriesName;
        }
        if ($creator !== '') {
            $conditions[] = 'LOWER(TRIM(t.creator)) = LOWER(TRIM(?))';
            $relatedValues[] = $creator;
        }
        foreach ($genres as $genre) {
            $conditions[] = "FIND_IN_SET(?, REPLACE(LOWER(COALESCE({$genresColumn}, '')), ' ', '')) > 0";
            $relatedValues[] = $genre;
        }
        foreach ($tropes as $trope) {
            $conditions[] = "FIND_IN_SET(?, REPLACE(LOWER(COALESCE({$tropesColumn}, '')), ' ', '')) > 0";
            $relatedValues[] = $trope;
        }

        $related = [];
        if ($conditions !== []) {
            $relatedStatement = $pdo->prepare(
                "SELECT t.id, t.title, t.kind, t.creator, t.release_year AS releaseYear,
                        t.cover_url AS coverUrl, t.description, {$genresColumn} AS genres,
                        {$tropesColumn} AS tropes, {$seriesColumn} AS series,
                        COALESCE(stats.reviewCount, 0) AS reviewCount,
                        stats.averageSpice, stats.averageRating
                 FROM titles t
                 LEFT JOIN (
                    SELECT title_id, COUNT(*) AS reviewCount,
                           ROUND(AVG(spice_level), 1) AS averageSpice,
                           ROUND(AVG(rating), 1) AS averageRating
                    FROM reviews
                    GROUP BY title_id
                 ) stats ON stats.title_id = t.id
                 WHERE t.id <> ? AND t.kind = ? AND (" . implode(' OR ', $conditions) . ')
                 LIMIT 200'
            );
            $relatedStatement->execute($relatedValues);
            $related = $relatedStatement->fetchAll();

            foreach ($related as &$candidate) {
                $candidateGenres = metadata_tokens(is_string($candidate['genres']) ? $candidate['genres'] : null);
                $candidateTropes = metadata_tokens(is_string($candidate['tropes']) ? $candidate['tropes'] : null);
                $sameSeries = $seriesName !== '' && is_string($candidate['series'])
                    && strcasecmp(trim($candidate['series']), $seriesName) === 0;
                $sameAuthor = $creator !== '' && is_string($candidate['creator'])
                    && strcasecmp(trim($candidate['creator']), $creator) === 0;
                $sharedGenres = array_values(array_intersect($genres, $candidateGenres));
                $sharedTropes = array_values(array_intersect($tropes, $candidateTropes));
                $candidate['matchScore'] = ($sameSeries ? 1000 : 0)
                    + ($sameAuthor ? 100 : 0)
                    + count($sharedGenres) * 15
                    + count($sharedTropes) * 20;
                $reasons = [];
                if ($sameSeries) $reasons[] = 'Same series';
                if ($sameAuthor) $reasons[] = 'More by this author';
                if ($sharedGenres !== []) $reasons[] = 'Genre: ' . implode(', ', $sharedGenres);
                if ($sharedTropes !== []) $reasons[] = 'Trope: ' . implode(', ', $sharedTropes);
                $candidate['matchReason'] = implode(' · ', $reasons);
                $candidate['id'] = (int) $candidate['id'];
                $candidate['releaseYear'] = $candidate['releaseYear'] !== null ? (int) $candidate['releaseYear'] : null;
                $candidate['reviewCount'] = (int) $candidate['reviewCount'];
                $candidate['averageSpice'] = $candidate['averageSpice'] !== null ? (float) $candidate['averageSpice'] : null;
                $candidate['averageRating'] = $candidate['averageRating'] !== null ? (float) $candidate['averageRating'] : null;
            }
            unset($candidate);
            usort($related, static function (array $a, array $b): int {
                $scoreComparison = $b['matchScore'] <=> $a['matchScore'];
                return $scoreComparison !== 0 ? $scoreComparison : strcasecmp($a['title'], $b['title']);
            });
            $related = array_slice($related, 0, 40);
        }

        respond(200, ['title' => $title, 'viewerStatus' => $viewerStatus, 'related' => $related]);
    }

    if ($method === 'GET' && $route === '/titles') {
        $rawQuery = query_string('q');
        $query = truncate_text($rawQuery, 120);
        $kind = query_string('kind');
        $minSpice = query_string('minSpice');
        $maxSpice = query_string('maxSpice');
        $needsInfo = query_string('needsInfo');
        $offsetInput = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        $limitInput = filter_var($_GET['limit'] ?? 60, FILTER_VALIDATE_INT);
        $offset = $offsetInput !== false ? max(0, $offsetInput) : 0;
        $limit = $limitInput !== false ? min(200, max(1, $limitInput)) : 60;
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
        $seriesColumn = title_metadata_column_exists($pdo, 'series') ? 't.series' : 'NULL';

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
        $countStatement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM titles t
             LEFT JOIN (
                SELECT title_id, ROUND(AVG(spice_level), 1) AS averageSpice
                FROM reviews
                GROUP BY title_id
             ) stats ON stats.title_id = t.id
             $where"
        );
        $countStatement->execute($values);
        $total = (int) $countStatement->fetchColumn();

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
                    {$seriesColumn} AS series,
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
                " . ($needsInfo === '1' ? '' : 'LIMIT ' . $limit . ' OFFSET ' . $offset);

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

        respond(200, ['titles' => $rows, 'total' => $total, 'hasMore' => $needsInfo !== '1' && $offset + count($rows) < $total]);
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
        $series = trim(body_string($body, 'series')) ?: null;

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
        if ($series !== null && text_length($series) > 200) {
            respond(400, ['error' => 'Series must be 200 characters or fewer.']);
        }
        if ($coverUrl !== null && (!preg_match('/^https?:\/\//i', $coverUrl) || text_length($coverUrl) > 2048)) {
            respond(400, ['error' => 'Cover URL must be a valid http(s) URL.']);
        }

        ensure_title_metadata_columns($pdo);
        $titleId = null;
        try {
            $statement = $pdo->prepare(
                'INSERT INTO titles (title, kind, creator, isbn, barcode, release_year, cover_url, description, series, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([$title, $kind, $creator, $isbn, $barcode, $releaseYear, $coverUrl, $description, $series, $user['id']]);
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
            'series' => $series,
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
        foreach (['genres', 'tropes', 'tags', 'series'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim(body_string($body, $field)) ?: null;
                $maxLength = $field === 'series' ? 200 : 10000;
                if ($value !== null && text_length($value) > $maxLength) {
                    respond(400, ucfirst($field) . " must be {$maxLength} characters or fewer.");
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
                COALESCE(stats.reviewCount, 0) AS reviewCount,
                stats.averageSpice,
                stats.averageRating,
                r.rating AS userRating,
                r.spice_level AS userSpice
             FROM user_libraries ul
             JOIN titles t ON t.id = ul.title_id
             LEFT JOIN reviews r ON r.title_id = t.id AND r.user_id = ul.user_id
             LEFT JOIN (
                SELECT title_id, COUNT(*) AS reviewCount,
                       ROUND(AVG(spice_level), 1) AS averageSpice,
                       ROUND(AVG(rating), 1) AS averageRating
                FROM reviews
                GROUP BY title_id
             ) stats ON stats.title_id = t.id
             WHERE ul.user_id = ?
             ORDER BY ul.created_at DESC'
        );
        $stmt->execute([$targetUserId]);
        $library = $stmt->fetchAll();

        foreach ($library as &$entry) {
            $entry['libraryEntryId'] = (int) $entry['libraryEntryId'];
            $entry['titleId'] = (int) $entry['titleId'];
            $entry['releaseYear'] = $entry['releaseYear'] !== null ? (int) $entry['releaseYear'] : null;
            $entry['reviewCount'] = (int) $entry['reviewCount'];
            $entry['averageSpice'] = $entry['averageSpice'] !== null ? (float) $entry['averageSpice'] : null;
            $entry['averageRating'] = $entry['averageRating'] !== null ? (float) $entry['averageRating'] : null;
            $entry['userRating'] = $entry['userRating'] !== null ? (int) $entry['userRating'] : null;
            $entry['userSpice'] = $entry['userSpice'] !== null ? (int) $entry['userSpice'] : null;
        }
        unset($entry);

        $relations = ['isBlocked' => false, 'friendStatus' => null];
        $authorization = request_authorization_header();
        if ($authorization !== '') {
            $viewer = authenticated_user($config['jwt_secret'], $pdo);
            if ((int) $viewer['id'] !== $targetUserId) {
                ensure_social_tables($pdo);
                $blocked = $pdo->prepare(
                    'SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?
                     UNION SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1'
                );
                $blocked->execute([$viewer['id'], $targetUserId, $targetUserId, $viewer['id']]);
                $relations['isBlocked'] = (bool) $blocked->fetch();

                if (!$relations['isBlocked']) {
                    $friendship = $pdo->prepare(
                        'SELECT requester_id, addressee_id, status FROM friendships
                         WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)
                         LIMIT 1'
                    );
                    $friendship->execute([$viewer['id'], $targetUserId, $targetUserId, $viewer['id']]);
                    $relation = $friendship->fetch();
                    if ($relation) {
                        if ($relation['status'] === 'accepted') {
                            $relations['friendStatus'] = 'accepted';
                        } else {
                            $relations['friendStatus'] = (int) $relation['requester_id'] === (int) $viewer['id']
                                ? 'request_sent'
                                : 'request_received';
                        }
                    }
                }
            }
        }

        respond(200, ['owner' => $owner, 'library' => $library, 'relations' => $relations]);
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
        ensure_community_lock_column($pdo);
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
                p.is_locked AS isLocked,
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
            $post['isLocked'] = (bool) $post['isLocked'];
            $post['linkedTitleId'] = $post['linkedTitleId'] !== null ? (int) $post['linkedTitleId'] : null;
            $post['comments'] = $commentsByPost[$post['id']] ?? [];
        }
        unset($post);

        respond(200, ['posts' => $posts]);
    }

    if ($method === 'POST' && $route === '/community/posts') {
        ensure_community_lock_column($pdo);
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

        ensure_community_lock_column($pdo);
        $pdo->beginTransaction();
        try {
            $postStatement = $pdo->prepare('SELECT is_locked FROM community_posts WHERE id = ? FOR UPDATE');
            $postStatement->execute([$postId]);
            $post = $postStatement->fetch();
            if (!$post) {
                $pdo->rollBack();
                respond(404, ['error' => 'Post not found.']);
            }
            if ((bool) $post['is_locked']) {
                $pdo->rollBack();
                respond(423, ['error' => 'This post is locked and no longer accepts replies.']);
            }

            $stmt = $pdo->prepare('INSERT INTO community_comments (post_id, user_id, body) VALUES (?, ?, ?)');
            $stmt->execute([$postId, $user['id'], $content]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(201, ['message' => 'Reply added.']);
    }

    if ($method === 'GET' && $route === '/admin/community/posts') {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        ensure_community_lock_column($pdo);
        $stmt = $pdo->query(
            'SELECT p.id, p.category, p.topic, p.body, p.is_locked AS isLocked,
                    p.created_at AS createdAt, u.id AS authorId, u.name AS authorName,
                    u.email AS authorEmail,
                    (SELECT COUNT(*) FROM community_comments c WHERE c.post_id = p.id) AS commentCount
             FROM community_posts p
             JOIN users u ON u.id = p.user_id
             ORDER BY p.created_at DESC
             LIMIT 100'
        );
        $posts = $stmt->fetchAll();
        foreach ($posts as &$post) {
            $post['id'] = (int) $post['id'];
            $post['authorId'] = (int) $post['authorId'];
            $post['commentCount'] = (int) $post['commentCount'];
            $post['isLocked'] = (bool) $post['isLocked'];
        }
        unset($post);

        $commentStatement = $pdo->query(
            'SELECT c.id, c.post_id AS postId, c.body, c.created_at AS createdAt,
                    u.id AS authorId, u.name AS authorName, u.email AS authorEmail
             FROM community_comments c
             JOIN users u ON u.id = c.user_id
             ORDER BY c.created_at DESC
             LIMIT 300'
        );
        $comments = $commentStatement->fetchAll();
        foreach ($comments as &$comment) {
            $comment['id'] = (int) $comment['id'];
            $comment['postId'] = (int) $comment['postId'];
            $comment['authorId'] = (int) $comment['authorId'];
        }
        unset($comment);
        respond(200, ['posts' => $posts, 'comments' => $comments]);
    }

    if ($method === 'PATCH' && preg_match('#^/admin/community/posts/([0-9]+)/lock$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        ensure_community_lock_column($pdo);
        $isLocked = $body['isLocked'] ?? null;
        if (!is_bool($isLocked)) respond(400, ['error' => 'isLocked must be true or false.']);
        $statement = $pdo->prepare('UPDATE community_posts SET is_locked = ? WHERE id = ?');
        $statement->execute([$isLocked ? 1 : 0, (int) $matches[1]]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM community_posts WHERE id = ?');
            $exists->execute([(int) $matches[1]]);
            if (!$exists->fetch()) respond(404, ['error' => 'Post not found.']);
        }
        respond(200, ['message' => $isLocked ? 'Post locked.' : 'Post unlocked.', 'isLocked' => $isLocked]);
    }

    if ($method === 'DELETE' && preg_match('#^/admin/community/posts/([0-9]+)$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        $postId = (int) $matches[1];
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM community_comments WHERE post_id = ?')->execute([$postId]);
            $statement = $pdo->prepare('DELETE FROM community_posts WHERE id = ?');
            $statement->execute([$postId]);
            if ($statement->rowCount() === 0) {
                $pdo->rollBack();
                respond(404, ['error' => 'Post not found.']);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        respond(200, ['message' => 'Post and its comments deleted.']);
    }

    if ($method === 'DELETE' && preg_match('#^/admin/community/comments/([0-9]+)$#', $route, $matches)) {
        $admin = authenticated_user($config['jwt_secret'], $pdo);
        require_role($admin, ['admin']);
        $statement = $pdo->prepare('DELETE FROM community_comments WHERE id = ?');
        $statement->execute([(int) $matches[1]]);
        if ($statement->rowCount() === 0) respond(404, ['error' => 'Comment not found.']);
        respond(200, ['message' => 'Comment deleted.']);
    }

    if ($method === 'POST' && preg_match('#^/community/posts/([0-9]+)/like$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        $postId = (int) $matches[1];
        $stmt = $pdo->prepare('UPDATE community_posts SET likes_count = likes_count + 1 WHERE id = ?');
        $stmt->execute([$postId]);
        respond(200, ['message' => 'Liked!']);
    }

    // --- Messages & Friends / Blocks ---
    if ($method === 'GET' && $route === '/messages/inbox') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);

        // Latest message per conversation partner
        $stmt = $pdo->prepare(
            'SELECT m.*, other.name AS partnerName, other.avatar_url AS partnerAvatar
             FROM messages m
             JOIN users other ON other.id = CASE
                 WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id
             END
             JOIN (
                 SELECT
                   CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS partner,
                   MAX(id) AS last_id
                 FROM messages
                 WHERE sender_id = ? OR receiver_id = ?
                 GROUP BY partner
             ) latest ON latest.last_id = m.id
             ORDER BY m.created_at DESC
             LIMIT 50'
        );
        $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
        $rows = $stmt->fetchAll();

        $inbox = array_map(static function ($r) use ($user) {
            return [
                'partnerId'     => (int) ($r['sender_id'] === (int) $user['id'] ? $r['receiver_id'] : $r['sender_id']),
                'partnerName'   => $r['partnerName'],
                'partnerAvatar' => $r['partnerAvatar'],
                'lastMessage'   => truncate_text($r['content'], 200),
                'lastMessageAt' => $r['created_at'],
                'senderId'      => (int) $r['sender_id'],
                'isRead'        => (bool) $r['is_read'],
            ];
        }, $rows);

        respond(200, ['inbox' => $inbox]);
    }

    if ($method === 'GET' && preg_match('#^/messages/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $partnerId = (int) $matches[1];

        $check = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $check->execute([$partnerId]);
        if (!$check->fetch()) {
            respond(404, ['error' => 'User not found.']);
        }

        // Mark partner's messages to me as read
        $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0')
            ->execute([$partnerId, $user['id']]);

        $stmt = $pdo->prepare(
            'SELECT id, sender_id AS senderId, receiver_id AS receiverId, content, is_read AS isRead, created_at AS createdAt
             FROM messages
             WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
             ORDER BY created_at ASC
             LIMIT 500'
        );
        $stmt->execute([$user['id'], $partnerId, $partnerId, $user['id']]);
        $messages = $stmt->fetchAll();
        foreach ($messages as &$m) {
            $m['id'] = (int) $m['id'];
            $m['senderId'] = (int) $m['senderId'];
            $m['receiverId'] = (int) $m['receiverId'];
            $m['isRead'] = (bool) $m['isRead'];
        }
        unset($m);

        respond(200, ['messages' => $messages]);
    }

    if ($method === 'POST' && preg_match('#^/messages/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $partnerId = (int) $matches[1];

        if ($partnerId === (int) $user['id']) {
            respond(400, ['error' => 'You cannot message yourself.']);
        }

        $content = trim(body_string($body, 'content'));
        if ($content === '' || text_length($content) > 4000) {
            respond(400, ['error' => 'Message must be between 1 and 4,000 characters.']);
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $check->execute([$partnerId]);
        if (!$check->fetch()) {
            respond(404, ['error' => 'User not found.']);
        }

        $blocked = $pdo->prepare(
            'SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?
             UNION SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1'
        );
        $blocked->execute([$user['id'], $partnerId, $partnerId, $user['id']]);
        if ($blocked->fetch()) {
            respond(409, ['error' => 'Messaging unavailable while either user has blocked the other.']);
        }

        $stmt = $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, content) VALUES (?, ?, ?)');
        $stmt->execute([$user['id'], $partnerId, $content]);

        respond(201, ['message' => 'Message sent.', 'id' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'POST' && $route === '/friends/request') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $friendId = filter_var($body['friendId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($friendId === false) {
            respond(400, ['error' => 'A valid friendId is required.']);
        }
        if ((int) $friendId === (int) $user['id']) {
            respond(400, ['error' => 'You cannot send a friend request to yourself.']);
        }

        $target = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $target->execute([$friendId]);
        if (!$target->fetch()) {
            respond(404, ['error' => 'User not found.']);
        }

        $blocked = $pdo->prepare(
            'SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?
             UNION SELECT 1 FROM user_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1'
        );
        $blocked->execute([$user['id'], $friendId, $friendId, $user['id']]);
        if ($blocked->fetch()) {
            respond(409, ['error' => 'A friend request cannot be sent while either user has blocked the other.']);
        }

        $existing = $pdo->prepare(
            'SELECT requester_id, addressee_id, status FROM friendships
             WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)
             LIMIT 1'
        );
        $existing->execute([$user['id'], $friendId, $friendId, $user['id']]);
        $friendship = $existing->fetch();
        if ($friendship) {
            if ($friendship['status'] === 'accepted') {
                respond(409, ['error' => 'You are already friends.']);
            }
            if ((int) $friendship['requester_id'] === (int) $friendId) {
                respond(409, ['error' => 'This user has already sent you a request. Accept it from your friend list.']);
            }
            respond(409, ['error' => 'A friend request has already been sent.']);
        }

        $insert = $pdo->prepare(
            'INSERT INTO friendships (requester_id, addressee_id, status) VALUES (?, ?, \'pending\')'
        );
        $insert->execute([$user['id'], $friendId]);
        respond(201, ['message' => 'Friend request sent.']);
    }

    if ($method === 'PUT' && preg_match('#^/friends/([0-9]+)/accept$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $requesterId = (int) $matches[1];
        $accept = $pdo->prepare(
            'UPDATE friendships SET status = \'accepted\'
             WHERE requester_id = ? AND addressee_id = ? AND status = \'pending\''
        );
        $accept->execute([$requesterId, $user['id']]);
        if ($accept->rowCount() === 0) {
            respond(404, ['error' => 'Pending friend request not found.']);
        }
        respond(200, ['message' => 'Friend request accepted.']);
    }

    if ($method === 'DELETE' && preg_match('#^/friends/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $friendId = (int) $matches[1];
        $remove = $pdo->prepare(
            'DELETE FROM friendships
             WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)'
        );
        $remove->execute([$user['id'], $friendId, $friendId, $user['id']]);
        respond(200, ['message' => 'Friend relationship removed.']);
    }

    if ($method === 'GET' && $route === '/friends') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $statement = $pdo->prepare(
            'SELECT
                CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END AS id,
                other.name,
                other.username,
                other.avatar_url AS avatarUrl,
                f.requester_id AS requesterId,
                f.status,
                f.created_at AS createdAt
             FROM friendships f
             JOIN users other ON other.id = CASE WHEN f.requester_id = ? THEN f.addressee_id ELSE f.requester_id END
             WHERE (f.requester_id = ? OR f.addressee_id = ?)
             ORDER BY f.status = \'pending\' DESC, f.created_at DESC'
        );
        $statement->execute([$user['id'], $user['id'], $user['id'], $user['id']]);
        $friends = $statement->fetchAll();
        foreach ($friends as &$friend) {
            $friend['id'] = (int) $friend['id'];
            $friend['requesterId'] = (int) $friend['requesterId'];
        }
        unset($friend);
        respond(200, ['friends' => $friends]);
    }

    if ($method === 'POST' && $route === '/blocks') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $blockedId = filter_var($body['blockId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($blockedId === false) {
            respond(400, ['error' => 'A valid blockId is required.']);
        }
        if ((int) $blockedId === (int) $user['id']) {
            respond(400, ['error' => 'You cannot block yourself.']);
        }
        $target = $pdo->prepare('SELECT id FROM users WHERE id = ?');
        $target->execute([$blockedId]);
        if (!$target->fetch()) {
            respond(404, ['error' => 'User not found.']);
        }
        $pdo->prepare('INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)')->execute([$user['id'], $blockedId]);
        $pdo->prepare(
            'DELETE FROM friendships
             WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?)'
        )->execute([$user['id'], $blockedId, $blockedId, $user['id']]);
        respond(200, ['message' => 'User blocked.']);
    }

    if ($method === 'DELETE' && preg_match('#^/blocks/([0-9]+)$#', $route, $matches)) {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $pdo->prepare('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?')->execute([$user['id'], (int) $matches[1]]);
        respond(200, ['message' => 'User unblocked.']);
    }

    if ($method === 'GET' && $route === '/blocks') {
        $user = authenticated_user($config['jwt_secret'], $pdo);
        ensure_social_tables($pdo);
        $statement = $pdo->prepare(
            'SELECT u.id, u.name, u.username, u.avatar_url AS avatarUrl
             FROM user_blocks b JOIN users u ON u.id = b.blocked_id
             WHERE b.blocker_id = ? ORDER BY b.created_at DESC'
        );
        $statement->execute([$user['id'], $user['id']]);
        $blocks = $statement->fetchAll();
        foreach ($blocks as &$block) {
            $block['id'] = (int) $block['id'];
        }
        unset($block);
        respond(200, ['blocks' => $blocks]);
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