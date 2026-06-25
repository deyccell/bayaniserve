<?php
/**
 * BayaniServe — SMS Parser
 *
 * Parses inbound SMS text into structured intent.
 * Runs on the LOCAL PC, called by receive.php when Gammu fires.
 *
 * Supported commands (case-insensitive):
 *   CHECK [medicine] [barangay]          → stock check
 *   RESERVE [medicine] [barangay] [name] → create reservation
 *   REQUEST [medicine] [barangay] [name] → create medicine request
 *   HELP                                 → send command list
 *
 * Examples:
 *   CHECK paracetamol hilamonan
 *   RESERVE amoxicillin camugao Juan dela Cruz
 *   REQUEST mefenamic inapoy Maria Santos
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/smart_inventory.php';
require_once __DIR__ . '/send_sms.php';

/**
 * Main entry point — called with the raw incoming SMS.
 *
 * @param string $from    Sender's mobile number
 * @param string $rawText The SMS body
 */
function handleIncomingSMS(string $from, string $rawText): void {
    $pdo  = getDB();
    $text = trim($rawText);

    // Log inbound
    $pdo->prepare("INSERT INTO sms_log (direction, mobile_number, message, status) VALUES ('inbound', ?, ?, 'received')")
        ->execute([$from, $text]);

    $upper = strtoupper($text);
    $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $cmd   = strtoupper($parts[0] ?? '');

    if (empty($cmd) || ($cmd === 'HELP' && count($parts) === 1)) {
        queueReply($from, helpText());
        return;
    }

    if ($cmd === 'HELP' || $cmd === 'STATUS') {
        handleLocalStatus($pdo, $from, $parts);
        return;
    }

    if ($cmd === 'CHECK') {
        handleCheck($pdo, $from, $parts);
        return;
    }

    if ($cmd === 'RESERVE') {
        handleReserve($pdo, $from, $parts, $text);
        return;
    }

    if ($cmd === 'REQUEST') {
        handleRequest($pdo, $from, $parts, $text);
        return;
    }

    if ($cmd === 'SUBSCRIBE') {
        handleSubscribe($pdo, $from, $parts);
        return;
    }

    if ($cmd === 'UNSUBSCRIBE') {
        handleUnsubscribe($pdo, $from, $parts);
        return;
    }

    // Unrecognized
    queueReply($from,
        "Hindi nakikilala ang command. " .
        "I-text ang HELP para sa listahan ng mga commands. " .
        "Example: CHECK paracetamol hilamonan"
    );
}

// ── OFFLINE STATUS CHATBOT ───────────────────────────────────────
// Usage:
//   STATUS MEDICINE [medicine] [barangay optional]
//   STATUS FLOOD [zone/barangay optional]
//   HELP FLOOD ZONE 4
function handleLocalStatus(PDO $pdo, string $from, array $parts): void {
    if (count($parts) < 2) {
        queueReply($from,
            "Format: STATUS MEDICINE [gamot] [barangay]\n" .
            "Halimbawa: STATUS MEDICINE losartan hilamonan\n" .
            "Pwede man: HELP FLOOD ZONE 4"
        );
        return;
    }

    $topic = strtoupper($parts[1]);

    if ($topic === 'MEDICINE' || $topic === 'MED' || $topic === 'GAMOT') {
        if (count($parts) < 3) {
            queueReply($from, "Format: STATUS MEDICINE [gamot] [barangay optional]");
            return;
        }

        $medicineKeyword = $parts[2];
        $barangayKeyword = count($parts) >= 4 ? implode(' ', array_slice($parts, 3)) : '';
        $station = $barangayKeyword !== '' ? findStation($pdo, $barangayKeyword) : null;

        $where = "m.name LIKE ?";
        $params = ['%' . $medicineKeyword . '%'];
        if ($station) {
            $where .= " AND i.station_id = ?";
            $params[] = $station['id'];
        }

        $stmt = $pdo->prepare("
            SELECT m.name, i.quantity, i.status, hs.barangay_name
            FROM inventory i
            JOIN medicines m ON m.id = i.medicine_id
            LEFT JOIN health_stations hs ON hs.id = i.station_id
            WHERE {$where}
            ORDER BY i.quantity DESC, hs.barangay_name ASC
            LIMIT 6
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (empty($rows)) {
            queueReply($from, "Wala sang record para sa {$medicineKeyword}. I-text CHECK {$medicineKeyword} [barangay] para magtan-aw sang BHS stock.");
            return;
        }

        $lines = ["BayaniServe offline status:"];
        foreach ($rows as $row) {
            $statusLabel = match($row['status']) {
                'in_stock' => "Available",
                'low_stock' => "Kubos na",
                'out_of_stock' => "Wala na",
                default => $row['status']
            };
            $lines[] = "{$row['barangay_name']} BHS - {$row['name']}: {$statusLabel} ({$row['quantity']})";
        }
        queueReply($from, implode("\n", $lines));
        return;
    }

    if ($topic === 'FLOOD' || $topic === 'BAGYO' || $topic === 'CALAMITY') {
        $keyword = count($parts) >= 3 ? implode(' ', array_slice($parts, 2)) : '';

        if (smartInventoryTableExists($pdo, 'barangay_alerts')) {
            $stmt = $pdo->prepare("
                SELECT title, message, severity, created_at
                FROM barangay_alerts
                WHERE is_active = 1
                  AND (? = '' OR title LIKE ? OR message LIKE ?)
                ORDER BY created_at DESC
                LIMIT 3
            ");
            $like = '%' . $keyword . '%';
            $stmt->execute([$keyword, $like, $like]);
            $alerts = $stmt->fetchAll();

            if (!empty($alerts)) {
                $lines = ["BayaniServe alert status:"];
                foreach ($alerts as $alert) {
                    $lines[] = strtoupper($alert['severity']) . ": {$alert['title']} - {$alert['message']}";
                }
                queueReply($from, implode("\n", $lines));
                return;
            }
        }

        queueReply($from,
            "Wala sang active local alert nga nakita" .
            ($keyword !== '' ? " para sa {$keyword}" : "") .
            ". Kung emergency, magkontak sa barangay DRRMO/BHS dayon."
        );
        return;
    }

    queueReply($from, "Hindi kilala ang STATUS topic. Gamiton: STATUS MEDICINE [gamot] or HELP FLOOD [lugar].");
}

// ── CHECK ─────────────────────────────────────────────────────────
// Usage: CHECK [medicine] [barangay]
function handleCheck(PDO $pdo, string $from, array $parts): void {
    // parts[0] = CHECK, parts[1] = medicine keyword, parts[2] = barangay
    if (count($parts) < 3) {
        queueReply($from, "Format: CHECK [gamot] [barangay]\nHalimbawa: CHECK paracetamol hilamonan");
        return;
    }

    $medKeyword = $parts[1];
    // barangay is everything after medicine keyword
    $barangay   = implode(' ', array_slice($parts, 2));

    $station = findStation($pdo, $barangay);
    if (!$station) {
        queueReply($from, "Hindi mahanap ang barangay na '{$barangay}'. Available: Hilamonan, Camugao, Inapoy.");
        return;
    }

    // Search medicine (partial match)
    $stmt = $pdo->prepare("
        SELECT m.name, i.quantity, i.status
        FROM inventory i
        JOIN medicines m ON m.id = i.medicine_id
        WHERE i.station_id = ? AND m.name LIKE ?
        ORDER BY m.name
        LIMIT 5
    ");
    $stmt->execute([$station['id'], '%' . $medKeyword . '%']);
    $results = $stmt->fetchAll();

    if (empty($results)) {
        queueReply($from,
            "Wala sang {$medKeyword} sa {$station['barangay_name']} BHS. " .
            "I-text ang REQUEST {$medKeyword} {$station['barangay_name']} [imo ngalan] para mag-request."
        );
        return;
    }

    $lines = ["Stock sa {$station['barangay_name']} BHS:"];
    foreach ($results as $r) {
        $statusLabel = match($r['status']) {
            'in_stock'    => "Available ({$r['quantity']} units)",
            'low_stock'   => "Kubos na ({$r['quantity']} units)",
            'out_of_stock'=> "Wala na (0 units)",
            default       => $r['status']
        };
        $lines[] = "• {$r['name']}: {$statusLabel}";
    }
    $lines[] = "Para mag-reserve: RESERVE {$medKeyword} {$station['barangay_name']} [imo ngalan]";

    queueReply($from, implode("\n", $lines));
}

// ── RESERVE ───────────────────────────────────────────────────────
// Usage: RESERVE [medicine] [barangay] [full name]
function handleReserve(PDO $pdo, string $from, array $parts, string $rawText = ''): void {
    if (count($parts) < 4) {
        queueReply($from,
            "Format: RESERVE [gamot] [barangay] [imo ngalan]\n" .
            "Halimbawa: RESERVE paracetamol hilamonan Juan dela Cruz"
        );
        return;
    }

    $medKeyword = $parts[1];
    $barangay   = $parts[2];
    $residentName = implode(' ', array_slice($parts, 3));

    $station = findStation($pdo, $barangay);
    if (!$station) {
        queueReply($from, "Hindi mahanap ang barangay na '{$barangay}'. Available: Hilamonan, Camugao, Inapoy.");
        return;
    }

    // Find medicine
    $stmt = $pdo->prepare("
        SELECT m.id, m.name, i.quantity, i.status
        FROM medicines m
        JOIN inventory i ON i.medicine_id = m.id
        WHERE i.station_id = ? AND m.name LIKE ?
        LIMIT 1
    ");
    $stmt->execute([$station['id'], '%' . $medKeyword . '%']);
    $medicine = $stmt->fetch();

    if (!$medicine) {
        queueReply($from,
            "Wala sang {$medKeyword} sa {$station['barangay_name']} BHS. " .
            "I-text ang REQUEST para mag-request sang wala pa sa imbentaryo."
        );
        return;
    }

    if ($medicine['status'] === 'out_of_stock') {
        queueReply($from,
            "Pasensya, wala na sang {$medicine['name']} sa {$station['barangay_name']} BHS. " .
            "I-text ang REQUEST {$medKeyword} {$barangay} {$residentName} para mag-request."
        );
        return;
    }

    // Create reservation
    $pdo->prepare("
        INSERT INTO reservations
            (resident_name, mobile_number, station_id, medicine_id, source, raw_message)
        VALUES (?, ?, ?, ?, 'sms', ?)
    ")->execute([$residentName, $from, $station['id'], $medicine['id'], $rawText ?: 'SMS reservation']);

    $refId = $pdo->lastInsertId();

    queueReply($from,
        "Natala na ang imo reservation!\n" .
        "Gamot: {$medicine['name']}\n" .
        "Lugar: {$station['barangay_name']} BHS\n" .
        "Ngalan: {$residentName}\n" .
        "Ref#: {$refId}\n" .
        "Hulaton ang SMS kung approved na. Bisita sa BHS para sa pickup.",
        'reservation', $refId
    );
}

// ── REQUEST ───────────────────────────────────────────────────────
// Usage: REQUEST [medicine] [barangay] [full name]
function handleRequest(PDO $pdo, string $from, array $parts, string $rawText = ''): void {
    if (count($parts) < 4) {
        queueReply($from,
            "Format: REQUEST [gamot] [barangay] [imo ngalan]\n" .
            "Halimbawa: REQUEST mefenamic hilamonan Maria Santos"
        );
        return;
    }

    $medKeyword   = $parts[1];
    $barangay     = $parts[2];
    $residentName = implode(' ', array_slice($parts, 3));

    $station = findStation($pdo, $barangay);
    if (!$station) {
        queueReply($from, "Hindi mahanap ang barangay na '{$barangay}'. Available: Hilamonan, Camugao, Inapoy.");
        return;
    }

    $pdo->prepare("
        INSERT INTO medicine_requests
            (resident_name, mobile_number, station_id, medicine_name, source, raw_message)
        VALUES (?, ?, ?, ?, 'sms', ?)
    ")->execute([$residentName, $from, $station['id'], $medKeyword, $rawText ?: "SMS: {$medKeyword}"]);

    $refId = $pdo->lastInsertId();

    queueReply($from,
        "Natala na ang imo request!\n" .
        "Gamot: {$medKeyword}\n" .
        "Lugar: {$station['barangay_name']} BHS\n" .
        "Ngalan: {$residentName}\n" .
        "Ref#: {$refId}\n" .
        "Kontakon ka sang BHW kung available na.",
        'medicine_request', $refId
    );
}

// ── SUBSCRIBE ────────────────────────────────────────────────────
// Usage: SUBSCRIBE [category] [barangay] [full name]
function handleSubscribe(PDO $pdo, string $from, array $parts): void {
    if (!smartInventoryTableExists($pdo, 'resident_subscriptions')) {
        queueReply($from, "Subscription feature indi pa aktibo. Palihog kontaka anay ang BHS.");
        return;
    }

    if (count($parts) < 4) {
        queueReply($from,
            "Format: SUBSCRIBE [category] [barangay] [imo ngalan]\n" .
            "Categories: MATERNAL, MAINTENANCE, CALAMITY, ALL\n" .
            "Halimbawa: SUBSCRIBE MAINTENANCE hilamonan Juan dela Cruz"
        );
        return;
    }

    $category = normalizeSubscriptionCategory($parts[1]);
    if ($category === null) {
        queueReply($from, "Category indi klaro. Pilia: MATERNAL, MAINTENANCE, CALAMITY, ALL.");
        return;
    }

    $station = findStation($pdo, $parts[2]);
    if (!$station) {
        queueReply($from, "Hindi mahanap ang barangay na '{$parts[2]}'. Available: Hilamonan, Camugao, Inapoy.");
        return;
    }

    $residentName = implode(' ', array_slice($parts, 3));

    $pdo->prepare("
        INSERT INTO resident_subscriptions
            (resident_name, mobile_number, station_id, category, is_active)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            resident_name = VALUES(resident_name),
            is_active = 1,
            updated_at = CURRENT_TIMESTAMP
    ")->execute([$residentName, $from, $station['id'], $category]);

    queueReply($from,
        "Subscribed ka na sa {$category} updates para sa {$station['barangay_name']} BHS. " .
        "Magapadala ang BayaniServe kung may bag-o nga stock. Para mag-untat: UNSUBSCRIBE {$parts[1]} {$station['barangay_name']}"
    );
}

// ── UNSUBSCRIBE ──────────────────────────────────────────────────
// Usage: UNSUBSCRIBE [category] [barangay optional]
function handleUnsubscribe(PDO $pdo, string $from, array $parts): void {
    if (!smartInventoryTableExists($pdo, 'resident_subscriptions')) {
        queueReply($from, "Subscription feature indi pa aktibo.");
        return;
    }

    if (count($parts) < 2) {
        queueReply($from, "Format: UNSUBSCRIBE [category] [barangay optional]");
        return;
    }

    $category = normalizeSubscriptionCategory($parts[1]);
    if ($category === null) {
        queueReply($from, "Category indi klaro. Pilia: MATERNAL, MAINTENANCE, CALAMITY, ALL.");
        return;
    }

    $params = [$from, $category];
    $stationSql = '';
    if (count($parts) >= 3) {
        $station = findStation($pdo, implode(' ', array_slice($parts, 2)));
        if ($station) {
            $stationSql = " AND station_id = ?";
            $params[] = $station['id'];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE resident_subscriptions
        SET is_active = 0, updated_at = CURRENT_TIMESTAMP
        WHERE mobile_number = ?
          AND category = ?
          {$stationSql}
    ");
    $stmt->execute($params);

    queueReply($from, "Na-unsubscribe ka na sa {$category} updates.");
}

// ── Helpers ───────────────────────────────────────────────────────

function findStation(PDO $pdo, string $keyword): ?array {
    $stmt = $pdo->prepare("SELECT id, barangay_name FROM health_stations WHERE barangay_name LIKE ? LIMIT 1");
    $stmt->execute(['%' . $keyword . '%']);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Queue an outbound SMS reply.
 * The online server's poll_outbox endpoint will pick this up and send it via Gammu.
 */
function queueReply(string $to, string $message, string $refType = null, int $refId = null): void {
    $pdo = getDB();
    $pdo->prepare("
        INSERT INTO sms_outbox (mobile_number, message, reference_type, reference_id)
        VALUES (?, ?, ?, ?)
    ")->execute([$to, $message, $refType, $refId]);
}

function normalizeSubscriptionCategory(string $raw): ?string {
    $key = strtoupper(trim($raw));
    return match($key) {
        'MATERNAL', 'MOTHER', 'PREGNANCY' => 'Maternal Health Supplies',
        'MAINTENANCE', 'MEDICINE', 'MEDS', 'GAMOT' => 'Maintenance Medicine',
        'CALAMITY', 'RELIEF', 'FOOD', 'PACK' => 'Calamity Relief Pack Updates',
        'ALL' => 'all',
        default => null
    };
}

function helpText(): string {
    return
        "BayaniServe SMS Commands:\n" .
        "CHECK [gamot] [barangay]\n" .
        "STATUS MEDICINE [gamot] [barangay]\n" .
        "RESERVE [gamot] [barangay] [ngalan]\n" .
        "REQUEST [gamot] [barangay] [ngalan]\n" .
        "SUBSCRIBE [category] [barangay] [ngalan]\n\n" .
        "Halimbawa:\n" .
        "CHECK paracetamol hilamonan\n" .
        "STATUS MEDICINE losartan hilamonan\n" .
        "RESERVE amoxicillin camugao Juan Reyes\n" .
        "REQUEST mefenamic inapoy Ana Cruz\n" .
        "SUBSCRIBE MAINTENANCE hilamonan Ana Cruz\n\n" .
        "Category: MATERNAL, MAINTENANCE, CALAMITY, ALL\n" .
        "Barangay: Hilamonan, Camugao, Inapoy";
}
