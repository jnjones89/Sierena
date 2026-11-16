<?php
/**
 * Client CRM check (GoHighLevel). Optional, Team Audit and Solo Audit only.
 *
 * The client connects their own GHL sub-account with a read-only Private Integration token.
 * We read the last 90 days and keep ONLY numbers (counts, medians, percentages). Message text, names,
 * emails and phone numbers are never saved. Nothing in their CRM is changed: every call is a read.
 *
 * The numbers become "From your CRM" notes on the matching Client journey stations and steps,
 * and a block in the report prompt.
 */

const CRM_DAYS = 90;
const CRM_SCOPES = [
    'contacts.readonly', 'conversations.readonly', 'conversations/message.readonly',
    'calendars.readonly', 'calendars/events.readonly', 'opportunities.readonly',
];

/* ------------------------------------------------------------------ */
/* Token storage (encrypted at rest)                                    */
/* ------------------------------------------------------------------ */

function crm_key(): string
{
    $k = (string) cfg_opt('data_key', '');
    if ($k === '') {
        // Fallback: derived from the admin password. Changing it means clients reconnect.
        $k = 'swa-crm|' . (string) cfg('admin_password');
    }
    return hash('sha256', $k, true);
}

function crm_encrypt(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, crm_key()));
}

function crm_decrypt(string $stored): ?string
{
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), crm_key());
    return $plain === false ? null : $plain;
}

/** What we keep about the client's CRM (never the token in plain text). */
function crm_load(int $licenseId): array
{
    $c = profile_data($licenseId)['crm'] ?? [];
    return is_array($c) ? $c : [];
}

function crm_save(int $licenseId, ?array $crm): void
{
    $data = profile_data($licenseId);
    if ($crm === null) {
        unset($data['crm']);
    } else {
        $data['crm'] = $crm;
    }
    write_profile_data($licenseId, $data);
}

/** Safe for display and export: no token. */
function crm_public(array $crm): array
{
    unset($crm['token']);
    return $crm;
}

/* ------------------------------------------------------------------ */
/* Reading their CRM                                                    */
/* ------------------------------------------------------------------ */

function crm_api(string $token, string $method, string $path, array $query = [], ?array $body = null): array
{
    static $calls = 0;
    if (++$calls > 1 && ($pause = (int) cfg_opt('crm_pause_ms', 120)) > 0) {
        usleep($pause * 1000); // stay well under GHL's 100 requests / 10 seconds
    }
    $dest = ['api_token' => $token];
    return ghl_request($dest, $method, $path . ($query ? '?' . http_build_query($query) : ''), $body);
}

function crm_ts($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_numeric($v)) {
        $n = (float) $v;
        return (int) ($n > 1e11 ? $n / 1000 : $n);
    }
    $t = strtotime((string) $v);
    return $t === false ? null : $t;
}

function crm_median(array $xs): ?float
{
    if (!$xs) {
        return null;
    }
    sort($xs);
    $n = count($xs);
    return $n % 2 ? (float) $xs[intdiv($n, 2)] : ($xs[$n / 2 - 1] + $xs[$n / 2]) / 2;
}

function crm_pct(int $part, int $whole): ?int
{
    return $whole > 0 ? (int) round(100 * $part / $whole) : null;
}

/** Channel name from a GHL message type, e.g. TYPE_SMS -> SMS. Null for system activity. */
function crm_channel($type): ?string
{
    $t = strtoupper((string) $type);
    if ($t === '' || str_contains($t, 'ACTIVITY') || str_contains($t, 'NOTE')) {
        return null;
    }
    $t = preg_replace('/^TYPE_/', '', $t);
    return match (true) {
        str_contains($t, 'SMS') => 'SMS',
        str_contains($t, 'EMAIL') => 'Email',
        str_contains($t, 'CALL') || str_contains($t, 'VOICEMAIL') => 'Phone',
        str_contains($t, 'WHATSAPP') => 'WhatsApp',
        str_contains($t, 'FACEBOOK') || str_contains($t, 'INSTAGRAM') => 'Social DMs',
        str_contains($t, 'CHAT') => 'Live chat',
        default => ucfirst(strtolower(str_replace('_', ' ', $t))),
    };
}

/**
 * Run the 90-day check. Returns numbers only. Each part runs on its own, so a missing scope
 * only skips that part.
 */
function crm_run_check(string $locationId, string $token): array
{
    @set_time_limit(180);
    $now   = time();
    $since = $now - CRM_DAYS * 86400;
    $stats = ['days' => CRM_DAYS, 'checked_at' => gmdate('c', $now), 'skipped' => []];

    // ---- Contacts: how many new leads, from where ----
    $contacts = [];
    try {
        $total = null;
        for ($page = 1; $page <= 5; $page++) {
            $res = crm_api($token, 'POST', '/contacts/search', [], [
                'locationId' => $locationId,
                'page'       => $page,
                'pageLimit'  => 100,
                'filters'    => [['field' => 'dateAdded', 'operator' => 'range', 'value' => ['gte' => gmdate('c', $since), 'lte' => gmdate('c', $now)]]],
                'sort'       => [['field' => 'dateAdded', 'direction' => 'desc']],
            ]);
            $total ??= isset($res['total']) ? (int) $res['total'] : null;
            $batch = (array) ($res['contacts'] ?? []);
            foreach ($batch as $c) {
                $added = crm_ts($c['dateAdded'] ?? null);
                if ($added !== null && $added >= $since) {
                    $contacts[] = ['id' => (string) ($c['id'] ?? ''), 'added' => $added, 'source' => trim((string) ($c['source'] ?? ''))];
                }
            }
            if (count($batch) < 100) {
                break;
            }
        }
        $sources = [];
        $noSource = 0;
        foreach ($contacts as $c) {
            if ($c['source'] === '') {
                $noSource++;
            } else {
                $key = mb_substr($c['source'], 0, 40);
                $sources[$key] = ($sources[$key] ?? 0) + 1;
            }
        }
        arsort($sources);
        $stats['contacts'] = [
            'new'         => $total ?? count($contacts),
            'scanned'     => count($contacts),
            'top_sources' => array_slice($sources, 0, 5, true),
            'no_source_pct' => crm_pct($noSource, count($contacts)),
        ];
    } catch (Throwable $e) {
        $stats['skipped']['contacts'] = crm_reason($e);
    }

    // ---- Speed to lead and follow-up, from a sample of new leads ----
    if ($contacts) {
        try {
            $sampleSize = max(5, (int) cfg_opt('crm_sample', 40));
            $sample = array_slice(array_values(array_filter($contacts, fn($c) => $c['added'] <= $now - 86400 && $c['id'] !== '')), 0, $sampleSize);
            $replyMins = []; $never = 0; $within5 = 0; $touches = []; $channels = []; $checked = 0;
            foreach ($sample as $c) {
                $conv = crm_api($token, 'GET', '/conversations/search', ['locationId' => $locationId, 'contactId' => $c['id']]);
                $msgs = [];
                foreach (array_slice((array) ($conv['conversations'] ?? []), 0, 2) as $cv) {
                    if (empty($cv['id'])) {
                        continue;
                    }
                    $m = crm_api($token, 'GET', '/conversations/' . rawurlencode((string) $cv['id']) . '/messages', ['limit' => 100]);
                    $list = $m['messages']['messages'] ?? $m['messages'] ?? [];
                    foreach ((array) $list as $msg) {
                        $ch = crm_channel($msg['messageType'] ?? $msg['type'] ?? '');
                        $at = crm_ts($msg['dateAdded'] ?? null);
                        if ($ch !== null && $at !== null) {
                            // Only the direction, channel and time are used. Message text is ignored.
                            $msgs[] = ['out' => ($msg['direction'] ?? '') === 'outbound', 'ch' => $ch, 'at' => $at];
                        }
                    }
                }
                $checked++;
                $out = array_values(array_filter($msgs, fn($x) => $x['out'] && $x['at'] >= $c['added'] - 60));
                usort($out, fn($a, $b) => $a['at'] <=> $b['at']);
                if (!$out) {
                    $never++;
                    $touches[] = 0;
                    continue;
                }
                $mins = max(0, ($out[0]['at'] - $c['added']) / 60);
                $replyMins[] = $mins;
                if ($mins <= 5) {
                    $within5++;
                }
                $in30 = array_filter($out, fn($x) => $x['at'] <= $c['added'] + 30 * 86400);
                $touches[] = count($in30);
                foreach ($in30 as $x) {
                    $channels[$x['ch']] = ($channels[$x['ch']] ?? 0) + 1;
                }
            }
            arsort($channels);
            $stats['speed'] = [
                'sampled'        => $checked,
                'never_pct'      => crm_pct($never, $checked),
                'never'          => $never,
                'median_minutes' => ($m = crm_median($replyMins)) === null ? null : (int) round($m),
                'within5_pct'    => crm_pct($within5, $checked),
            ];
            $stats['followup'] = [
                'avg_touches'      => $touches ? round(array_sum($touches) / count($touches), 1) : null,
                'one_or_less_pct'  => crm_pct(count(array_filter($touches, fn($t) => $t <= 1)), count($touches)),
                'channels'         => array_keys($channels),
            ];
        } catch (Throwable $e) {
            $stats['skipped']['conversations'] = crm_reason($e);
        }
    }

    // ---- Calendars: show rate, no-shows, rebooking ----
    try {
        $cals = (array) (crm_api($token, 'GET', '/calendars/', ['locationId' => $locationId])['calendars'] ?? []);
        $byStatus = ['showed' => 0, 'noshow' => 0, 'cancelled' => 0, 'unmarked' => 0];
        $events = [];
        foreach (array_slice($cals, 0, 10) as $cal) {
            if (empty($cal['id'])) {
                continue;
            }
            $res = crm_api($token, 'GET', '/calendars/events', [
                'locationId' => $locationId, 'calendarId' => (string) $cal['id'],
                'startTime' => $since * 1000, 'endTime' => ($now + 30 * 86400) * 1000,
            ]);
            foreach ((array) ($res['events'] ?? []) as $ev) {
                $events[] = ['contact' => (string) ($ev['contactId'] ?? ''), 'start' => crm_ts($ev['startTime'] ?? null) ?? 0,
                             'status' => strtolower((string) ($ev['appointmentStatus'] ?? $ev['status'] ?? ''))];
            }
        }
        $past = array_filter($events, fn($e) => $e['start'] > 0 && $e['start'] < $now);
        $noShowContacts = [];
        foreach ($past as $e) {
            $s = $e['status'];
            if ($s === 'showed') { $byStatus['showed']++; }
            elseif (in_array($s, ['noshow', 'no_show', 'no-show'], true)) { $byStatus['noshow']++; $noShowContacts[$e['contact']] = max($noShowContacts[$e['contact']] ?? 0, $e['start']); }
            elseif (in_array($s, ['cancelled', 'canceled', 'invalid'], true)) { $byStatus['cancelled']++; }
            else { $byStatus['unmarked']++; }
        }
        $rebooked = 0;
        foreach ($noShowContacts as $cid => $missedAt) {
            foreach ($events as $e) {
                if ($cid !== '' && $e['contact'] === $cid && $e['start'] > $missedAt) { $rebooked++; break; }
            }
        }
        $marked = $byStatus['showed'] + $byStatus['noshow'];
        $stats['calendar'] = [
            'calendars'    => count($cals),
            'booked'       => count($past),
            'showed'       => $byStatus['showed'],
            'noshow'       => $byStatus['noshow'],
            'cancelled'    => $byStatus['cancelled'],
            'unmarked_pct' => crm_pct($byStatus['unmarked'], count($past)),
            'show_rate'    => $marked >= 5 ? crm_pct($byStatus['showed'], $marked) : null,
            'rebooked_pct' => crm_pct($rebooked, count($noShowContacts)),
        ];
    } catch (Throwable $e) {
        $stats['skipped']['calendars'] = crm_reason($e);
    }

    // ---- Opportunities: close rate and stuck deals ----
    try {
        $won = $lost = $open = $stuck = $created = 0;
        for ($page = 1; $page <= 5; $page++) {
            $res = crm_api($token, 'GET', '/opportunities/search', ['location_id' => $locationId, 'limit' => 100, 'page' => $page]);
            $batch = (array) ($res['opportunities'] ?? []);
            foreach ($batch as $o) {
                $st = strtolower((string) ($o['status'] ?? ''));
                $createdAt = crm_ts($o['createdAt'] ?? $o['dateAdded'] ?? null) ?? 0;
                if ($st === 'open') {
                    $open++;
                    $moved = crm_ts($o['lastStageChangeAt'] ?? $o['lastStatusChangeAt'] ?? $o['updatedAt'] ?? null) ?? $createdAt;
                    if ($moved > 0 && $moved < $now - 30 * 86400) {
                        $stuck++;
                    }
                }
                if ($createdAt >= $since) {
                    $created++;
                    if ($st === 'won') { $won++; }
                    if (in_array($st, ['lost', 'abandoned'], true)) { $lost++; }
                }
            }
            if (count($batch) < 100 || empty($res['meta']['nextPage'])) {
                break;
            }
        }
        $stats['deals'] = [
            'created'    => $created,
            'won'        => $won,
            'lost'       => $lost,
            'close_rate' => ($won + $lost) >= 5 ? crm_pct($won, $won + $lost) : null,
            'open'       => $open,
            'stuck'      => $stuck,
        ];
    } catch (Throwable $e) {
        $stats['skipped']['opportunities'] = crm_reason($e);
    }

    return $stats;
}

function crm_reason(Throwable $e): string
{
    $m = $e->getMessage();
    if (preg_match('/HTTP (401|403)/', $m)) {
        return 'No permission. Check the token has the read-only scopes listed.';
    }
    return mb_substr($m, 0, 160);
}

/** Connect, run the first check and save. Returns an error message, or '' on success. */
function crm_connect(int $licenseId, string $locationId, string $token): string
{
    $locationId = trim($locationId);
    $token = trim($token);
    if (!preg_match('/^[A-Za-z0-9]{10,40}$/', $locationId)) {
        return 'That Location ID does not look right. It is 20 letters and numbers, found in Settings → Business Profile.';
    }
    if (strlen($token) < 20 || strlen($token) > 400 || preg_match('/\s/', $token)) {
        return 'That token does not look right. Copy it again from Settings → Private Integrations.';
    }
    $stats = crm_run_check($locationId, $token);
    if (!isset($stats['contacts']) && !isset($stats['calendar']) && !isset($stats['deals'])) {
        return 'We could not read your CRM: ' . reset($stats['skipped']);
    }
    crm_save($licenseId, [
        'location_id'  => $locationId,
        'token'        => crm_encrypt($token),
        'connected_at' => now_iso(),
        'stats'        => $stats,
    ]);
    return '';
}

/** Run the check again with the saved token. */
function crm_refresh(int $licenseId): string
{
    $crm = crm_load($licenseId);
    $token = isset($crm['token']) ? crm_decrypt((string) $crm['token']) : null;
    if (!$token) {
        return 'Please connect your CRM again.';
    }
    $crm['stats'] = crm_run_check((string) $crm['location_id'], $token);
    crm_save($licenseId, $crm);
    return '';
}

/* ------------------------------------------------------------------ */
/* Turning the numbers into journey notes                              */
/* ------------------------------------------------------------------ */

function crm_minutes_text(int $m): string
{
    if ($m < 60) {
        return $m . ' min';
    }
    if ($m < 1440) {
        return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
    }
    $d = round($m / 1440, 1);
    return $d . ' day' . ($d == 1 ? '' : 's');
}

/**
 * Notes for the Client journey. ['stations' => [station => [[level, text]]], 'links' => [link => [level, text]]]
 * level: ok, weak, broken.
 */
function crm_findings(array $stats): array
{
    $st = []; $ln = [];
    $add = function (string $station, string $level, string $text, array $links = []) use (&$st, &$ln) {
        $st[$station][] = ['level' => $level, 'text' => $text];
        foreach ($links as $l) {
            $ln[$l] = ['level' => $level, 'text' => $text];
        }
    };
    if ($c = $stats['contacts'] ?? null) {
        $src = [];
        foreach ($c['top_sources'] as $name => $n) {
            $src[] = "$name ($n)";
        }
        $add('lead_capture', 'ok', $c['new'] . ' new contacts in the last ' . CRM_DAYS . ' days.' . ($src ? ' Top sources: ' . implode(', ', array_slice($src, 0, 3)) . '.' : ''));
        if (($c['no_source_pct'] ?? 0) >= 30) {
            $add('lead_capture', 'weak', $c['no_source_pct'] . '% of new contacts have no source, so you can\'t tell which marketing works.');
        }
    }
    if (($s = $stats['speed'] ?? null) && $s['sampled'] > 0) {
        $level = ($s['never_pct'] ?? 0) >= 20 ? 'broken' : (($s['within5_pct'] ?? 0) < 50 ? 'weak' : 'ok');
        $text = 'We checked ' . $s['sampled'] . ' new leads: ' . $s['never'] . ' (' . $s['never_pct'] . '%) never got a reply.';
        if ($s['median_minutes'] !== null) {
            $text .= ' Typical first reply: ' . crm_minutes_text($s['median_minutes']) . '. Answered within 5 minutes: ' . $s['within5_pct'] . '%.';
        }
        $add('speed', $level, $text, ['lead_reply']);
    }
    if (($f = $stats['followup'] ?? null) && $f['avg_touches'] !== null) {
        $level = ($f['one_or_less_pct'] ?? 0) >= 50 ? 'broken' : ($f['avg_touches'] < 3 ? 'weak' : 'ok');
        $add('followup', $level, 'New leads got ' . $f['avg_touches'] . ' follow-ups on average in their first 30 days. '
            . $f['one_or_less_pct'] . '% got one or none.' . ($f['channels'] ? ' Channels used: ' . implode(', ', $f['channels']) . '.' : ''));
    }
    if ($cal = $stats['calendar'] ?? null) {
        if ($cal['booked'] > 0 && ($cal['unmarked_pct'] ?? 0) >= 50) {
            $add('conversion', 'weak', $cal['unmarked_pct'] . '% of past appointments were never marked showed or no-show, so your show rate can\'t be measured.', []);
        }
        if ($cal['show_rate'] !== null) {
            $level = $cal['show_rate'] < 50 ? 'broken' : ($cal['show_rate'] < 75 ? 'weak' : 'ok');
            $add('conversion', $level, $cal['booked'] . ' appointments in the last ' . CRM_DAYS . ' days. Show rate: ' . $cal['show_rate'] . '% (' . $cal['noshow'] . ' no-shows).', ['reminders']);
        }
        if ($cal['noshow'] > 0 && $cal['rebooked_pct'] !== null) {
            $level = $cal['rebooked_pct'] < 50 ? 'broken' : ($cal['rebooked_pct'] < 80 ? 'weak' : 'ok');
            $add('conversion', $level, 'Only ' . $cal['rebooked_pct'] . '% of no-shows booked again.', ['no_show']);
        }
    }
    if ($d = $stats['deals'] ?? null) {
        if ($d['close_rate'] !== null) {
            $level = $d['close_rate'] < 20 ? 'weak' : 'ok';
            $add('conversion', $level, 'Close rate in your pipeline: ' . $d['close_rate'] . '% (' . $d['won'] . ' won, ' . $d['lost'] . ' lost).');
        }
        if ($d['open'] > 0 && $d['stuck'] > 0) {
            $share = crm_pct($d['stuck'], $d['open']);
            $level = $share >= 25 ? 'broken' : 'weak';
            $add('followup', $level, $d['stuck'] . ' of ' . $d['open'] . ' open deals have not moved in 30+ days.', ['proposal_chase']);
        }
    }
    return ['stations' => $st, 'links' => $ln];
}

/** Text for the AI report prompt. */
function crm_prompt_text(array $crm): string
{
    if (empty($crm['stats'])) {
        return '- (Not connected. Use the owner\'s answers and the audit.)';
    }
    $f = crm_findings($crm['stats']);
    $lines = [];
    foreach ($f['stations'] as $sid => $notes) {
        $title = stations()[$sid]['title'] ?? $sid;
        foreach ($notes as $n) {
            $lines[] = "- $title: " . $n['text'];
        }
    }
    foreach ($crm['stats']['skipped'] ?? [] as $part => $why) {
        $lines[] = "- Could not read $part: $why";
    }
    return $lines ? implode("\n", $lines) : '- Connected, but there was not enough data in the last ' . CRM_DAYS . ' days.';
}

/* ------------------------------------------------------------------ */
/* One call for the whole Client journey                               */
/* ------------------------------------------------------------------ */

/** Chain + stations, with audit evidence, our tests and CRM numbers. */
function journey_full(int $licenseId, string $industry, array $aggregate): array
{
    $crm   = crm_load($licenseId);
    $found = !empty($crm['stats']) ? crm_findings($crm['stats']) : ['stations' => [], 'links' => []];
    $tests = load_tests($licenseId);
    $chain = chain_status($industry ?: 'other', load_chain($licenseId), chain_evidence($aggregate), $tests, $found['links']);
    $stations = stations_status(load_stations($licenseId), station_evidence($aggregate), $tests, $chain['rows'], $found['stations']);
    return ['chain' => $chain, 'stations' => $stations, 'crm' => $crm];
}
