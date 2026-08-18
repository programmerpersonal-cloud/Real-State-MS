<?php
/**
 * A phone number: country selector plus the local number.
 *
 * One field for every phone in the application. Before this, a phone was a
 * plain text box and every module guessed at what a valid one looked like —
 * usually by counting digits against a single hard-coded length, which is
 * wrong for every country but one.
 *
 * The selector is a real <select> carrying its own name, so the pair round-
 * trips through the existing PRG reject path with no special handling: the
 * server reads `phone` and `phone_country` together and judges the number
 * against that country's own rules.
 *
 * What gets stored is composed on the server — +<dial><digits> — so the
 * column ends up with one format regardless of how it was typed. Nothing is
 * migrated: rows written before this keep what they hold until edited, and
 * phoneParse() reads all the old shapes back into the form.
 *
 * Expects $phoneField:
 *   name      request key for the number itself, e.g. 'phone'
 *   id        DOM id for the number input
 *   label     visible label
 *   value     stored value, in any historic format   (optional)
 *   required  (optional)
 *   hint      help text under the field              (optional)
 *
 * One array rather than loose variables on purpose: the field partials that
 * host this use $label and $value as foreach variables, and a shared include
 * that quietly reassigns them would break the select two lines further down.
 */
$pf         = $phoneField ?? [];
$phName     = $pf['name'] ?? 'phone';
$phId       = $pf['id'] ?? $phName;
$phLabel    = $pf['label'] ?? 'Phone';
$phRequired = !empty($pf['required']);
$phHint     = $pf['hint'] ?? '';
$phErrs     = $errs ?? ($formErrors ?? []);

/* After a reject the country comes back in the payload; otherwise it is read
   out of whatever is stored. */
$parsed  = phoneParse((string) ($pf['value'] ?? ''));
$country = (string) ($phErrs ? ($_SESSION['form_data'][$phName . '_country'] ?? $parsed['country']) : $parsed['country']);
$countries = phoneCountries();
if (!isset($countries[$country])) {
    $country = PHONE_DEFAULT_COUNTRY;
}
$national = $parsed['national'];

$hintId = $phId . '-hint';
?>
<div class="form-group">
    <label class="form-label" for="<?= sanitize($phId) ?>">
        <?= sanitize($phLabel) ?><?php if ($phRequired): ?> <span class="req" aria-hidden="true">*</span><?php endif ?>
    </label>

    <div class="phone-field<?= uiInvalidClass($phErrs, $phName) ? ' phone-field--error' : '' ?>">
        <select class="form-control phone-field__country" name="<?= sanitize($phName) ?>_country"
                aria-label="Waddanka lambarka">
            <?php foreach ($countries as $iso => $c): ?>
                <option value="<?= $iso ?>"
                        data-dial="<?= sanitize($c['dial']) ?>"
                        data-lengths="<?= sanitize(implode(',', $c['lengths'])) ?>"
                        <?= $iso === $country ? 'selected' : '' ?>>
                    <?= sanitize($c['name']) ?> (+<?= sanitize($c['dial']) ?>)
                </option>
            <?php endforeach ?>
        </select>

        <input type="tel" inputmode="tel" autocomplete="tel"
               class="form-control phone-field__number<?= uiInvalidClass($phErrs, $phName) ?>"
               id="<?= sanitize($phId) ?>" name="<?= sanitize($phName) ?>"
               value="<?= sanitize($national) ?>"
               placeholder="<?= sanitize($countries[$country]['example']) ?>"
               data-validate-type="phone"
               <?= $phRequired ? 'required' : '' ?>
               <?= uiFieldAria($phErrs, $phName, $phHint !== '' ? $hintId : '') ?>>
    </div>

    <?= uiFieldError($phErrs, $phName) ?>
    <?php if ($phHint !== ''): ?>
        <div class="form-hint" id="<?= $hintId ?>"><?= sanitize($phHint) ?></div>
    <?php endif ?>
</div>
<?php
/* Cleared so a second phone field on the same form cannot inherit the first
   one's settings by forgetting to set a key. */
unset($phoneField, $pf, $phName, $phId, $phLabel, $phRequired, $phHint, $parsed, $country, $national);
