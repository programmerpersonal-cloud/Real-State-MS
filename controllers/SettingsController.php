<?php
/**
 * Settings Controller — admin company-wide settings.
 *
 * The settings table is a flat key/value store, so the presentation and the
 * validation rules for each key live here in one schema. Keys that exist in
 * the database but not in the schema still render (as plain text fields) so
 * nothing silently disappears when a new setting is seeded.
 */
class SettingsController
{
    /** Display order + chrome for each setting_group. */
    private const GROUPS = [
        'general' => [
            'label' => 'Company Profile',
            'icon'  => 'bi-building',
            'desc'  => 'Identity used across invoices, receipts, emails and the public website.',
        ],
        'appearance' => [
            'label' => 'Appearance',
            'icon'  => 'bi-palette',
            'desc'  => 'How the navigation rail is drawn for everyone signed in to this installation.',
        ],
        'financial' => [
            'label' => 'Financial',
            'icon'  => 'bi-cash-stack',
            'desc'  => 'Currency, tax and the default rates applied to sales, leases and payments.',
        ],
        'booking' => [
            'label' => 'Bookings',
            'icon'  => 'bi-calendar-check',
            'desc'  => 'Rules that govern reservations and how long a hold stays valid.',
        ],
        'documents' => [
            'label' => 'Documents',
            'icon'  => 'bi-folder2-open',
            'desc'  => 'Upload limits, expiry warnings and whether public documents appear on listings.',
            'action' => [
                'label' => 'Manage document categories',
                'icon'  => 'bi-tags',
                'page'  => 'document-categories',
            ],
        ],
    ];

    /**
     * Setting groups this screen does not draw.
     *
     * `backup` is edited on Backup Settings, which also owns the schedule
     * rows that live in a table of their own. Rendering the same keys here as
     * loose text fields would give one setting two edit surfaces with
     * different validation — and the one on this screen would be the wrong
     * one, because it cannot recompute next_run_at after a change.
     */
    private const HIDDEN_GROUPS = ['backup'];

    /**
     * Rail surface modes — value => [label, help].
     *
     * Three, not two. "Auto" is the one people actually want on a laptop
     * that already switches at dusk, and leaving it out means an
     * administrator picking dark for everyone including the half of the
     * office that does not want it.
     */
    public const RAIL_THEMES = [
        'light' => ['Light', 'A near-white rail with hairline dividers.'],
        'dark'  => ['Dark',  'A charcoal rail. The rest of the page stays light.'],
        'auto'  => ['Auto',  'Follows each person’s system appearance.'],
    ];

    /**
     * Accent presets — hex => name.
     *
     * A starting point, not a limit: the field beside them takes any
     * #rrggbb. They exist because "pick a colour" with nothing on offer is
     * how an interface ends up accented in whatever the colour picker
     * opened on.
     *
     * Any of them is safe in either surface mode, and so is anything an
     * administrator types instead — the rail never puts text in the accent.
     * See the derivation notes at the top of assets/css/rail.css.
     */
    public const RAIL_ACCENTS = [
        '#0a63a8' => 'Architect Blue',
        '#4f46e5' => 'Indigo',
        '#7c3aed' => 'Violet',
        '#0f766e' => 'Teal',
        '#15803d' => 'Forest',
        '#b45309' => 'Amber',
        '#be123c' => 'Rose',
        '#3f4653' => 'Graphite',
    ];

    /** Currency presets — value => [name, symbol]. */
    public const CURRENCIES = [
        'USD' => ['US Dollar', '$'],
        'EUR' => ['Euro', '€'],
        'GBP' => ['British Pound', '£'],
        'SOS' => ['Somali Shilling', 'Sh'],
        'KES' => ['Kenyan Shilling', 'KSh'],
        'ETB' => ['Ethiopian Birr', 'Br'],
        'AED' => ['UAE Dirham', 'AED'],
        'SAR' => ['Saudi Riyal', 'SR'],
        'TRY' => ['Turkish Lira', '₺'],
        'CAD' => ['Canadian Dollar', 'C$'],
        'AUD' => ['Australian Dollar', 'A$'],
        'INR' => ['Indian Rupee', '₹'],
    ];

    /**
     * Per-key metadata: label, help text, input type and validation bounds.
     */
    private static function schema(): array
    {
        $currencyOptions = [];
        foreach (self::CURRENCIES as $code => [$name]) {
            $currencyOptions[$code] = "$code — $name";
        }

        return [
            'company_name' => [
                'label' => 'Company Name', 'type' => 'text', 'required' => true, 'max' => 120,
                'hint'  => 'Shown on the dashboard, printed receipts and the public site.',
                'placeholder' => 'Saxane Real Estate',
            ],
            'company_legal_name' => [
                'label' => 'Registered Name', 'type' => 'text', 'max' => 160,
                'hint'  => 'Legal entity name for contracts and search-engine listings. Defaults to the trading name.',
                'placeholder' => 'Saxane Real Estate Ltd',
            ],
            'company_email' => [
                'label' => 'Company Email', 'type' => 'email', 'max' => 120,
                'hint'  => 'Published in the site header, footer and contact page, and used as the reply-to for enquiries.',
                'placeholder' => 'info@example.com', 'icon' => 'bi-envelope',
            ],
            'company_phone' => [
                'label' => 'Company Phone', 'type' => 'tel', 'max' => 40,
                'hint'  => 'Shown as a click-to-call link across the public site. Include the country code.',
                'placeholder' => '+252 61 000 0000', 'icon' => 'bi-telephone',
            ],
            'company_address' => [
                'label' => 'Business Address', 'type' => 'textarea', 'max' => 400, 'full' => true,
                'hint'  => 'Appears on invoices, receipts and lease agreements.',
                'placeholder' => "Street, District\nCity, Country",
            ],
            'company_logo' => [
                'label' => 'Company Logo', 'type' => 'logo', 'max' => 255, 'full' => true,
                'hint'  => 'PNG, JPG, WEBP or GIF, up to 3 MB. Shown in the sidebar, on the public site and on printed receipts. A transparent PNG looks best.',
            ],

            /* Appearance. Two keys, and that is the whole surface: everything
               else the rail draws is derived from them in rail.css. Six
               colour pickers would give an administrator six ways to make a
               rail nobody can read; one accent and one mode cannot fail. */
            'rail_theme' => [
                'label' => 'Sidebar Theme', 'type' => 'theme', 'full' => true,
                'hint'  => 'Applies to everyone signed in to this installation. The page beside the sidebar stays light in every mode.',
            ],
            'rail_accent' => [
                'label' => 'Accent Colour', 'type' => 'color', 'full' => true,
                'hint'  => 'Used for the logo tile, the current page and the unread counts. Labels are never drawn in it, so any colour stays readable.',
            ],

            'currency' => [
                'label' => 'Currency', 'type' => 'select', 'options' => $currencyOptions,
                'hint'  => 'Base currency for every amount recorded in the system, and the currency published in listing markup.',
            ],
            'currency_symbol' => [
                'label' => 'Currency Symbol', 'type' => 'text', 'max' => 8,
                'hint'  => 'Prefixed to every amount the system prints. Auto-fills when you pick a currency.',
                'placeholder' => '$',
            ],
            'tax_rate' => [
                'label' => 'Tax Rate', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => '0.01',
                'suffix' => '%', 'hint' => 'Pre-fills the tax on new sales and prints on the receipt. Recorded sales keep the rate they were charged at.',
            ],
            'commission_rate' => [
                'label' => 'Default Commission', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => '0.01',
                'suffix' => '%', 'hint' => 'Pre-fills the commission on new sales and the rate on new owner records. Can be overridden per deal.',
            ],
            'late_fee_percentage' => [
                'label' => 'Late Payment Fee', 'type' => 'number', 'min' => 0, 'max' => 100, 'step' => '0.01',
                'suffix' => '%', 'hint' => 'Pre-fills the late fee rate on new leases. Existing leases keep the rate they were signed with.',
            ],

            'reservation_expiry_days' => [
                'label' => 'Reservation Expiry', 'type' => 'number', 'min' => 1, 'max' => 365, 'step' => '1',
                'suffix' => 'days', 'hint' => 'Sets the default expiry date on new reservations. Holds past their expiry date are released automatically.',
            ],

            'document_expiry_warning_days' => [
                'label' => 'Expiry Warning', 'type' => 'number', 'min' => 1, 'max' => 365, 'step' => '1',
                'suffix' => 'days', 'hint' => 'How far ahead a document is flagged as expiring. Expired documents are only ever flagged — nothing is deleted automatically.',
            ],
            'document_max_size_mb' => [
                'label' => 'Maximum File Size', 'type' => 'number', 'min' => 1, 'max' => 10, 'step' => '1',
                'suffix' => 'MB', 'hint' => 'Upload ceiling for a single document. The server\'s own upload limit still applies if it is lower.',
            ],
            'document_public_enabled' => [
                'label' => 'Publish Public Documents', 'type' => 'toggle', 'full' => true,
                'hint'  => 'When on, documents marked Public appear on the property\'s public listing. Private and Staff Only documents are never published, whatever this is set to.',
            ],
        ];
    }

    public function index(): void
    {
        authorize('settings.view');

        $rows = getDBConnection()
            ->query("SELECT * FROM settings ORDER BY setting_group, id")
            ->fetchAll();

        $schema  = self::schema();
        $order   = array_keys($schema);
        $grouped = [];
        $updated = null;

        foreach ($rows as $r) {
            if (in_array($r['setting_group'], self::HIDDEN_GROUPS, true)) {
                continue;
            }
            $grouped[$r['setting_group']][] = $r;
            if (!empty($r['updated_at']) && $r['updated_at'] > $updated) {
                $updated = $r['updated_at'];
            }
        }

        // Sort each group by the schema order; unknown keys fall to the end.
        foreach ($grouped as $group => $items) {
            usort($items, function ($a, $b) use ($order) {
                $ia = array_search($a['setting_key'], $order, true);
                $ib = array_search($b['setting_key'], $order, true);
                return ($ia === false ? PHP_INT_MAX : $ia) <=> ($ib === false ? PHP_INT_MAX : $ib);
            });
            $grouped[$group] = $items;
        }

        // Known groups first, in the order declared above; extras keep their own order.
        $ordered = [];
        foreach (array_keys(self::GROUPS) as $g) {
            if (isset($grouped[$g])) $ordered[$g] = $grouped[$g];
        }
        foreach ($grouped as $g => $items) {
            if (!isset($ordered[$g])) $ordered[$g] = $items;
        }

        renderPage(VIEWS_PATH . '/admin/settings/index.php', [
            'grouped'      => $ordered,
            'groupMeta'    => self::GROUPS,
            'schema'       => $schema,
            'lastUpdated'  => $updated,
            'pageTitle'    => 'System Settings',
            'pageSubtitle' => 'Company profile, financial defaults and system behaviour.',
            'breadcrumbs'  => [['label' => 'Settings']],
        ]);
    }

    public function update(): void
    {
        authorize('settings.update');
        enforceCSRF();

        $db     = getDBConnection();
        $schema = self::schema();

        // Only keys that already exist in the table may be written — and only
        // keys this screen actually renders. A hidden group is skipped on the
        // way in as well as on the way out: a form field this page never drew
        // must not be writable by adding it to the POST body, or "edited
        // somewhere else" quietly becomes "editable from both places".
        $hidden = "'" . implode("','", self::HIDDEN_GROUPS) . "'";
        $current = [];
        foreach ($db->query("
            SELECT setting_key, setting_value FROM settings
             WHERE setting_group NOT IN ({$hidden})
        ")->fetchAll() as $r) {
            $current[$r['setting_key']] = (string)($r['setting_value'] ?? '');
        }

        $errors  = [];
        $changed = [];
        $posted  = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];

        foreach ($posted as $key => $raw) {
            if (!array_key_exists($key, $current) || is_array($raw)) continue;

            $rules = $schema[$key] ?? ['type' => 'text', 'max' => 255];
            // File-backed fields never arrive as text; they are handled below.
            if (($rules['type'] ?? '') === 'logo') continue;

            $label = $rules['label'] ?? ucwords(str_replace('_', ' ', $key));
            $value = self::normalize((string)$raw, $rules, $label, $errors);

            if ($value === null || $value === $current[$key]) continue;
            $changed[$key] = $value;
        }

        // ── Logo: upload, replace or remove ─────────────────────────────
        if (array_key_exists('company_logo', $current)) {
            $logo = self::handleLogoUpload($current['company_logo'], $errors);
            if ($logo !== null && $logo !== $current['company_logo']) {
                $changed['company_logo'] = $logo;
            }
        }

        if ($changed) {
            $stmt = $db->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = :key");
            foreach ($changed as $key => $value) {
                $stmt->execute([':val' => $value, ':key' => $key]);
            }
            logAudit(
                'updated_settings',
                'settings',
                0,
                json_encode(array_intersect_key($current, $changed), JSON_UNESCAPED_UNICODE),
                json_encode($changed, JSON_UNESCAPED_UNICODE)
            );
        }

        if ($errors) {
            setFlash('warning', count($errors) === 1
                ? $errors[0]
                : 'Some settings were not saved: ' . implode(' ', $errors));
        } elseif ($changed) {
            $n = count($changed);
            setFlash('success', "Settings saved — {$n} " . ($n === 1 ? 'change' : 'changes') . ' applied.');
        } else {
            setFlash('info', 'No changes to save.');
        }

        redirect(APP_URL . '/index.php?page=settings');
    }

    /**
     * Resolve the company logo from the submitted form.
     *
     * Returns the value to store, or null to leave it untouched. The old file
     * is deleted once the new one is safely in place — but only when it is one
     * of ours under assets/uploads/logo, never an arbitrary path from the DB.
     */
    private static function handleLogoUpload(string $currentValue, array &$errors): ?string
    {
        // Explicit removal wins over any file that was also selected.
        if (!empty($_POST['remove_company_logo'])) {
            self::deleteLogoFile($currentValue);
            return '';
        }

        $file = $_FILES['company_logo_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null; // nothing submitted — keep what is there
        }

        // PHP's own limits fire before any of our checks, so translate them.
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The logo is larger than the server allows.',
                UPLOAD_ERR_PARTIAL                        => 'The logo upload was interrupted. Try again.',
                default                                   => 'The logo could not be uploaded.',
            };
            return null;
        }

        if ($file['size'] > MAX_IMAGE_SIZE) {
            $errors[] = 'The logo must be ' . round(MAX_IMAGE_SIZE / 1048576) . ' MB or smaller.';
            return null;
        }

        // Sniff the real type rather than trusting the browser's content-type.
        // SVG is deliberately excluded: it can carry script and would be served
        // from our own origin.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, ALLOWED_IMAGE_TYPES, true) || getimagesize($file['tmp_name']) === false) {
            $errors[] = 'The logo must be a PNG, JPG, WEBP or GIF image.';
            return null;
        }

        $path = uploadFile($file, 'logo', ALLOWED_IMAGE_TYPES);
        if ($path === false) {
            $errors[] = 'The logo could not be saved. Check that assets/uploads is writable.';
            return null;
        }

        self::deleteLogoFile($currentValue);
        return $path;
    }

    /** Remove a previously uploaded logo file, ignoring URLs and stray paths. */
    private static function deleteLogoFile(string $value): void
    {
        if ($value === '' || !str_starts_with($value, 'assets/uploads/logo/')) return;
        $full = BASE_PATH . '/' . $value;
        if (is_file($full)) @unlink($full);
    }

    /**
     * Validate and coerce one submitted value.
     * Returns the value to store, or null to skip it.
     *
     * Values are stored raw (trimmed) — escaping happens at output time via
     * sanitize(), so escaping here too would double-encode on every save.
     */
    private static function normalize(string $raw, array $rules, string $label, array &$errors): ?string
    {
        $type  = $rules['type'] ?? 'text';
        $value = trim($raw);

        // Strip control characters; keep newlines/tabs only for textareas.
        $value = $type === 'textarea'
            ? preg_replace('/[^\P{C}\n\t]+/u', '', $value)
            : preg_replace('/\p{C}+/u', '', $value);
        $value = (string)$value;

        switch ($type) {
            case 'toggle':
                return $value === '1' ? '1' : '0';

            case 'number':
                if ($value === '') {
                    if (!empty($rules['required'])) {
                        $errors[] = "$label is required.";
                        return null;
                    }
                    return '';
                }
                if (!is_numeric($value)) {
                    $errors[] = "$label must be a number.";
                    return null;
                }
                $num = (float)$value;
                $min = $rules['min'] ?? null;
                $max = $rules['max'] ?? null;
                if (($min !== null && $num < $min) || ($max !== null && $num > $max)) {
                    $errors[] = "$label must be between {$min} and {$max}.";
                    return null;
                }
                // Keep integers clean (7, not 7.0) and drop trailing zeros on decimals.
                return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.') ?: '0';

            case 'email':
                if ($value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "$label is not a valid email address.";
                    return null;
                }
                return $value;

            case 'logo':
                // Scheme is pinned to http/https — FILTER_VALIDATE_URL alone
                // would happily accept javascript: and data: URLs.
                if ($value !== '' && (
                    !filter_var($value, FILTER_VALIDATE_URL) ||
                    !in_array(strtolower((string)parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                )) {
                    $errors[] = "$label must be a valid http(s) URL.";
                    return null;
                }
                return $value;

            case 'select':
                $options = $rules['options'] ?? [];
                if ($options && !array_key_exists($value, $options)) {
                    $errors[] = "$label has an unsupported value.";
                    return null;
                }
                return $value;

            case 'theme':
                if (!array_key_exists($value, self::RAIL_THEMES)) {
                    $errors[] = "$label must be Light, Dark or Auto.";
                    return null;
                }
                return $value;

            case 'color':
                // Only #rrggbb. Shorthand, named colours and rgb() are all
                // refused — not because they would look wrong, but because
                // this value is written into a <style> block on every signed-in
                // page, and the narrowest input that does the job is the one
                // that cannot be turned into something else. railAccent() in
                // includes/functions.php applies the same test on the way out,
                // so a row edited directly in the database is caught too.
                $value = strtolower($value);
                if ($value !== '' && preg_match('/^#[0-9a-f]{6}$/', $value) !== 1) {
                    $errors[] = "$label must be a six-digit hex colour such as #0a63a8.";
                    return null;
                }
                // Empty is a legitimate answer: it means "use the default".
                return $value;

            default:
                $max = (int)($rules['max'] ?? 255);
                if (mb_strlen($value) > $max) {
                    $errors[] = "$label must be {$max} characters or fewer.";
                    return null;
                }
                if ($value === '' && !empty($rules['required'])) {
                    $errors[] = "$label is required.";
                    return null;
                }
                return $value;
        }
    }
}
