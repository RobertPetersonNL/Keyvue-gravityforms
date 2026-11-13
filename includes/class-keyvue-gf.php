<?php
if (!defined('ABSPATH')) { exit; }

final class KeyVue_GF {
    const OPTION = 'keyvue_gf_settings';      // Per formulier: enabled + handmatige veldkeuzes.
    const GLOBAL_OPTION = 'keyvue_gf_global'; // Per website: webhook, meldingen, standaardgedrag.
    const CRON = 'keyvue_gf_process';
    const VERSION = '2.0.0';
    const MAX_ATTEMPTS = 6;
    const DEFAULT_NOTIFY = 'robert@dijs.media';

    private static $direct = false;
    private static $auto_cache = array();

    /* ---------------------------------------------------------------------
     * Basis
     * ------------------------------------------------------------------- */

    public static function fields() {
        return array(
            'email'        => 'E-mailadres',
            'first_name'   => 'Voornaam',
            'last_name'    => 'Achternaam',
            'full_name'    => 'Volledige naam',
            'phone'        => 'Telefoonnummer',
            'zip'          => 'Postcode',
            'pohuisnummer' => 'Huisnummer',
            'postraatnaam' => 'Straatnaam',
            'poplaats'     => 'Plaats',
            'country'      => 'Land',
            'gender'       => 'Geslacht',
            'birthday'     => 'Geboortedatum',
        );
    }

    public static function table() { global $wpdb; return $wpdb->prefix . 'keyvue_gf_jobs'; }

    public static function boot() {
        add_filter('cron_schedules', array(__CLASS__, 'schedules'));
        add_action('init', array(__CLASS__, 'init'));
        add_action('gform_after_submission', array(__CLASS__, 'after_submission'), 20, 2);
        add_action(self::CRON, array(__CLASS__, 'process'));
        add_filter('gform_settings_menu', array(__CLASS__, 'tab'));
        add_action('gform_settings_keyvue', array(__CLASS__, 'page'));
        add_filter('gform_entry_detail_meta_boxes', array(__CLASS__, 'meta_box'), 10, 3);
        add_action('gform_delete_entry', array(__CLASS__, 'delete_entry'));
        add_action('admin_notices', array(__CLASS__, 'notices'));
        foreach (array('save_global', 'save_form', 'toggle', 'retry', 'send', 'dismiss') as $action) {
            add_action('admin_post_keyvue_gf_' . $action, array(__CLASS__, 'do_' . $action));
        }
    }

    public static function schedules($s) {
        $s['keyvue_minute'] = array('interval' => 60, 'display' => 'KeyVue: elke minuut');
        return $s;
    }

    public static function init() {
        if (get_option('keyvue_gf_version') !== self::VERSION) { self::install(); }
        if (!wp_next_scheduled(self::CRON)) { wp_schedule_event(time() + 30, 'keyvue_minute', self::CRON); }
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table(); $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) unsigned NOT NULL,
            form_id bigint(20) unsigned NOT NULL,
            config longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            created_at datetime NOT NULL,
            message varchar(255) NOT NULL DEFAULT '',
            lock_token varchar(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY entry_id (entry_id),
            KEY due (status,available_at)
        ) $charset;");
        self::migrate();
        update_option('keyvue_gf_version', self::VERSION, false);
    }

    // Versie 1.x bewaarde de webhook en een volledige koppeling per formulier.
    private static function migrate() {
        $all = get_option(self::OPTION, array());
        if (!is_array($all) || !$all) { return; }
        $global = (array) get_option(self::GLOBAL_OPTION, array());
        $changed = false;
        foreach ($all as $id => $c) {
            if (!is_array($c) || !array_key_exists('url', $c)) { continue; }
            if (empty($global['url']) && self::valid_url($c['url'])) { $global['url'] = $c['url']; }
            if (!empty($c['retry_ambiguous'])) { $global['retry_ambiguous'] = true; }
            $new = array('enabled' => !empty($c['enabled']));
            foreach ((array) ($c['map'] ?? array()) as $key => $field_id) {
                if ((string) $field_id !== '') { $new['map'][$key] = (string) $field_id; }
            }
            $all[$id] = $new; $changed = true;
        }
        if ($changed) {
            update_option(self::OPTION, $all, false);
            update_option(self::GLOBAL_OPTION, $global, false);
        }
    }

    public static function deactivate() { wp_clear_scheduled_hook(self::CRON); }

    public static function delete_entry($id) {
        global $wpdb;
        $wpdb->delete(self::table(), array('entry_id' => absint($id)), array('%d'));
    }

    /* ---------------------------------------------------------------------
     * Instellingen
     * ------------------------------------------------------------------- */

    public static function globals() {
        return wp_parse_args((array) get_option(self::GLOBAL_OPTION, array()), array(
            'url' => '', 'default_enabled' => true, 'notify_email' => self::DEFAULT_NOTIFY, 'retry_ambiguous' => false,
        ));
    }

    public static function settings() { return (array) get_option(self::OPTION, array()); }

    public static function form_config($id) {
        $all = self::settings();
        return isset($all[$id]) && is_array($all[$id]) ? $all[$id] : array();
    }

    // Formulieren zonder eigen keuze volgen de standaard (aan).
    public static function form_enabled($id) {
        $c = self::form_config($id);
        if (isset($c['enabled'])) { return (bool) $c['enabled']; }
        $g = self::globals();
        return (bool) $g['default_enabled'];
    }

    public static function valid_url($url) {
        $p = wp_parse_url((string) $url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || ($p['host'] ?? '') !== 'api.keyvue.nl' || ($p['path'] ?? '') !== '/api/v1/service/active_campaign/referral' || isset($p['user']) || isset($p['pass']) || isset($p['port']) || isset($p['fragment'])) { return false; }
        parse_str($p['query'] ?? '', $q);
        if (count($q) !== 2) { return false; }
        foreach (array('company', 'webhook') as $key) {
            if (!isset($q[$key]) || !is_string($q[$key]) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $q[$key])) { return false; }
        }
        return true;
    }

    // Geplakte links bevatten vaak HTML-codes zoals &#xA; (regeleinde), &amp; of spaties.
    public static function clean_url($url) {
        $url = str_replace('&amp;', '&', (string) $url);
        $url = preg_replace('/&#x?[0-9a-f]+;/i', '', $url);
        $url = preg_replace('/[\s\x00-\x1F]+/', '', $url);
        return trim($url, "\"'<>");
    }

    /* ---------------------------------------------------------------------
     * Velden herkennen en koppelen
     * ------------------------------------------------------------------- */

    // Alle kiesbare formulieronderdelen, met de gegevens voor automatisch koppelen.
    public static function candidates($form) {
        $sub_labels = array(
            'name'    => array('2' => 'Aanhef', '3' => 'Voornaam', '4' => 'Tussenvoegsel', '6' => 'Achternaam', '8' => 'Achtervoegsel'),
            'address' => array('1' => 'Straat', '2' => 'Adresregel 2', '3' => 'Plaats', '4' => 'Provincie', '5' => 'Postcode', '6' => 'Land'),
        );
        $out = array();
        foreach ((array) ($form['fields'] ?? array()) as $f) {
            if (!is_object($f) || !in_array($f->type, array('text', 'name', 'email', 'phone', 'address', 'number', 'select', 'radio', 'date', 'hidden', 'textarea'), true)) { continue; }
            // Alleen naam en adres bewaren hun waarde per onderdeel; e-mail, datum en telefoon onder het veldnummer.
            if (!empty($f->inputs) && is_array($f->inputs) && isset($sub_labels[$f->type])) {
                foreach ($f->inputs as $input) {
                    if (!empty($input['isHidden'])) { continue; }
                    $id = (string) $input['id'];
                    $sub = (string) substr((string) strrchr($id, '.'), 1);
                    $part = !empty($input['customLabel']) ? $input['customLabel'] : ($sub_labels[$f->type][$sub] ?? ($input['label'] ?? $sub));
                    $out[$id] = array('label' => $f->label . ' — ' . $part, 'type' => $f->type, 'sub' => $sub, 'texts' => array(), 'param' => (string) ($input['name'] ?? ''));
                }
            } else {
                $out[(string) $f->id] = array('label' => (string) $f->label !== '' ? $f->label : 'Veld ' . $f->id, 'type' => $f->type, 'sub' => '', 'texts' => array($f->label, $f->adminLabel ?? ''), 'param' => (string) ($f->inputName ?? ''));
            }
        }
        return $out;
    }

    public static function choices($form) {
        $out = array();
        foreach (self::candidates($form) as $id => $c) { $out[$id] = $c['label']; }
        return $out;
    }

    private static function norm($t) {
        return preg_replace('/[^a-z0-9]/', '', remove_accents(strtolower((string) $t)));
    }

    private static function score($key, $c) {
        static $structure = array(
            'email' => array('email', ''), 'phone' => array('phone', ''),
            'first_name' => array('name', '3'), 'last_name' => array('name', '6'), 'full_name' => array('name', ''),
            'postraatnaam' => array('address', '1'), 'poplaats' => array('address', '3'), 'zip' => array('address', '5'), 'country' => array('address', '6'),
        );
        static $words = array(
            'email'        => array('exact' => array('mail'), 'contains' => array('email')),
            'first_name'   => array('exact' => array(), 'contains' => array('voornaam', 'firstname', 'roepnaam')),
            'last_name'    => array('exact' => array(), 'contains' => array('achternaam', 'familienaam', 'lastname', 'surname')),
            'full_name'    => array('exact' => array('naam', 'name', 'uwnaam', 'jenaam', 'jouwnaam', 'volledigenaam', 'fullname', 'voorenachternaam', 'voornaamenachternaam', 'naamcontactpersoon', 'contactpersoon'), 'contains' => array()),
            'phone'        => array('exact' => array('tel', 'gsm'), 'contains' => array('telefoon', 'mobiel', 'phone')),
            'zip'          => array('exact' => array('zip'), 'contains' => array('postcode', 'zipcode')),
            'pohuisnummer' => array('exact' => array(), 'contains' => array('huisnummer', 'huisnr', 'housenumber')),
            'postraatnaam' => array('exact' => array('street', 'adres', 'address'), 'contains' => array('straat')),
            'poplaats'     => array('exact' => array('plaats', 'plaatsnaam', 'stad', 'city'), 'contains' => array('woonplaats')),
            'country'      => array('exact' => array('land', 'country'), 'contains' => array()),
            'gender'       => array('exact' => array('aanhef'), 'contains' => array('geslacht', 'gender')),
            'birthday'     => array('exact' => array(), 'contains' => array('geboortedat', 'geboorte', 'birthda')),
        );
        // Een e-mail-, telefoon- of datumveld kan alleen zijn eigen soort gegevens leveren.
        $only = array('email' => 'email', 'phone' => 'phone', 'date' => 'birthday');
        if (isset($only[$c['type']]) && $only[$c['type']] !== $key) { return 0; }
        if ($c['type'] === 'textarea') { return 0; }

        $param = self::norm($c['param']);
        if ($param !== '' && ($param === self::norm($key) || in_array($param, $words[$key]['exact'], true) || in_array($param, $words[$key]['contains'], true))) { return 5; }
        if (isset($structure[$key]) && $structure[$key][0] === $c['type'] && $structure[$key][1] === $c['sub']) { return 4; }
        if ($c['sub'] !== '') { return 0; } // Onderdelen van naam/adres alleen via hun vaste positie.

        $best = 0;
        foreach ($c['texts'] as $text) {
            $t = self::norm($text);
            if ($t === '') { continue; }
            // "Straat en huisnummer" is een straatveld; het huisnummer wordt er bij verzenden uit gehaald.
            if ($key === 'pohuisnummer' && strpos($t, 'straat') !== false) { continue; }
            // "Voor- en achternaam" is één naamveld.
            if (in_array($key, array('first_name', 'last_name'), true) && strpos($t, 'voor') !== false && strpos($t, 'achternaam') !== false) { continue; }
            if (in_array($t, $words[$key]['exact'], true)) { $best = max($best, 3); continue; }
            foreach ($words[$key]['contains'] as $w) {
                if ($t === $w) { $best = max($best, 3); } elseif (strpos($t, $w) !== false) { $best = max($best, 2); }
            }
        }
        return $best;
    }

    // Automatische koppeling plus de velden waarvoor meerdere kandidaten even goed passen.
    public static function auto_info($form) {
        $cache_key = (int) ($form['id'] ?? 0);
        if ($cache_key && isset(self::$auto_cache[$cache_key])) { return self::$auto_cache[$cache_key]; }
        $keys = array_keys(self::fields());
        $pairs = array(); $top = array(); $order = 0;
        foreach (self::candidates($form) as $id => $c) {
            foreach ($keys as $ki => $key) {
                $score = self::score($key, $c);
                if (!$score) { continue; }
                $pairs[] = array($score, $ki, $order, $key, (string) $id);
                if (!isset($top[$key]) || $score > $top[$key][0]) { $top[$key] = array($score, 1); }
                elseif ($score === $top[$key][0]) { $top[$key][1]++; }
            }
            $order++;
        }
        usort($pairs, function ($a, $b) {
            if ($a[0] !== $b[0]) { return $b[0] <=> $a[0]; }
            if ($a[1] !== $b[1]) { return $a[1] <=> $b[1]; }
            return $a[2] <=> $b[2];
        });
        $map = array_fill_keys($keys, ''); $used = array();
        foreach ($pairs as $p) {
            if ($map[$p[3]] !== '' || isset($used[$p[4]])) { continue; }
            $map[$p[3]] = $p[4]; $used[$p[4]] = true;
        }
        $ambiguous = array();
        foreach ($top as $key => $t) { if ($t[1] > 1 && $map[$key] !== '') { $ambiguous[$key] = true; } }
        // Losse voor- en achternaam gaan voor op één volledig naamveld.
        if ($map['first_name'] !== '' && $map['last_name'] !== '') { $map['full_name'] = ''; unset($ambiguous['full_name']); }
        $info = array('map' => $map, 'ambiguous' => $ambiguous);
        if ($cache_key) { self::$auto_cache[$cache_key] = $info; }
        return $info;
    }

    public static function auto_map($form) { $info = self::auto_info($form); return $info['map']; }

    // De koppeling die echt gebruikt wordt: automatisch, met handmatige keuzes eroverheen.
    public static function resolve_map($form, $config = null) {
        if ($config === null) { $config = self::form_config($form['id'] ?? 0); }
        $map = self::auto_map($form);
        $overrides = (array) ($config['map'] ?? array());
        if ($overrides) {
            $choices = self::choices($form);
            foreach ($overrides as $key => $value) {
                if (!array_key_exists($key, $map)) { continue; }
                $value = (string) $value;
                if ($value === 'none') { $map[$key] = ''; }
                elseif ($value !== '' && isset($choices[$value])) { $map[$key] = $value; }
            }
        }
        return $map;
    }

    /* ---------------------------------------------------------------------
     * Gegevens opbouwen
     * ------------------------------------------------------------------- */

    // De gegevens zoals KeyVue ze krijgt. Lege velden vallen weg.
    public static function contact($entry, $map) {
        $v = array();
        foreach (array_keys(self::fields()) as $key) {
            $id = (string) ($map[$key] ?? '');
            $raw = ($id !== '' && isset($entry[$id]) && is_scalar($entry[$id])) ? trim((string) $entry[$id]) : '';
            $v[$key] = $key === 'email' ? $raw : sanitize_text_field($raw);
        }
        // Tussenvoegsel van het naamveld hoort bij de achternaam.
        if ($v['last_name'] !== '' && preg_match('/^(\d+)\.6$/', (string) ($map['last_name'] ?? ''), $m)) {
            $middle = isset($entry[$m[1] . '.4']) && is_scalar($entry[$m[1] . '.4']) ? sanitize_text_field(trim((string) $entry[$m[1] . '.4'])) : '';
            if ($middle !== '') { $v['last_name'] = $middle . ' ' . $v['last_name']; }
        }
        // Eén naamveld: eerste woord is de voornaam, de rest de achternaam.
        if ($v['full_name'] !== '' && $v['first_name'] === '' && $v['last_name'] === '') {
            $parts = preg_split('/\s+/', $v['full_name'], 2);
            $v['first_name'] = $parts[0]; $v['last_name'] = $parts[1] ?? '';
        }
        // Straat en huisnummer in één veld: het huisnummer apart doorgeven.
        if ($v['pohuisnummer'] === '' && $v['postraatnaam'] !== '' && preg_match('/^(.+)\s+(\d+\s?[a-zA-Z]{0,2}(?:[\-\/]\s?[a-zA-Z0-9]+)?)$/', $v['postraatnaam'], $m)) {
            $v['postraatnaam'] = rtrim($m[1], ' ,'); $v['pohuisnummer'] = $m[2];
        }
        if ($v['zip'] !== '') { $v['zip'] = strtoupper(preg_replace('/\s+/', '', $v['zip'])); }

        $contact = array();
        foreach (array('email', 'first_name', 'last_name', 'phone') as $key) { if ($v[$key] !== '') { $contact[$key] = $v[$key]; } }
        foreach (array('zip', 'pohuisnummer', 'postraatnaam', 'poplaats', 'country', 'gender', 'birthday') as $key) { if ($v[$key] !== '') { $contact['fields'][$key] = $v[$key]; } }
        return $contact;
    }

    public static function body($contact) {
        return http_build_query(array('contact' => $contact), '', '&', PHP_QUERY_RFC1738);
    }

    /* ---------------------------------------------------------------------
     * Wachtrij en verzending
     * ------------------------------------------------------------------- */

    public static function after_submission($entry, $form) { self::enqueue($entry, $form, false); }

    // Geeft true als de inzending (opnieuw) in de wachtrij staat.
    public static function enqueue($entry, $form, $force) {
        global $wpdb;
        if (($entry['status'] ?? 'active') !== 'active' || gform_get_meta($entry['id'], 'keyvue_gf_sent')) { return false; }
        $g = self::globals();
        if (!self::valid_url($g['url'])) { return false; }
        if (!$force && !self::form_enabled($form['id'])) { return false; }
        $map = self::resolve_map($form);
        if ($map['email'] === '') { return false; } // Formulier zonder e-mailveld: KeyVue kan er niets mee.
        $now = gmdate('Y-m-d H:i:s');
        // De unieke index op entry_id voorkomt dubbele opdrachten bij herhaalde hooks.
        $result = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table() . ' (entry_id,form_id,config,status,available_at,updated_at,created_at) VALUES (%d,%d,%s,%s,%s,%s,%s)', $entry['id'], $form['id'], $force ? 'force' : '', 'pending', $now, $now, $now));
        if ($result === false) {
            gform_update_meta($entry['id'], 'keyvue_gf_queue_error', 1);
            GFAPI::add_note($entry['id'], 0, 'KeyVue', 'Wachtrij opslaan mislukt. De inzending is bewaard; verstuur hem handmatig via het KeyVue-blok bij deze inzending.');
            update_option('keyvue_gf_queue_error', absint($entry['id']), false);
            self::notify($form['id'], $entry['id'], 'Wachtrij opslaan mislukt (databasefout).');
            return false;
        }
        if (!$force && !self::$direct) {
            self::$direct = true;
            add_action('shutdown', array(__CLASS__, 'direct'), 100);
        }
        return true;
    }

    // Direct na de inzending versturen, zonder dat de bezoeker hoeft te wachten.
    public static function direct() {
        if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
        else {
            // Geen manier om de bezoeker eerst los te laten: laat WordPress-cron het meteen oppakken.
            wp_clear_scheduled_hook(self::CRON);
            wp_schedule_event(time() - 1, 'keyvue_minute', self::CRON);
            if (function_exists('spawn_cron')) { spawn_cron(); }
            return;
        }
        ignore_user_abort(true);
        self::process();
    }

    public static function classify($response) {
        if (is_wp_error($response)) { return array('review', 'Netwerkfout of time-out; ontvangst bij KeyVue is onbekend.', 0); }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $json = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($json) && ((isset($json['success']) && $json['success'] === false) || !empty($json['error']) || !empty($json['errors']) || in_array($json['status'] ?? '', array('error', 'failed'), true))) {
                return array('failed', 'KeyVue meldt een inhoudelijke fout; controleer de invoer bij KeyVue.', 0);
            }
            return array('sent', 'HTTP ' . $code . ': geaccepteerd door KeyVue.', 0);
        }
        if ($code === 429) {
            $header = wp_remote_retrieve_header($response, 'retry-after');
            $delay = is_numeric($header) ? (int) $header : (strtotime((string) $header) ?: time()) - time();
            return array('retry', 'HTTP 429: KeyVue vraagt later opnieuw te proberen.', max(60, $delay));
        }
        if ($code >= 500 || $code === 408) { return array('review', 'HTTP ' . $code . ': ontvangst bij KeyVue is onbekend.', 0); }
        return array('failed', 'HTTP ' . $code . ': geweigerd door KeyVue. Controleer de webhook-URL.', 0);
    }

    public static function process() {
        global $wpdb;
        if (!class_exists('GFAPI')) { return; }
        $table = self::table(); $now = gmdate('Y-m-d H:i:s');
        // Een onderbroken worker kan al geleverd hebben: nooit blind herhalen.
        $stale = $wpdb->get_results($wpdb->prepare("SELECT id,entry_id,form_id FROM $table WHERE status='processing' AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - 600)));
        foreach ($stale as $s) {
            $message = 'Verwerking onderbroken; controleer eerst of KeyVue de lead heeft ontvangen.';
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET status='review',message=%s,lock_token='' WHERE id=%d AND status='processing'", $message, $s->id))) {
                self::notify($s->form_id, $s->entry_id, $message);
            }
        }
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE status IN ('pending','retry') AND available_at <= %s ORDER BY id LIMIT 5", $now));
        foreach ($ids as $id) { self::run_job((int) $id); }
        // Geslaagde en overgeslagen opdrachten verdwijnen na 90 dagen; open problemen blijven staan.
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE status IN ('sent','cancelled') AND updated_at < %s", gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS)));
    }

    private static function run_job($id) {
        global $wpdb;
        $table = self::table(); $now = gmdate('Y-m-d H:i:s'); $token = wp_generate_uuid4();
        $claimed = $wpdb->query($wpdb->prepare("UPDATE $table SET status='processing',attempts=attempts+1,lock_token=%s,updated_at=%s WHERE id=%d AND status IN ('pending','retry') AND available_at <= %s", $token, $now, $id, $now));
        if ($claimed !== 1) { return; }
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$job) { return; }

        $entry = GFAPI::get_entry($job->entry_id);
        if (is_wp_error($entry) || ($entry['status'] ?? '') !== 'active') { self::finish($job, $token, 'cancelled', 'Inzending is verwijderd of als spam gemarkeerd.'); return; }
        if (gform_get_meta($job->entry_id, 'keyvue_gf_sent')) { self::finish($job, $token, 'sent', 'Was al verstuurd.'); return; }
        if ($job->config !== 'force' && !self::form_enabled($job->form_id)) { self::finish($job, $token, 'cancelled', 'Verzenden staat uit voor dit formulier.'); return; }
        $g = self::globals();
        if (!self::valid_url($g['url'])) { self::finish($job, $token, 'failed', 'Webhook-URL ontbreekt of is ongeldig. Vul hem in bij de KeyVue-instellingen.'); return; }
        $form = GFAPI::get_form($job->form_id);
        if (!$form) { self::finish($job, $token, 'cancelled', 'Formulier bestaat niet meer.'); return; }

        $contact = self::contact($entry, self::resolve_map($form));
        if (empty($contact['email'])) {
            self::finish($job, $token, 'cancelled', 'Geen e-mailadres in de inzending; KeyVue vereist een e-mailadres.');
            GFAPI::add_note($job->entry_id, 0, 'KeyVue', 'Niet verstuurd: geen e-mailadres in de inzending.');
            return;
        }
        if (!is_email($contact['email'])) { self::finish($job, $token, 'failed', 'Geen geldig e-mailadres in de inzending.'); return; }

        $response = wp_remote_post($g['url'], array(
            'timeout' => 20, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'),
            'body' => self::body($contact),
        ));
        list($state, $message, $delay) = self::classify($response);
        if ($state === 'review' && !empty($g['retry_ambiguous'])) { $state = 'retry'; }
        if ($state === 'retry') {
            if ((int) $job->attempts >= self::MAX_ATTEMPTS) { $state = 'review'; $message .= ' Maximum aantal pogingen bereikt.'; }
            else { $delay = max($delay, min(21600, 60 * pow(3, (int) $job->attempts - 1)) + wp_rand(0, 30)); }
        }
        self::finish($job, $token, $state, $message, $delay);
    }

    private static function finish($job, $token, $state, $message, $delay = 0) {
        global $wpdb;
        $changed = $wpdb->update(self::table(), array('status' => $state, 'message' => $message, 'lock_token' => '', 'updated_at' => gmdate('Y-m-d H:i:s'), 'available_at' => gmdate('Y-m-d H:i:s', time() + $delay)), array('id' => $job->id, 'lock_token' => $token));
        if (!$changed) { return; }
        if ($state === 'sent') { gform_update_meta($job->entry_id, 'keyvue_gf_sent', gmdate('c')); }
        if (in_array($state, array('sent', 'failed', 'review'), true)) { GFAPI::add_note($job->entry_id, 0, 'KeyVue', $message); }
        if (in_array($state, array('failed', 'review'), true)) { self::notify($job->form_id, $job->entry_id, $message); }
    }

    // E-mailmelding bij een probleem; hooguit één per tien minuten. Bevat geen contactgegevens.
    private static function notify($form_id, $entry_id, $message) {
        $g = self::globals();
        if (!is_email($g['notify_email']) || get_transient('keyvue_gf_mail_lock')) { return; }
        set_transient('keyvue_gf_mail_lock', 1, 10 * MINUTE_IN_SECONDS);
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $form = class_exists('GFAPI') ? GFAPI::get_form($form_id) : false;
        $lines = array(
            'Een formulierinzending is niet (zeker) bij KeyVue aangekomen.',
            '',
            'Website: ' . home_url(),
            'Formulier: ' . ($form ? $form['title'] : '#' . $form_id),
            'Inzending: #' . $entry_id . ' — ' . self::entry_url($form_id, $entry_id),
            'Melding: ' . $message,
            '',
            'Overzicht en opnieuw versturen: ' . self::url(),
            '',
            'Bij meerdere problemen kort na elkaar komt er één e-mail per tien minuten; het overzicht toont ze allemaal.',
        );
        wp_mail($g['notify_email'], '[' . $site . '] KeyVue: verzending vraagt aandacht', implode("\n", $lines));
    }

    /* ---------------------------------------------------------------------
     * Beheer: hulpfuncties
     * ------------------------------------------------------------------- */

    public static function tab($tabs) {
        $tabs[] = array('name' => 'keyvue', 'label' => 'KeyVue', 'title' => 'KeyVue koppeling', 'icon' => 'gform-icon--cog');
        return $tabs;
    }

    private static function url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'gf_settings', 'subview' => 'keyvue'), $args), admin_url('admin.php'));
    }

    private static function entry_url($form_id, $entry_id) {
        return add_query_arg(array('page' => 'gf_entries', 'view' => 'entry', 'id' => $form_id, 'lid' => $entry_id), admin_url('admin.php'));
    }

    private static function action_url($action, $args = array()) {
        return wp_nonce_url(add_query_arg(array_merge(array('action' => 'keyvue_gf_' . $action), $args), admin_url('admin-post.php')), 'keyvue_gf_' . $action);
    }

    private static function action_link($action, $args, $label, $confirm = '', $class = 'button') {
        $onclick = $confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '';
        return '<a class="' . esc_attr($class) . '" href="' . esc_url(self::action_url($action, $args)) . '"' . $onclick . '>' . esc_html($label) . '</a>';
    }

    private static function guard($action) {
        if (!current_user_can('manage_options')) { wp_die('Geen toegang.'); }
        check_admin_referer('keyvue_gf_' . $action);
        if (!class_exists('GFAPI')) { wp_die('Gravity Forms is niet actief.'); }
    }

    private static function back($msg, $fallback_args = array()) {
        $ref = wp_get_referer();
        $target = $ref ? remove_query_arg(array('kv_msg', 'kv_test', 'entry_id'), $ref) : self::url($fallback_args);
        wp_safe_redirect(add_query_arg('kv_msg', $msg, $target));
        exit;
    }

    private static function local_time($gmt) {
        return get_date_from_gmt($gmt, 'd-m-Y H:i');
    }

    private static function status_labels() {
        return array('pending' => 'In wachtrij', 'processing' => 'Wordt verstuurd', 'retry' => 'Nieuwe poging gepland', 'sent' => 'Verstuurd', 'failed' => 'Mislukt', 'review' => 'Controleren', 'cancelled' => 'Niet verstuurd');
    }

    private static function pill($text, $color) {
        $colors = array('green' => '#e6f4ea;color:#1a7f37', 'red' => '#fcf0f1;color:#b32d2e', 'orange' => '#fcf9e8;color:#8a6100', 'grey' => '#f0f0f1;color:#50575e');
        return '<span style="display:inline-block;white-space:nowrap;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;background:' . $colors[$color] . '">' . esc_html($text) . '</span>';
    }

    private static function status_pill($status) {
        $labels = self::status_labels();
        $color = array('sent' => 'green', 'failed' => 'red', 'review' => 'orange', 'cancelled' => 'grey');
        return self::pill($labels[$status] ?? $status, $color[$status] ?? 'grey');
    }

    private static function panel_open($title) {
        echo '<fieldset class="gform-settings-panel gform-settings-panel--full"><legend class="gform-settings-panel__title gform-settings-panel__title--header">' . esc_html($title) . '</legend><div class="gform-settings-panel__content">';
    }

    private static function panel_close() { echo '</div></fieldset>'; }

    private static function job_for_entry($entry_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE entry_id=%d', $entry_id));
    }

    /* ---------------------------------------------------------------------
     * Beheer: acties
     * ------------------------------------------------------------------- */

    public static function do_save_global() {
        self::guard('save_global');
        $g = self::globals();
        $url = self::clean_url(wp_unslash($_POST['url'] ?? ''));
        if ($url !== '') {
            if (!self::valid_url($url)) { self::back('bad_url'); }
            $g['url'] = $url;
        }
        $email = trim((string) wp_unslash($_POST['notify_email'] ?? ''));
        if ($email !== '' && !is_email($email)) { self::back('bad_email'); }
        $g['notify_email'] = $email;
        $g['default_enabled'] = !empty($_POST['default_enabled']);
        $g['retry_ambiguous'] = !empty($_POST['retry_ambiguous']);
        update_option(self::GLOBAL_OPTION, $g, false);
        self::back('saved');
    }

    public static function do_save_form() {
        self::guard('save_form');
        $id = absint($_POST['form_id'] ?? 0); $form = GFAPI::get_form($id);
        if (!$form) { wp_die('Formulier niet gevonden.'); }
        $all = self::settings(); $choices = self::choices($form);
        $new = array('enabled' => !empty($_POST['enabled']));
        $submitted = isset($_POST['map']) && is_array($_POST['map']) ? wp_unslash($_POST['map']) : array();
        foreach (array_keys(self::fields()) as $key) {
            $value = isset($submitted[$key]) && is_scalar($submitted[$key]) ? (string) $submitted[$key] : 'auto';
            if ($value === 'none' || isset($choices[$value])) { $new['map'][$key] = $value; }
        }
        $all[$id] = $new;
        update_option(self::OPTION, $all, false);
        self::back('saved', array('form_id' => $id));
    }

    public static function do_toggle() {
        self::guard('toggle');
        $id = absint($_REQUEST['form_id'] ?? 0);
        if (!GFAPI::get_form($id)) { wp_die('Formulier niet gevonden.'); }
        $all = self::settings();
        $c = isset($all[$id]) && is_array($all[$id]) ? $all[$id] : array();
        if (!empty($_REQUEST['reset'])) { unset($c['map']); }
        else { $c['enabled'] = !empty($_REQUEST['state']); }
        $all[$id] = $c;
        update_option(self::OPTION, $all, false);
        self::back('saved', array('form_id' => $id));
    }

    // Opnieuw versturen na een fout. De beheerder heeft bevestigd dat dit nodig is.
    public static function do_retry() {
        self::guard('retry');
        global $wpdb; $table = self::table();
        $id = absint($_REQUEST['job_id'] ?? 0);
        $done = $wpdb->query($wpdb->prepare("UPDATE $table SET status='pending',attempts=0,config='force',available_at=%s,message=%s WHERE id=%d AND status IN ('failed','review','cancelled')", gmdate('Y-m-d H:i:s'), 'Handmatig opnieuw gestart.', $id));
        if (!$done) { self::back('not_possible'); }
        self::process();
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d", $id));
        self::back('job_' . $status);
    }

    // Een inzending zonder verzendopdracht (oud, of formulier stond uit) alsnog versturen.
    public static function do_send() {
        self::guard('send');
        $entry_id = absint($_REQUEST['entry_id'] ?? 0);
        $entry = GFAPI::get_entry($entry_id);
        if (is_wp_error($entry)) { wp_die('Inzending niet gevonden.'); }
        $form = GFAPI::get_form($entry['form_id']);
        if (!$form) { wp_die('Formulier niet gevonden.'); }
        if (self::job_for_entry($entry_id) || !self::enqueue($entry, $form, true)) { self::back('not_possible'); }
        gform_delete_meta($entry_id, 'keyvue_gf_queue_error');
        if (absint(get_option('keyvue_gf_queue_error')) === $entry_id) { delete_option('keyvue_gf_queue_error'); }
        self::process();
        $job = self::job_for_entry($entry_id);
        self::back('job_' . ($job ? $job->status : 'pending'));
    }

    // Een probleem als afgehandeld markeren, zodat de waarschuwing verdwijnt.
    public static function do_dismiss() {
        self::guard('dismiss');
        global $wpdb;
        $id = absint($_REQUEST['job_id'] ?? 0);
        $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET status='cancelled',message=%s,updated_at=%s WHERE id=%d AND status IN ('failed','review')", 'Handmatig afgehandeld.', gmdate('Y-m-d H:i:s'), $id));
        self::back('dismissed');
    }

    /* ---------------------------------------------------------------------
     * Beheer: meldingen en inzendingspagina
     * ------------------------------------------------------------------- */

    public static function notices() {
        if (!current_user_can('manage_options') || !class_exists('GFForms') || !GFForms::is_gravity_page()) { return; }
        $messages = array(
            'saved' => array('success', 'Opgeslagen.'),
            'dismissed' => array('success', 'Gemarkeerd als afgehandeld.'),
            'bad_url' => array('error', 'Ongeldige KeyVue-URL. Plak de volledige link uit de mail van KeyVue (https://api.keyvue.nl/…?company=…&webhook=…).'),
            'bad_email' => array('error', 'Ongeldig e-mailadres voor foutmeldingen.'),
            'not_possible' => array('error', 'Deze inzending kan nu niet (opnieuw) verstuurd worden. Controleer of de webhook-URL is ingesteld, het formulier een e-mailveld heeft en de inzending niet al verstuurd is.'),
            'job_sent' => array('success', 'Verstuurd naar KeyVue.'),
            'job_failed' => array('error', 'Versturen mislukt. Zie de toelichting bij de inzending.'),
            'job_review' => array('warning', 'Onduidelijk of KeyVue de inzending heeft ontvangen. Zie de toelichting bij de inzending.'),
            'job_retry' => array('warning', 'KeyVue vraagt om later opnieuw te proberen; dat gebeurt automatisch.'),
            'job_cancelled' => array('warning', 'Niet verstuurd. Zie de toelichting bij de inzending.'),
            'job_pending' => array('info', 'In de wachtrij gezet.'),
        );
        $msg = isset($_GET['kv_msg']) ? sanitize_key($_GET['kv_msg']) : '';
        if (isset($messages[$msg])) { echo '<div class="notice gf-notice notice-' . esc_attr($messages[$msg][0]) . ' is-dismissible"><p>KeyVue: ' . esc_html($messages[$msg][1]) . '</p></div>'; }

        $on_tab = ($_GET['page'] ?? '') === 'gf_settings' && ($_GET['subview'] ?? '') === 'keyvue';
        if ($on_tab) { return; } // De tab toont dit zelf.
        global $wpdb;
        $g = self::globals();
        if (!self::valid_url($g['url'])) {
            echo '<div class="notice gf-notice notice-warning"><p>KeyVue: de webhook-URL is nog niet ingesteld; er wordt niets verstuurd. <a href="' . esc_url(self::url()) . '">Instellen</a></p></div>';
            return;
        }
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('failed','review')");
        if ($count || get_option('keyvue_gf_queue_error')) {
            echo '<div class="notice gf-notice notice-warning"><p>KeyVue: ' . esc_html($count > 1 ? $count . ' inzendingen vragen aandacht.' : 'een inzending vraagt aandacht.') . ' <a href="' . esc_url(self::url()) . '">Bekijken</a></p></div>';
        }
    }

    public static function meta_box($boxes, $entry, $form) {
        $boxes['keyvue'] = array('title' => 'KeyVue', 'callback' => array(__CLASS__, 'meta_box_content'), 'context' => 'side');
        return $boxes;
    }

    public static function meta_box_content($args) {
        $entry = $args['entry'] ?? array();
        if (empty($entry['id'])) { return; }
        $job = self::job_for_entry($entry['id']);
        $sent = gform_get_meta($entry['id'], 'keyvue_gf_sent');
        $admin = current_user_can('manage_options');
        if ($job) {
            echo '<p>' . self::status_pill($job->status) . '</p><p>' . esc_html($job->message) . '<br><small>' . esc_html(self::local_time($job->updated_at)) . '</small></p>';
            if ($admin && in_array($job->status, array('failed', 'review'), true)) {
                echo '<p>' . self::action_link('retry', array('job_id' => $job->id), 'Opnieuw versturen', 'Controleer eerst in KeyVue of deze lead er niet al staat. Opnieuw versturen?') . '</p>';
            } elseif ($admin && $job->status === 'cancelled') {
                echo '<p>' . self::action_link('retry', array('job_id' => $job->id), 'Toch versturen', 'Deze inzending naar KeyVue versturen?') . '</p>';
            }
        } elseif ($sent) {
            echo '<p>' . self::status_pill('sent') . '</p>';
        } else {
            echo '<p>' . self::pill('Niet verstuurd', 'grey') . '</p>';
            if ($admin && ($entry['status'] ?? '') === 'active') {
                echo '<p>' . self::action_link('send', array('entry_id' => $entry['id']), 'Versturen naar KeyVue', 'Deze inzending naar KeyVue versturen?') . '</p>';
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Beheer: instellingenpagina (Formulieren → Instellingen → KeyVue)
     * ------------------------------------------------------------------- */

    public static function page() {
        if (!current_user_can('manage_options')) { echo '<p>Geen toegang.</p>'; return; }
        if (!class_exists('GFAPI')) { echo '<p>Activeer eerst Gravity Forms.</p>'; return; }
        $g = self::globals(); $has_url = self::valid_url($g['url']);
        echo '<style>.kv .gform-settings-panel__content{padding-top:14px}.kv table.widefat td,.kv table.widefat th{vertical-align:middle;padding:10px 12px}.kv .kv-muted{color:#646970}.kv .kv-map td,.kv .kv-map th{padding:8px 12px 8px 0;text-align:left;vertical-align:middle}.kv .kv-map select{min-width:320px}.kv .kv-box{padding:10px 14px;border-left:4px solid #dba617;background:#fcf9e8;margin:0 0 16px}.kv .kv-box.kv-err{border-color:#b32d2e;background:#fcf0f1}.kv code{word-break:break-all}</style><div class="kv">';

        if (!$has_url) { echo '<div class="kv-box kv-err"><strong>Er wordt nog niets verstuurd.</strong> Plak hieronder bij Instellingen de webhook-URL van KeyVue voor deze website.</div>'; }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) { echo '<div class="kv-box">WordPress-cron staat uit op deze site. Zorg dat de hosting wp-cron.php elke minuut aanroept; anders worden nieuwe pogingen na een storing niet uitgevoerd.</div>'; }
        $error_entry = absint(get_option('keyvue_gf_queue_error'));
        if ($error_entry) { echo '<div class="kv-box kv-err">Inzending #' . esc_html($error_entry) . ' kon door een databasefout niet in de wachtrij. Open de inzending en klik in het KeyVue-blok op “Versturen naar KeyVue”.</div>'; }

        $id = absint($_GET['form_id'] ?? 0);
        $form = $id ? GFAPI::get_form($id) : false;
        if ($form) { self::form_view($form, $has_url); }
        else {
            self::overview($has_url);
            self::global_settings($g, $has_url);
            self::jobs();
        }
        echo '</div>';
    }

    private static function short($key) {
        $names = self::fields();
        return $names[$key];
    }

    private static function overview($has_url) {
        global $wpdb;
        $forms = GFAPI::get_forms(); $all = self::settings();
        $last = array();
        foreach ((array) $wpdb->get_results('SELECT form_id, MAX(updated_at) AS t FROM ' . self::table() . " WHERE status='sent' GROUP BY form_id") as $row) { $last[(int) $row->form_id] = $row->t; }

        self::panel_open('Formulieren');
        echo '<p>Elk formulier met een e-mailveld stuurt zijn inzendingen automatisch naar KeyVue. Velden worden zelf herkend; een nieuw formulier hoeft u hier niet in te stellen. Zet een formulier uit als het geen leads oplevert (bijvoorbeeld een nieuwsbrief of sollicitatie).</p>';
        echo '<table class="widefat striped"><thead><tr><th>Formulier</th><th>Status</th><th>Gaat mee naar KeyVue</th><th>Laatst verstuurd</th><th></th></tr></thead><tbody>';
        foreach ($forms as $f) {
            $fid = (int) $f['id']; $c = isset($all[$fid]) && is_array($all[$fid]) ? $all[$fid] : array();
            $info = self::auto_info($f); $map = self::resolve_map($f, $c); $enabled = self::form_enabled($fid);
            $mapped = array();
            foreach ($map as $key => $field_id) {
                if ($field_id === '') { continue; }
                $warn = isset($info['ambiguous'][$key]) && !isset($c['map'][$key]);
                $mapped[] = $warn ? '<span style="color:#8a6100" title="Meerdere velden passen; controleer de keuze">⚠ ' . esc_html(self::short($key)) . '</span>' : esc_html(self::short($key));
            }
            if ($map['email'] === '') { $status = self::pill('Geen e-mailveld', 'grey') . '<br><small class="kv-muted">wordt niet verstuurd</small>'; }
            elseif (!$enabled) { $status = self::pill('Uit', 'grey'); }
            elseif (!$has_url) { $status = self::pill('Wacht op webhook-URL', 'orange'); }
            else { $status = self::pill('Aan', 'green'); }
            if (!empty($c['map'])) { $status .= '<br><small class="kv-muted">handmatig aangepast</small>'; }
            echo '<tr><td><strong>' . esc_html($f['title']) . '</strong></td><td>' . $status . '</td><td>' . ($mapped ? implode(', ', $mapped) : '<span class="kv-muted">—</span>') . '</td><td>' . (isset($last[$fid]) ? esc_html(self::local_time($last[$fid])) : '<span class="kv-muted">—</span>') . '</td><td style="white-space:nowrap;text-align:right">';
            if ($map['email'] !== '') { echo self::action_link('toggle', array('form_id' => $fid, 'state' => $enabled ? 0 : 1), $enabled ? 'Uitzetten' : 'Aanzetten') . ' '; }
            echo '<a class="button" href="' . esc_url(self::url(array('form_id' => $fid))) . '">Velden en test</a></td></tr>';
        }
        if (!$forms) { echo '<tr><td colspan="5">Nog geen formulieren.</td></tr>'; }
        echo '</tbody></table>';
        self::panel_close();
    }

    private static function global_settings($g, $has_url) {
        self::panel_open('Instellingen');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="keyvue_gf_save_global">';
        wp_nonce_field('keyvue_gf_save_global');
        echo '<p><label for="kv-url"><strong>Webhook-URL van KeyVue</strong></label><br>';
        echo $has_url ? self::pill('Ingesteld', 'green') . ' <span class="kv-muted">eindigt op …' . esc_html(substr($g['url'], -4)) . '. Laat het veld leeg om hem te behouden.</span>' : self::pill('Nog niet ingesteld', 'red');
        echo '<br><input id="kv-url" type="password" name="url" value="" class="large-text" autocomplete="new-password" placeholder="https://api.keyvue.nl/api/v1/service/active_campaign/referral?company=…&amp;webhook=…" style="margin-top:6px"><br><small class="kv-muted">Eén link per website: gebruik op De Keizer de De Keizer-link en op GeldXpert de GeldXpert-link. Behandel de link als een wachtwoord.</small></p>';
        echo '<p><label for="kv-mail"><strong>Foutmeldingen mailen naar</strong></label><br><input id="kv-mail" type="email" name="notify_email" value="' . esc_attr($g['notify_email']) . '" class="regular-text"><br><small class="kv-muted">Krijgt een e-mail als een inzending niet (zeker) bij KeyVue is aangekomen. Leeg laten om geen e-mails te sturen.</small></p>';
        echo '<p><label><input type="checkbox" name="default_enabled" value="1" ' . checked(!empty($g['default_enabled']), true, false) . '> Nieuwe formulieren automatisch naar KeyVue versturen</label><br><small class="kv-muted">Uit: een nieuw formulier verstuurt pas nadat u het hierboven aanzet.</small></p>';
        echo '<p><label><input type="checkbox" name="retry_ambiguous" value="1" ' . checked(!empty($g['retry_ambiguous']), true, false) . '> Ook bij time-outs en serverfouten automatisch opnieuw proberen</label><br><small class="kv-muted">Standaard uit: KeyVue kan de aanvraag dan al verwerkt hebben, en opnieuw versturen kan een dubbele lead geven. Alleen aanzetten als KeyVue bevestigt dat dat niet gebeurt.</small></p>';
        echo '<p><button class="primary button large">Instellingen opslaan</button></p></form>';
        self::panel_close();
    }

    private static function form_view($form, $has_url) {
        $id = (int) $form['id']; $c = self::form_config($id);
        $choices = self::choices($form); $info = self::auto_info($form); $map = self::resolve_map($form, $c);
        $overrides = (array) ($c['map'] ?? array()); $enabled = self::form_enabled($id);

        echo '<p><a href="' . esc_url(self::url()) . '">← Alle formulieren</a></p>';
        self::panel_open($form['title']);
        if ($map['email'] === '') {
            echo '<div class="kv-box"><strong>Geen e-mailveld gevonden.</strong> KeyVue vereist een e-mailadres; dit formulier wordt daarom niet verstuurd. Voeg een e-mailveld toe aan het formulier, of kies hieronder zelf het juiste veld.</div>';
        }
        $ambiguous = array_diff_key($info['ambiguous'], $overrides);
        if ($ambiguous) {
            echo '<div class="kv-box"><strong>Controleer:</strong> voor ' . esc_html(implode(', ', array_map(array(__CLASS__, 'short'), array_keys($ambiguous)))) . ' passen meerdere velden. De eerste is gekozen; pas dit hieronder aan als dat niet klopt.</div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="keyvue_gf_save_form"><input type="hidden" name="form_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('keyvue_gf_save_form');
        echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked($enabled, true, false) . '> <strong>Inzendingen van dit formulier naar KeyVue versturen</strong></label></p>';
        echo '<p class="kv-muted">De velden zijn automatisch herkend. Alleen het e-mailadres is verplicht; wat niet in het formulier zit, gaat gewoon niet mee. Kies alleen zelf een veld als de automatische keuze niet klopt.</p>';
        echo '<table class="kv-map"><thead><tr><th>Bij KeyVue</th><th>Veld in dit formulier</th><th></th></tr></thead><tbody>';
        foreach (self::fields() as $key => $label) {
            $auto_id = $info['map'][$key]; $current = isset($overrides[$key]) ? (string) $overrides[$key] : 'auto';
            if ($current !== 'none' && $current !== 'auto' && !isset($choices[$current])) { $current = 'auto'; }
            $auto_text = $auto_id !== '' ? 'Automatisch: ' . $choices[$auto_id] : 'Automatisch: niet in dit formulier';
            if ($current !== 'auto') { $note = '<span class="kv-muted">handmatig gekozen</span>'; }
            elseif ($auto_id !== '' && isset($info['ambiguous'][$key])) { $note = '<span style="color:#8a6100">⚠ meerdere velden passen</span>'; }
            elseif ($auto_id !== '') { $note = '<span style="color:#1a7f37">✓ herkend</span>'; }
            elseif ($key === 'email') { $note = '<span style="color:#b32d2e">verplicht voor KeyVue</span>'; }
            else { $note = '<span class="kv-muted">gaat niet mee</span>'; }
            $hint = $key === 'full_name' ? '<br><small class="kv-muted">wordt gesplitst in voor- en achternaam</small>' : '';
            echo '<tr><th><label for="kv-s-' . esc_attr($key) . '">' . esc_html($label) . '</label>' . $hint . '</th><td><select id="kv-s-' . esc_attr($key) . '" name="map[' . esc_attr($key) . ']">';
            echo '<option value="auto" ' . selected($current, 'auto', false) . '>' . esc_html($auto_text) . '</option><option value="none" ' . selected($current, 'none', false) . '>Niet meesturen</option>';
            foreach ($choices as $field_id => $name) { echo '<option value="' . esc_attr($field_id) . '" ' . selected($current, (string) $field_id, false) . '>' . esc_html($name) . '</option>'; }
            echo '</select></td><td>' . $note . '</td></tr>';
        }
        echo '</tbody></table><p class="kv-muted">Andere velden (zoals het bericht) gaan niet naar KeyVue: daar is geen veld voor opgegeven. Ze blijven in de inzending en de e-mailnotificaties staan.</p>';
        echo '<p><button class="primary button large">Opslaan</button> ';
        if ($overrides) { echo self::action_link('toggle', array('form_id' => $id, 'reset' => 1), 'Alles terug naar automatisch'); }
        echo '</p></form>';
        self::panel_close();

        self::test_panel($form, $map, $has_url);
    }

    // Proefverzending: toont wat KeyVue zou ontvangen. Doet nooit een verzoek naar KeyVue.
    private static function test_panel($form, $map, $has_url) {
        $id = (int) $form['id'];
        self::panel_open('Test — er wordt niets verstuurd');
        echo '<p>Laat zien wat KeyVue zou ontvangen met de opgeslagen koppeling. Er gaat niets naar KeyVue en er komt niets in de wachtrij.</p>';
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="gf_settings"><input type="hidden" name="subview" value="keyvue"><input type="hidden" name="form_id" value="' . esc_attr($id) . '"><input type="hidden" name="kv_test" value="1">';
        echo '<label>Inzendingsnummer <input type="number" min="1" name="entry_id" value="' . esc_attr(absint($_GET['entry_id'] ?? 0) ?: '') . '" placeholder="laatste" style="width:110px"></label> <button class="button">Test uitvoeren</button></form>';
        if (!empty($_GET['kv_test'])) {
            $entry_id = absint($_GET['entry_id'] ?? 0); $sample = false; $entry = null;
            if ($entry_id) {
                $entry = GFAPI::get_entry($entry_id);
                if (is_wp_error($entry) || (int) $entry['form_id'] !== $id) {
                    echo '<div class="kv-box" style="margin-top:16px">Inzending #' . esc_html($entry_id) . ' hoort niet bij dit formulier of bestaat niet.</div>';
                    self::panel_close(); return;
                }
            } else {
                global $wpdb;
                $latest = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . GFFormsModel::get_entry_table_name() . " WHERE form_id=%d AND status='active' ORDER BY id DESC LIMIT 1", $id));
                $found = $latest ? GFAPI::get_entry($latest) : null;
                if ($found && !is_wp_error($found)) { $entry = $found; }
            }
            if (!$entry) {
                // Nog geen inzending: voorbeeldgegevens laten zien hoe de koppeling uitpakt.
                $examples = array('email' => 'jan.jansen@example.com', 'first_name' => 'Jan', 'last_name' => 'Jansen', 'full_name' => 'Jan Jansen', 'phone' => '0612345678', 'zip' => '1234 ab', 'pohuisnummer' => '10', 'postraatnaam' => 'Dorpsstraat', 'poplaats' => 'Heerenveen', 'country' => 'Nederland', 'gender' => 'Man', 'birthday' => '1980-01-31');
                $entry = array('id' => 0, 'form_id' => $id);
                foreach ($map as $key => $field_id) { if ($field_id !== '') { $entry[$field_id] = $examples[$key]; } }
                $sample = true;
            }
            $contact = self::contact($entry, $map); $choices = self::choices($form);
            echo '<h4 style="margin:20px 0 8px">' . ($sample ? 'Voorbeeldgegevens (dit formulier heeft nog geen inzendingen)' : 'Inzending #' . esc_html($entry['id'])) . '</h4>';
            if (empty($contact['email'])) { echo '<div class="kv-box kv-err">Deze inzending zou <strong>niet</strong> verstuurd worden: er is geen e-mailadres.</div>'; }
            elseif (!is_email($contact['email'])) { echo '<div class="kv-box kv-err">Deze inzending zou <strong>mislukken</strong>: het e-mailadres is ongeldig.</div>'; }
            elseif (!$has_url) { echo '<div class="kv-box">De gegevens kloppen, maar de webhook-URL is nog niet ingesteld.</div>'; }
            echo '<table class="widefat striped"><thead><tr><th>Veld bij KeyVue</th><th>Komt uit</th><th>Waarde</th></tr></thead><tbody>';
            foreach (self::fields() as $key => $label) {
                if ($key === 'full_name') { continue; }
                $top = in_array($key, array('email', 'first_name', 'last_name', 'phone'), true);
                $value = $top ? ($contact[$key] ?? '') : ($contact['fields'][$key] ?? '');
                $field_id = $map[$key];
                if ($field_id !== '') { $source = esc_html($choices[$field_id] ?? $field_id); }
                elseif ($value !== '' && in_array($key, array('first_name', 'last_name'), true)) { $source = esc_html($choices[$map['full_name']] ?? '') . ' <span class="kv-muted">(gesplitst)</span>'; }
                elseif ($value !== '' && $key === 'pohuisnummer') { $source = esc_html($choices[$map['postraatnaam']] ?? '') . ' <span class="kv-muted">(gesplitst)</span>'; }
                else { $source = '<span class="kv-muted">niet in dit formulier</span>'; }
                $cell = $value !== '' ? '<code>' . esc_html($value) . '</code>' : '<span class="kv-muted">' . ($field_id !== '' ? 'leeg in deze inzending' : '—') . '</span>';
                echo '<tr><td><code>' . esc_html($top ? 'contact[' . $key . ']' : 'contact[fields][' . $key . ']') . '</code></td><td>' . $source . '</td><td>' . $cell . '</td></tr>';
            }
            echo '</tbody></table>';
            if (!empty($contact['email'])) { echo '<p style="margin-top:12px"><small class="kv-muted">POST · application/x-www-form-urlencoded</small><br><code>' . esc_html(urldecode(self::body($contact))) . '</code></p>'; }
        }
        self::panel_close();
    }

    private static function jobs() {
        global $wpdb; $table = self::table();
        $page = max(1, absint($_GET['job_page'] ?? 1)); $per = 20; $offset = ($page - 1) * $per;
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY CASE WHEN status IN ('failed','review') THEN 0 ELSE 1 END, id DESC LIMIT %d OFFSET %d", $per, $offset));
        $titles = array();
        foreach (GFAPI::get_forms(null) as $f) { $titles[(int) $f['id']] = $f['title']; }

        self::panel_open('Verzendingen');
        echo '<p>Inzendingen worden direct na het versturen van het formulier doorgestuurd. Problemen staan bovenaan en worden ook gemaild.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Inzending</th><th>Formulier</th><th>Status</th><th>Toelichting</th><th>Bijgewerkt</th><th></th></tr></thead><tbody>';
        foreach ($jobs as $j) {
            echo '<tr><td><a href="' . esc_url(self::entry_url($j->form_id, $j->entry_id)) . '">#' . esc_html($j->entry_id) . '</a></td><td>' . esc_html($titles[(int) $j->form_id] ?? '#' . $j->form_id) . '</td><td>' . self::status_pill($j->status) . '</td><td>' . esc_html($j->message);
            if ($j->status === 'retry') { echo '<br><small class="kv-muted">Volgende poging: ' . esc_html(self::local_time($j->available_at)) . '</small>'; }
            echo '</td><td style="white-space:nowrap">' . esc_html(self::local_time($j->updated_at)) . '</td><td style="white-space:nowrap;text-align:right">';
            if (in_array($j->status, array('failed', 'review'), true)) {
                echo self::action_link('retry', array('job_id' => $j->id), 'Opnieuw versturen', 'Controleer eerst in KeyVue of deze lead er niet al staat. Opnieuw versturen?') . ' ';
                echo self::action_link('dismiss', array('job_id' => $j->id), 'Afgehandeld', '', 'button-link');
            }
            echo '</td></tr>';
        }
        if (!$jobs) { echo '<tr><td colspan="6">Nog geen verzendingen.</td></tr>'; }
        echo '</tbody></table>';
        if ($total > $per) {
            echo '<p>Pagina ' . esc_html($page) . ' van ' . esc_html(ceil($total / $per)) . '. ';
            if ($page > 1) { echo '<a href="' . esc_url(self::url(array('job_page' => $page - 1))) . '">Vorige</a> '; }
            if ($page * $per < $total) { echo '<a href="' . esc_url(self::url(array('job_page' => $page + 1))) . '">Volgende</a>'; }
            echo '</p>';
        }
        self::panel_close();
    }
}
