<?php
/**
 * Shared field validation — one ruleset, used by the server and the browser.
 *
 * Before this file, each controller carried its own idea of what a name or a
 * phone number was, and the browser carried a third. The result was a form
 * that accepted "Ahmed 123" on one page, rejected it on another, and told the
 * user something different each time.
 *
 * Everything is declared once here. The server reads it directly; the browser
 * gets the same table as JSON (see views/components/scripts.php), so the two
 * cannot drift. The client half is a courtesy — it answers immediately and
 * saves a round trip — but it is never the authority. Every rule below is
 * enforced again in PHP, and a request that skips the browser entirely gets
 * exactly the same answer.
 *
 * Messages are in Somali, next to the field they belong to.
 */

/**
 * The messages. Placeholders in {braces} are filled by the formatter below and
 * by its twin in the browser.
 */
function validationMessages(): array
{
    return [
        'required'  => 'Fadlan buuxi goobtan.',
        'letters'   => 'Xarfo kaliya ayaa la oggol yahay.',
        'numbers'   => 'Tirooyin kaliya ayaa la oggol yahay.',
        'email'     => 'Fadlan geli cinwaan email sax ah.',
        'phone'     => 'Fadlan geli lambar taleefan sax ah.',
        'phoneLen'  => 'Lambarka {country} waa inuu ahaadaa {lengths} lambar.',
        'date'      => 'Fadlan geli taariikh sax ah.',
        'min'       => 'Ugu yaraan {min} xaraf.',
        'max'       => 'Ugu badnaan {max} xaraf.',
        'minValue'  => 'Qiimuhu waa inuu ka weynaadaa ama la mid noqdaa {min}.',
        'maxValue'  => 'Qiimuhu waa inuu ka yaraadaa ama la mid noqdaa {max}.',
        'positive'  => 'Fadlan geli tiro ka weyn eber.',
        'passwordMatch' => 'Furayaasha sirta ah isku mid ma aha.',
    ];
}

/** Fill {placeholders} in a message. */
function validationMessage(string $key, array $vars = []): string
{
    $msg = validationMessages()[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $msg = str_replace('{' . $k . '}', (string) $v, $msg);
    }
    return $msg;
}

/**
 * Field types and what each one accepts.
 *
 * `pattern` is a JavaScript-compatible regular expression, deliberately: it is
 * handed to the browser as-is and compiled here with delimiters added, so one
 * expression governs both sides. Anything PHP-specific would have to be
 * written twice and would eventually disagree.
 *
 * `filter` marks a type whose invalid characters are refused as they are
 * typed. Only used where the wrong character is unambiguous — a letter in a
 * price. Never on free text, where refusing a keystroke is guesswork.
 */
function validationTypes(): array
{
    return [
        // A person's or place's name. Letters — including the accented and
        // Arabic ranges this customer base actually writes in — plus the
        // spaces, apostrophes and hyphens that appear inside real names.
        'name' => [
            'pattern' => "^[\\p{L}][\\p{L} '\\-.]*$",
            'message' => 'letters',
            'filter'  => true,
            'inputmode' => 'text',
        ],
        // Free text: notes, descriptions, addresses. No character rule at all;
        // only whatever length and required rules the field declares. An
        // address legitimately contains digits and commas.
        'text' => [
            'pattern' => '',
            'message' => '',
            'inputmode' => 'text',
        ],
        // A whole number.
        'integer' => [
            'pattern' => '^\\d+$',
            'message' => 'numbers',
            'filter'  => true,
            'inputmode' => 'numeric',
        ],
        // Money and measurements: digits with an optional decimal part.
        'number' => [
            'pattern' => '^\\d+(\\.\\d{1,2})?$',
            'message' => 'numbers',
            'filter'  => true,
            'inputmode' => 'decimal',
        ],
        'email' => [
            'pattern' => "^[^@\\s]+@[^@\\s.]+\\.[^@\\s]{2,}$",
            'message' => 'email',
            'inputmode' => 'email',
        ],
        'phone' => [
            'pattern' => '',          // length is per country; see below
            'message' => 'phone',
            'filter'  => true,
            'inputmode' => 'tel',
        ],
        'date' => [
            'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
            'message' => 'date',
            'inputmode' => 'text',
        ],
    ];
}

/**
 * Dialling codes and how long a national number is in each.
 *
 * `lengths` is the number of digits after the country code, with the trunk
 * zero removed — which is the part that actually varies. A single hard-coded
 * length for every country is the usual mistake: a Somali mobile is 8 or 9
 * digits, a Kenyan one is 9, an Emirati one is 9, and a British one is 10.
 * Rejecting a valid foreign number is worse than accepting a doubtful one.
 *
 * Ordered with the countries this business actually works in first, because
 * this list is also the order of the selector.
 */
function phoneCountries(): array
{
    return [
        'SO' => ['name' => 'Soomaaliya',      'dial' => '252', 'lengths' => [7, 8, 9], 'example' => '61 234 5678'],
        'KE' => ['name' => 'Kenya',           'dial' => '254', 'lengths' => [9],       'example' => '712 345 678'],
        'ET' => ['name' => 'Itoobiya',        'dial' => '251', 'lengths' => [9],       'example' => '911 234 567'],
        'DJ' => ['name' => 'Jabuuti',         'dial' => '253', 'lengths' => [8],       'example' => '77 123 456'],
        'UG' => ['name' => 'Uganda',          'dial' => '256', 'lengths' => [9],       'example' => '712 345 678'],
        'TZ' => ['name' => 'Tansaaniya',      'dial' => '255', 'lengths' => [9],       'example' => '712 345 678'],
        'AE' => ['name' => 'Imaaraadka',      'dial' => '971', 'lengths' => [9],       'example' => '50 123 4567'],
        'SA' => ['name' => 'Sacuudiga',       'dial' => '966', 'lengths' => [9],       'example' => '50 123 4567'],
        'QA' => ['name' => 'Qatar',           'dial' => '974', 'lengths' => [8],       'example' => '3312 3456'],
        'TR' => ['name' => 'Turkiga',         'dial' => '90',  'lengths' => [10],      'example' => '532 123 4567'],
        'EG' => ['name' => 'Masar',           'dial' => '20',  'lengths' => [10],      'example' => '100 123 4567'],
        'GB' => ['name' => 'Boqortooyada',    'dial' => '44',  'lengths' => [10],      'example' => '7400 123456'],
        'US' => ['name' => 'Maraykanka',      'dial' => '1',   'lengths' => [10],      'example' => '202 555 0142'],
        'CA' => ['name' => 'Kanada',          'dial' => '1',   'lengths' => [10],      'example' => '416 555 0142'],
        'SE' => ['name' => 'Iswiidhan',       'dial' => '46',  'lengths' => [7, 8, 9], 'example' => '70 123 45 67'],
        'NO' => ['name' => 'Norwiij',         'dial' => '47',  'lengths' => [8],       'example' => '406 12 345'],
        'NL' => ['name' => 'Holand',          'dial' => '31',  'lengths' => [9],       'example' => '6 12345678'],
        'DE' => ['name' => 'Jarmalka',        'dial' => '49',  'lengths' => [10, 11],  'example' => '151 23456789'],
    ];
}

/** The country a phone field starts on when nothing says otherwise. */
const PHONE_DEFAULT_COUNTRY = 'SO';

/**
 * Split a stored phone number back into a country and a national part.
 *
 * Numbers already in the database were typed freely, long before this existed:
 * "0612345678", "+252 61 234 5678", "252612345678". All three have to come
 * back into the form as something a person recognises, so the longest matching
 * dialling code wins and anything left over is treated as national.
 *
 * @return array{country:string, national:string}
 */
function phoneParse(?string $stored): array
{
    $raw = trim((string) $stored);
    if ($raw === '') {
        return ['country' => PHONE_DEFAULT_COUNTRY, 'national' => ''];
    }

    $digits = preg_replace('/\D+/', '', $raw);
    $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
    if (str_starts_with($raw, '00')) {
        $digits = substr($digits, 2);
    }

    if ($digits === '') {
        return ['country' => PHONE_DEFAULT_COUNTRY, 'national' => ''];
    }

    // Longest dialling code first, so +1 does not swallow a +252.
    $codes = [];
    foreach (phoneCountries() as $iso => $c) {
        $codes[$iso] = $c['dial'];
    }
    uasort($codes, static fn($a, $b) => strlen($b) <=> strlen($a));

    foreach ($codes as $iso => $dial) {
        if (!str_starts_with($digits, $dial)) {
            continue;
        }
        $national = substr($digits, strlen($dial));
        // A bare local number that happens to begin with the country's own
        // digits is not an international one: "252..." typed without a plus
        // is only a country code if what follows is a plausible length.
        if (!$hasPlus && !in_array(strlen($national), phoneCountries()[$iso]['lengths'], true)) {
            continue;
        }
        return ['country' => $iso, 'national' => ltrim($national, '0')];
    }

    return ['country' => PHONE_DEFAULT_COUNTRY, 'national' => ltrim($digits, '0')];
}

/**
 * Compose the value that gets stored: +<dial><national>, digits only.
 *
 * One format in the column from now on, which is what makes a number
 * searchable and dialable. Nothing is migrated — existing rows keep whatever
 * they hold until someone edits them.
 */
function phoneCompose(string $country, string $national): string
{
    $countries = phoneCountries();
    $dial = $countries[$country]['dial'] ?? $countries[PHONE_DEFAULT_COUNTRY]['dial'];
    $digits = ltrim(preg_replace('/\D+/', '', $national), '0');

    return $digits === '' ? '' : '+' . $dial . $digits;
}

/**
 * Check one value against one type.
 *
 * @return string|null  the Somali message, or null when the value is fine
 */
function validateValue(string $type, $value, array $opts = []): ?string
{
    $value = is_string($value) ? trim($value) : $value;
    $required = !empty($opts['required']);

    if ($value === '' || $value === null) {
        return $required ? validationMessage('required') : null;
    }

    if ($type === 'phone') {
        return validatePhoneValue((string) $value, (string) ($opts['country'] ?? PHONE_DEFAULT_COUNTRY));
    }

    $types = validationTypes();
    $rule = $types[$type] ?? $types['text'];

    if (!empty($rule['pattern'])
        && !preg_match('/' . $rule['pattern'] . '/u', (string) $value)) {
        return validationMessage($rule['message']);
    }

    $len = mb_strlen((string) $value);
    if (isset($opts['min']) && $len < (int) $opts['min']) {
        return validationMessage('min', ['min' => (int) $opts['min']]);
    }
    if (isset($opts['max']) && $len > (int) $opts['max']) {
        return validationMessage('max', ['max' => (int) $opts['max']]);
    }

    if (in_array($type, ['number', 'integer'], true)) {
        $n = (float) $value;
        if (!empty($opts['positive']) && $n <= 0) {
            return validationMessage('positive');
        }
        if (isset($opts['minValue']) && $n < (float) $opts['minValue']) {
            return validationMessage('minValue', ['min' => $opts['minValue']]);
        }
        if (isset($opts['maxValue']) && $n > (float) $opts['maxValue']) {
            return validationMessage('maxValue', ['max' => $opts['maxValue']]);
        }
    }

    return null;
}

/** A phone number, judged against its own country's rules rather than one global length. */
function validatePhoneValue(string $value, string $country): ?string
{
    $countries = phoneCountries();
    $c = $countries[$country] ?? $countries[PHONE_DEFAULT_COUNTRY];

    // The value may arrive as a national part or as a composed +dial number;
    // both reduce to the same national digits.
    $parsed = phoneParse($value);
    $digits = $parsed['national'] !== '' ? $parsed['national'] : ltrim(preg_replace('/\D+/', '', $value), '0');

    if ($digits === '') {
        return validationMessage('phone');
    }
    if (!ctype_digit($digits)) {
        return validationMessage('numbers');
    }
    if (!in_array(strlen($digits), $c['lengths'], true)) {
        return validationMessage('phoneLen', [
            'country'  => $c['name'],
            'lengths'  => implode(' ama ', $c['lengths']),
        ]);
    }
    return null;
}

/**
 * What each field name is, application-wide.
 *
 * Keyed by the request key, so a module does not get to decide that its
 * `full_name` means something different from everyone else's. A field absent
 * from this table is free text and is only checked for the rules its own
 * controller adds.
 */
function fieldTypes(): array
{
    return [
        // People and places
        'full_name'         => ['type' => 'name', 'max' => 100],
        'manager_name'      => ['type' => 'name', 'max' => 100],
        'guarantor_name'    => ['type' => 'name', 'max' => 100],
        'author_name'       => ['type' => 'name', 'max' => 100],
        'emergency_contact' => ['type' => 'name', 'max' => 100],
        'occupation'        => ['type' => 'name', 'max' => 80],
        'employment_status' => ['type' => 'name', 'max' => 80],

        // Contact
        'email'             => ['type' => 'email', 'max' => 150],
        'phone'             => ['type' => 'phone'],
        'emergency_phone'   => ['type' => 'phone'],
        'guarantor_contact' => ['type' => 'phone'],

        // Money
        'price'             => ['type' => 'number', 'positive' => true],
        'amount'            => ['type' => 'number', 'positive' => true],
        'rent_amount'       => ['type' => 'number', 'positive' => true],
        'sale_amount'       => ['type' => 'number', 'positive' => true],
        'deposit_amount'    => ['type' => 'number'],
        'tax_amount'        => ['type' => 'number'],
        'commission_amount' => ['type' => 'number'],
        'cost_estimate'     => ['type' => 'number'],
        'actual_cost'       => ['type' => 'number'],
        'commission_rate'   => ['type' => 'number', 'minValue' => 0, 'maxValue' => 100],
        'late_fee_rate'     => ['type' => 'number', 'minValue' => 0, 'maxValue' => 100],

        // Counts and measurements
        'num_rooms'         => ['type' => 'integer', 'maxValue' => 999],
        'num_bathrooms'     => ['type' => 'integer', 'maxValue' => 999],
        'num_floors'        => ['type' => 'integer', 'maxValue' => 999],
        'size_sqm'          => ['type' => 'number'],
        'sort_order'        => ['type' => 'integer'],
        'rating'            => ['type' => 'integer', 'minValue' => 1, 'maxValue' => 5],

        // Dates
        'start_date'        => ['type' => 'date'],
        'end_date'          => ['type' => 'date'],
        'due_date'          => ['type' => 'date'],
        'payment_date'      => ['type' => 'date'],
        'sale_date'         => ['type' => 'date'],
        'expiry_date'       => ['type' => 'date'],
        'document_date'     => ['type' => 'date'],
        'move_in_date'      => ['type' => 'date'],
        'move_out_date'     => ['type' => 'date'],
        'reservation_date'  => ['type' => 'date'],
    ];
}

/**
 * Fold every `<field>` + `<field>_country` pair into one stored number.
 *
 * Called where a controller collects its input, before validation and before
 * anything is written, so the rest of the request — the duplicate-phone check
 * included — sees the same string that will end up in the column. Fields the
 * form did not send are left untouched; a partial payload is not made to
 * answer for a phone it never asked about.
 */
function normalisePhoneFields(array &$data): void
{
    $countries = phoneCountries();
    foreach (fieldTypes() as $key => $spec) {
        if (($spec['type'] ?? '') !== 'phone' || !isset($data[$key])) {
            continue;
        }
        $raw = trim((string) $data[$key]);
        if ($raw === '') {
            continue;
        }
        // The selector is a UI companion, not a column, so it is usually only
        // in the request rather than in the array the controller assembled.
        $country = (string) ($data[$key . '_country'] ?? $_POST[$key . '_country'] ?? PHONE_DEFAULT_COUNTRY);
        if (!isset($countries[$country])) {
            $country = PHONE_DEFAULT_COUNTRY;
        }
        // Only compose a number that is actually usable. A half-typed one is
        // left exactly as it was typed, so the error names what the person
        // still sees in the box rather than something rewritten behind them.
        if (validatePhoneValue($raw, $country) === null) {
            $data[$key] = phoneCompose($country, $raw);
        }
    }
}

/**
 * Run the shared rules over a request.
 *
 * Called at the top of a controller's own validator, so the shared answer
 * lands before any module-specific rule and a field never collects two
 * different complaints. Only keys actually present are examined: a partial
 * form (the quick-add popup, an inline edit) is not made to answer for fields
 * it never showed.
 *
 * `$required` names the keys this particular form insists on — that is a
 * module decision, not a global one, because the same column is optional in
 * one place and mandatory in another.
 */
function validateSharedFields(array $data, array &$errors, array $required = []): void
{
    $spec = fieldTypes();

    foreach ($data as $key => $value) {
        if (!isset($spec[$key]) || is_array($value)) {
            continue;
        }
        $opts = $spec[$key];
        $type = $opts['type'];
        unset($opts['type']);
        $opts['required'] = in_array($key, $required, true);

        if ($type === 'phone') {
            // The selector rides alongside the number: customers[phone] pairs
            // with customers[phone_country].
            $opts['country'] = (string) ($data[$key . '_country'] ?? $_POST[$key . '_country'] ?? PHONE_DEFAULT_COUNTRY);
        }

        $message = validateValue($type, $value, $opts);
        if ($message !== null && !isset($_SESSION['form_errors'][$key])) {
            addFieldError($errors, $key, $message);
        }
    }

    // A required field missing from the payload entirely still has to be caught.
    foreach ($required as $key) {
        if (!array_key_exists($key, $data) && !isset($_SESSION['form_errors'][$key])) {
            addFieldError($errors, $key, validationMessage('required'));
        }
    }
}

/**
 * The whole ruleset as the browser needs it.
 *
 * Emitted into the page rather than duplicated in a .js file, so there is
 * exactly one definition of what a valid phone number is and it lives in PHP.
 */
function validationClientRules(): array
{
    return [
        'messages'  => validationMessages(),
        'types'     => validationTypes(),
        'fields'    => fieldTypes(),
        'countries' => phoneCountries(),
        'defaultCountry' => PHONE_DEFAULT_COUNTRY,
    ];
}
