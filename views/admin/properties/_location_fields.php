<?php
/**
 * Shared location block — place name, full address, map coordinates and the
 * "use my location" control that reads them from the device.
 *
 * Expects:  $fd   form data array (may be empty)
 * Optional: $uid  id prefix, so a page carrying two property forms keeps
 *                 each label wired to its own input.
 */
$uid  = $uid ?? 'prop';
$errs = $formErrors ?? [];

/**
 * DECIMAL(10,8) comes back as "9.56000000" — show the value the user typed,
 * not the storage padding. A rejected non-numeric entry is echoed back as-is
 * so it can be corrected rather than silently vanishing.
 */
$geoCoord = static function ($v): string {
    $v = trim((string) ($v ?? ''));
    if ($v === '' || !is_numeric($v)) return sanitize($v);
    return rtrim(rtrim(number_format((float) $v, 8, '.', ''), '0'), '.');
};
?>
<div class="geo" data-geo>
    <div class="geo__head">
        <div>
            <h3 class="geo__title"><i class="bi bi-geo-alt-fill"></i> Location</h3>
            <p class="geo__hint">
                Name the area, or let this device read its coordinates. You can also paste a
                <code>latitude, longitude</code> pair (or a map link) into either coordinate box.
            </p>
        </div>
        <button type="button" class="btn btn--outline btn--sm" data-geo-locate>
            <i class="bi bi-crosshair"></i> Use my location
        </button>
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-location">Location <span class="req" aria-hidden="true">*</span></label>
        <input type="text" id="<?= $uid ?>-location" name="location"
               class="form-control<?= uiInvalidClass($errs, 'location') ?>"
               placeholder="e.g. Jarka, Borama" value="<?= sanitize($fd['location'] ?? '') ?>"
               required data-geo-place<?= uiFieldAria($errs, 'location') ?>>
        <?= uiFieldError($errs, 'location') ?>
    </div>

    <div class="form-group">
        <label class="form-label" for="<?= $uid ?>-address">Full Address</label>
        <input type="text" id="<?= $uid ?>-address" name="address" class="form-control"
               placeholder="Street, district, nearest landmark" value="<?= sanitize($fd['address'] ?? '') ?>"
               data-geo-address>
    </div>

    <div class="geo__grid">
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-lat">Latitude</label>
            <input type="text" inputmode="decimal" id="<?= $uid ?>-lat" name="latitude"
                   class="form-control<?= uiInvalidClass($errs, 'latitude') ?>"
                   placeholder="-90 … 90" value="<?= $geoCoord($fd['latitude'] ?? '') ?>"
                   data-geo-lat<?= uiFieldAria($errs, 'latitude') ?>>
            <?= uiFieldError($errs, 'latitude') ?>
        </div>
        <div class="form-group">
            <label class="form-label" for="<?= $uid ?>-lng">Longitude</label>
            <input type="text" inputmode="decimal" id="<?= $uid ?>-lng" name="longitude"
                   class="form-control<?= uiInvalidClass($errs, 'longitude') ?>"
                   placeholder="-180 … 180" value="<?= $geoCoord($fd['longitude'] ?? '') ?>"
                   data-geo-lng<?= uiFieldAria($errs, 'longitude') ?>>
            <?= uiFieldError($errs, 'longitude') ?>
        </div>
        <a class="geo__map is-disabled" href="#" target="_blank" rel="noopener noreferrer" data-geo-map>
            <i class="bi bi-map"></i> View on map
        </a>
    </div>

    <p class="geo__status" data-geo-status role="status" aria-live="polite"></p>
</div>
