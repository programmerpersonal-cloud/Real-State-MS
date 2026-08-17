<?php
/**
 * Property location map.
 *
 * Renders the pin when a property has coordinates, and an honest empty
 * state when it does not — an unpinned listing gets no map rather than a
 * decorative one centred on the wrong town.
 *
 * The frame is an OpenStreetMap embed: no API key, no vendored library,
 * and lazy so it costs nothing until it scrolls into view (the public page
 * keeps it inside a tab, where it may never be opened at all).
 *
 * Expects:  $property
 * Optional: $mapVariant  'public' (default) | 'admin'
 *           $mapEditUrl  where the admin empty state sends the user
 */
$mapCoords  = propertyCoords($property);
$mapVariant = $mapVariant ?? 'public';
$mapTitle   = trim((string) ($property['title'] ?? 'this property'));
?>
<?php if ($mapCoords): ?>
    <?php $mapLinks = mapUrls($mapCoords['lat'], $mapCoords['lng']); ?>
    <div class="propmap">
        <div class="propmap__frame">
            <iframe src="<?= sanitize($mapLinks['embed']) ?>"
                    title="Map showing the location of <?= sanitize($mapTitle) ?>"
                    loading="lazy" referrerpolicy="no-referrer"
                    style="border:0" width="600" height="340"></iframe>
        </div>

        <div class="propmap__bar">
            <span class="propmap__coords">
                <i class="bi bi-pin-map-fill" aria-hidden="true"></i>
                <span class="sr-only">Coordinates: </span><?= sanitize($mapCoords['text']) ?>
            </span>
            <span class="propmap__actions">
                <a class="btn btn--outline btn--sm" href="<?= sanitize($mapLinks['directions']) ?>"
                   target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-signpost-2" aria-hidden="true"></i> Directions
                </a>
                <a class="btn btn--outline btn--sm" href="<?= sanitize($mapLinks['osm']) ?>"
                   target="_blank" rel="noopener noreferrer">
                    <i class="bi bi-arrows-fullscreen" aria-hidden="true"></i> Larger map
                </a>
            </span>
        </div>

        <p class="propmap__credit">
            Map data ©
            <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>
            contributors
        </p>
    </div>

<?php elseif ($mapVariant === 'admin'): ?>
    <div class="map-placeholder">
        <i class="bi bi-geo" aria-hidden="true"></i>
        <strong>No coordinates on file</strong>
        <span>
            Pin this property to put it on the map — open the edit form and use
            <em>Use my location</em>, or type the latitude and longitude in.
        </span>
        <?php if (!empty($mapEditUrl)): ?>
            <a href="<?= $mapEditUrl ?>" class="btn btn--outline btn--sm" style="margin-top:var(--space-2)">
                <i class="bi bi-geo-alt" aria-hidden="true"></i> Add coordinates
            </a>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="map-placeholder">
        <i class="bi bi-geo-alt" aria-hidden="true"></i>
        <strong>Exact position not published</strong>
        <span>
            This listing has not been pinned to the map yet. The agent can share
            the precise location and arrange a viewing.
        </span>
        <a href="#inquiry" class="btn btn--outline btn--sm" style="margin-top:var(--space-2)">
            Ask about the location
        </a>
    </div>
<?php endif; ?>
