<?php
/**
 * Minimal WebDAV/CalDAV client for Nextcloud Tasks with a folder shim so the tasklist driver
 * can operate without libkolab.
 */
if (!class_exists('simple_dav_client')) {
class simple_dav_client {
    private function resolve_url($path) {
        if (preg_match('~^https?://~i', $path)) return $path;
        $base = $this->base;
        $u = parse_url($base);
        $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? (':' . $u['port']) : '');
        if (strpos($path, '/') === 0) {
            return rtrim($origin, '/') . $path;
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
    private $debug;
    private function simple_debug($msg) {
        if (class_exists('rcube')) {
            $rc = rcube::get_instance();
            if ($this->debug === null) { $this->debug = (bool)$rc->config->get('tasklist_debug', false); }
            if ($this->debug) rcube::write_log('tasklist', '[DAV] ' . $msg);
        }
    }
    private $base, $user, $pass, $curlopts;
    public function __construct($base_url, $username, $password, $curlopts = []) {
        $this->base = rtrim($base_url, '/');
        $this->user = $username;
        $this->pass = $password;
        $this->curlopts = $curlopts;
    }
    private function request($method, $url, $headers = [], $body = null, $depth = null) {
        $this->simple_debug($method.' '.$url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $this->user . ":" . $this->pass);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $req_headers = [];
        foreach ($headers as $k => $v) { $req_headers[] = $k . ': ' . $v; }
        if ($depth !== null) { $req_headers[] = 'Depth: ' . $depth; }
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        if (!empty($req_headers)) { curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers); }
        foreach ($this->curlopts as $k=>$v) { curl_setopt($ch, $k, $v); }
        $resp = curl_exec($ch);
        if ($resp === false) { $err = curl_error($ch); curl_close($ch); throw new Exception("cURL error: " . $err); }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($resp, 0, $header_size);
        $body = substr($resp, $header_size);
        curl_close($ch);
        $this->simple_debug('<= status ' . $status);
        return [$status, $headers, $body];
    }
    public function propfind($path, $depth = 1, $xml_body = null) {
        $url = $this->resolve_url($path);

        $body = $xml_body ?: '<?xml version="1.0"?>
            <d:propfind xmlns:d="DAV:">
              <d:prop><d:displayname/><d:resourcetype/><cal:supported-calendar-component-set xmlns:cal="urn:ietf:params:xml:ns:caldav"/></d:prop>
            </d:propfind>';
        return $this->request('PROPFIND', $url, ['Content-Type'=>'application/xml'], $body, $depth);
    }
    public function get($href) { $url = $this->resolve_url($href); return $this->request('GET', $url, [], null, null); }
    public function put($href, $data, $content_type = 'text/calendar') {
        $url = $this->resolve_url($href);
        return $this->request('PUT', $url, ['Content-Type'=>$content_type], $data, null);
    }
    public function delete($href) { $url = $this->resolve_url($href); return $this->request('DELETE', $url, [], null, null); }
}
}

if (!class_exists('simple_dav_folder')) {
class simple_dav_folder {
    public $id;
    public $name;
    public $default = false;
    public $subtype = 'task';
    public $attributes = ['alarms' => true];
    public $editable = true;
    private $href;
    private $dav;
    private $owner;

    public function __construct($dav, $href, $name, $owner) {
        $this->dav = $dav;
        $this->href = rtrim($href, '/') . '/';
        $this->id = $this->href;
        $this->name = $name ?: trim($this->href, '/');
        $this->owner = $owner;
    }
    // --- API expected by driver ---
    public function get_namespace() { return 'personal'; }
    public function get_myrights() { return 'lrswikxtea'; }
    public function get_name() { return $this->name; }
    public function get_foldername() { return $this->name; }
    public function get_color($default) { return $default; }
    public function get_owner() { return $this->owner; }
    public function get_parent() { return null; }
    public function is_active() { return true; }
    public function is_subscribed() { return true; }
    public function get_mailbox_id() { return md5($this->href); }
    public function get_uid() { return $this->get_mailbox_id(); }
    public function select() { return true; }
    public function delete($uid, $force = false) {
        $href = $this->href . rawurlencode($uid) . '.ics';
        list($code, $h, $b) = $this->dav->delete($href);
        return $code >= 200 && $code < 300;
    }
    public function move($uid, $destFolder) {
        // Fallback: GET then PUT then DELETE
        $href = $this->href . rawurlencode($uid) . '.ics';
        list($code, $h, $body) = $this->dav->get($href);
        if ($code < 200 || $code >= 300) return false;
        $dst = rtrim($destFolder->id, '/') . '/' . rawurlencode($uid) . '.ics';
        list($pc, $ph, $pb) = $this->dav->put($dst, $body);
        if ($pc < 200 || $pc >= 300) return false;
        $this->dav->delete($href);
        return true;
    }
    public function get_object($uid) {
        $href = $this->href . rawurlencode($uid) . '.ics';
        list($code, $h, $body) = $this->dav->get($href);
        if ($code < 200 || $code >= 300) return false;
        // Minimal parse: just signal existence; full parse not required for edit merge fallback
        return ['_raw_ics' => $body];
    }
    public function save($object, $type = 'task', $uid = null) {
        $uid = $uid ?: (isset($object['uid']) ? $object['uid'] : bin2hex(random_bytes(8)) . '@roundcube');
        $summary = isset($object['title']) ? $object['title'] : (isset($object['summary']) ? $object['summary'] : '');
        $desc = isset($object['description']) ? $object['description'] : '';
        $priority = isset($object['priority']) ? intval($object['priority']) : 0;
        $status = isset($object['status']) ? $object['status'] : (isset($object['complete']) && $object['complete'] >= 100 ? 'COMPLETED' : 'NEEDS-ACTION');
        $pct = isset($object['complete']) ? intval($object['complete']) : (strcasecmp($status,'COMPLETED')===0 ? 100 : 0);
        $now = gmdate('Ymd\THis\Z');

        $fmtDT = function($v) {
            if (is_object($v) && method_exists($v, 'format')) {
                // support libcalendaring_datetime and DateTime
                $s = $v->format('Ymd\THis');
                // try to detect TZ-less date-only flag
                if (property_exists($v, '_dateonly') && $v->_dateonly) return "VALUE=DATE:" . $v->format('Ymd');
                return $s . 'Z';
            }
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                // 'YYYY-mm-dd' optionally with time
                $ts = strtotime($v);
                return gmdate('Ymd\THis\Z', $ts);
            }
            return null;
        };
        $due = isset($object['due']) ? $fmtDT($object['due']) : null;
        $start = isset($object['start']) ? $fmtDT($object['start']) : null;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Roundcube Tasklist Nextcloud//EN',
            'BEGIN:VTODO',
            'UID:' . $uid,
            'DTSTAMP:' . $now,
        ];
        if ($summary !== '') $lines[] = 'SUMMARY:' . self::ical_escape($summary);
        if ($desc !== '') $lines[] = 'DESCRIPTION:' . self::ical_escape($desc);
        if ($start) {
            if (strpos($start, 'VALUE=DATE:') === 0) {
                $lines[] = 'DTSTART;' . $start;
            } else {
                $lines[] = 'DTSTART:' . $start;
            }
        }
        if ($due) {
            if (strpos($due, 'VALUE=DATE:') === 0) {
                $lines[] = 'DUE;' . $due;
            } else {
                $lines[] = 'DUE:' . $due;
            }
        }
        if ($priority) $lines[] = 'PRIORITY:' . $priority;
        if ($pct) $lines[] = 'PERCENT-COMPLETE:' . $pct;
        if ($status) $lines[] = 'STATUS:' . strtoupper($status);
        $lines[] = 'END:VTODO';
        $lines[] = 'END:VCALENDAR';
        $ics = implode("\r\n", $lines) . "\r\n";

        $href = $this->href . rawurlencode($uid) . '.ics';
        list($code, $h, $b) = $this->dav->put($href, $ics);
        return $code >= 200 && $code < 300;
    }

    private static function ical_escape($s) {
        $s = str_replace(['\\', ';', ',', "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', ''], $s);
        return $s;
    }
}
}

if (!class_exists('simple_storage_dav')) {
class simple_storage_dav {
    private $dav;
    private $principal;
    private $cal_root;
    private $owner;
    private $override;
    private $debug;
    private $override_set;

    public function __construct($base_url, $username, $password) {
        $this->owner = $username;
        $rc = class_exists('rcube') ? rcube::get_instance() : null;
        $this->debug = $rc ? (bool)$rc->config->get('tasklist_debug', false) : false;
        $this->override = $rc ? $rc->config->get('nextcloud_tasks_collection', null) : null;
        $this->override_set = !empty($this->override);

        $this->dav = new simple_dav_client($base_url, $username, $password, [
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        // Always run discovery so %p can resolve even if override is set
        $this->discover_calendar_home();

        if ($this->override_set) {
            $ovr = $this->expand_override($this->override);
            $ovr = rtrim($ovr, '/') . '/';
            $this->cal_root = $ovr;
            $this->log('Using override collection: ' . $ovr);
            // Log status, do not block
            list($c0, $h0, $b0) = $this->dav->propfind($ovr, 0);
            $this->log('override PROPFIND(0) status=' . $c0);
        }
    }

    private function log($msg) {
        if ($this->debug && class_exists('rcube')) {
            rcube::write_log('tasklist', '[DISCOVER] ' . $msg);
        }
    }

    private function expand_override($template) {
        $username = $this->owner;
        $local = preg_replace('/@.*$/', '', $username);
        $principal_user = $local;
        if (!empty($this->cal_root) && preg_match('~/calendars/([^/]+)/~', $this->cal_root, $m)) {
            $principal_user = $m[1];
        } elseif (!empty($this->principal)) {
            $principal_user = trim(basename(rtrim($this->principal, '/')));
        }
        $map = [
            '%u' => rawurlencode($username),
            '%l' => rawurlencode($local),
            '%p' => rawurlencode($principal_user),
        ];
        $expanded = strtr($template, $map);
        return $this->normalize_href($expanded);
    }

    private function discover_calendar_home() {
        // Step 1: current-user-principal (namespace-agnostic)
        $xml = '<?xml version="1.0"?>
            <d:propfind xmlns:d="DAV:">
              <d:prop><d:current-user-principal/></d:prop>
            </d:propfind>';
        list($code, $hdr, $body) = $this->dav->propfind('', 0, $xml);
        $this->log('current-user-principal status=' . $code);
        $principal_href = null;
        if ($code >= 200 && $code < 300) {
            if (preg_match('~<[^:>]*:current-user-principal[^>]*>.*?<[^:>]*:href>(.*?)</[^:>]*:href>~is', $body, $m)) {
                $principal_href = trim(html_entity_decode($m[1]));
            }
        }
        if ($principal_href) {
            $this->principal = $principal_href;
            $this->log('principal href=' . $principal_href);
        }

        // Step 2: calendar-home-set on principal (namespace-agnostic)
        $xml2 = '<?xml version="1.0"?>
            <d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">
              <d:prop><c:calendar-home-set/></d:prop>
            </d:propfind>';
        $home_href = null;
        if ($principal_href) {
            list($code2, $hdr2, $body2) = $this->dav->propfind($principal_href, 0, $xml2);
            $this->log('calendar-home-set status=' . $code2);
            if ($code2 >= 200 && $code2 < 300) {
                if (preg_match('~<[^:>]*:calendar-home-set[^>]*>.*?<[^:>]*:href>(.*?)</[^:>]*:href>~is', $body2, $m2)) {
                    $home_href = trim(html_entity_decode($m2[1]));
                }
            }
        }
        if ($home_href) {
            $this->cal_root = $home_href;
            $this->log('calendar-home-set=' . $home_href);
            return;
        }

        // Fallback probes: try both email-username and localpart variants
        $candidates = [];
        $email_user = $this->owner;
        $local = preg_replace('/@.*$/', '', $email_user);
        $candidates[] = 'calendars/' . rawurlencode($email_user) . '/';
        if ($local !== $email_user) $candidates[] = 'calendars/' . rawurlencode($local) . '/';

        foreach ($candidates as $cand) {
            list($sc, $sh, $sb) = $this->dav->propfind($cand, 0);
            $this->log('probe ' . $cand . ' status=' . $sc);
            if ($sc >= 200 && $sc < 300) {
                $this->cal_root = $cand;
                $this->log('using fallback cal_root=' . $cand);
                return;
            }
        }

        // Last resort: default to localpart
        $this->cal_root = 'calendars/' . rawurlencode($local) . '/';
        $this->log('no calendar-home-set; fallback cal_root=' . $this->cal_root);
    }

    public function get_folders($type = 'task') {
        if ($this->override_set) {
            $href = $this->cal_root;
            $name = basename(rtrim($href, '/'));
            return [ new simple_dav_folder($this->dav, $href, $name, $this->owner) ];
        }

        $folders = [];
        $root = $this->cal_root ?: ('calendars/' . rawurlencode(preg_replace('/@.*$/','',$this->owner)) . '/');
        $this->log('listing ' . $root);
        list($code, $hdr, $body) = $this->dav->propfind($root, 1);
        if ($code >= 200 && $code < 300) {
            preg_match_all('~<d:response[^>]*>(.*?)</d:response>~is', $body, $m);
            foreach ($m[1] as $resp) {
                if (!preg_match('~<d:href>(.*?)</d:href>~is', $resp, $hm)) continue;
                $href = html_entity_decode(trim($hm[1]));
                if (substr($href, -1) != '/') continue;
                if (preg_match('~/calendars/[^/]+/?$~', $href)) continue;
                if (stripos($href, '/trashbin/') !== false) { $this->log('skip trashbin ' . $href); continue; }
                if (preg_match('~/(inbox|outbox|contact_birthdays|inbox|outbox)/$~i', $href)) { $this->log('skip system ' . $href); continue; }
                $name = basename(rtrim($href, '/'));
                if (preg_match('~<d:displayname>(.*?)</d:displayname>~is', $resp, $nm)) {
                    $name = trim(strip_tags($nm[1]));
                }
                
                // Filter to VTODO-capable collections when type=='task'
                if ($type === 'task') {
                    if (!preg_match('~supported-?calendar-?component-?set~i', $resp) ||
                        !preg_match('~<[^>]*comp[^>]+name=["\']VTODO["\']~i', $resp)) {
                        $this->log('skip non-VTODO ' . $href);
                        continue;
                    }
                }
$this->log('found folder ' . $name . ' -> ' . $href);
                $folders[] = new simple_dav_folder($this->dav, $href, $name, $this->owner);
            }
        } else {
            $this->log('PROPFIND ' . $root . ' failed with ' . $code);
        }
        return $folders;
    }

    public function search_folders($type, $query, $scopes = []) {
        $out = [];
        foreach ($this->get_folders($type) as $f) {
            if (stripos($f->name, $query) !== false) $out[] = $f;
        }
        return $out;
    }

    public function get_share_invitations($type, $query) { return []; }
    public function accept_share_invitation($type, $href) { return false; }

    public function folder_update($props) { return false; }
    public function folder_delete($id, $type = 'task') { return false; }

    private function normalize_href($href) {
        if (preg_match('~^https?://~i', $href)) return $href;
        $base = $this->dav_base();
        $u = parse_url($base);
        $origin = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? (':' . $u['port']) : '');
        if (strpos($href, '/') === 0) {
            return rtrim($origin, '/') . $href;
        }
        return rtrim($base, '/') . '/' . ltrim($href, '/');
    }

    private function dav_base() {
        $r = new ReflectionClass($this->dav);
        $p = $r->getProperty('base');
        $p->setAccessible(true);
        return rtrim($p->getValue($this->dav), '/');
    }
}
}
// Provide tiny shims for kolab_utils and kolab_format when libkolab is not present.
if (!class_exists('kolab_utils')) {
class kolab_utils {
    public static function folder_form($form, $folder = null, $type = 'tasklist', $hidden_fields = []) { return $form; }
}
}
if (!class_exists('kolab_format')) {
class kolab_format {
    public static function merge_attachments(&$object, $old) { /* no-op */ }
}
}
